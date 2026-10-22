<?php

namespace Tests\Auth6B4;

use App\Jobs\PollSecurityNotices;
use App\Services\Auth\Notices\DeliveryResult;
use App\Services\Auth\Notices\FakeNoticeDelivery;
use App\Services\Auth\Notices\IndependentNoticeDelivery;
use App\Services\Auth\Notices\NoticeDelivery;
use App\Services\Auth\Notices\NoticeDispatcher;
use App\Services\Auth\Notices\NoticeMetrics;
use App\Services\Auth\Notices\NoticeType;
use App\Services\Auth\Notices\NoticeUnavailable;
use App\Services\Auth\Notices\NoticeWriter;
use App\Services\Auth\Notices\RecipientCodec;
use App\Services\Auth\SecretValue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    private int $subject;

    private NoticeDispatcher $dispatcher;

    private FakeNoticeDelivery $fake;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        $this->subject = DB::table('users')->insertGetId(['name' => 'B4 synthetic', 'role' => 'client', 'email' => Str::uuid().'@example.invalid']);
        $this->dispatcher = app(NoticeDispatcher::class);
        $this->fake = new FakeNoticeDelivery;
        app()->instance(NoticeDelivery::class, $this->fake);
        // Попередні B3 регресії мають durable synthetic backlog; polling без destructive cleanup.
        while (($claims = $this->dispatcher->claim(50)) !== []) {
            foreach ($claims as $claim) {
                $this->dispatcher->deliver($claim);
            }
        }
        $this->fake->messages = [];
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function write(?string $request = null, array $recipients = ['current' => 'CANARY@example.invalid']): object
    {
        $request = DB::transaction(fn () => app(NoticeWriter::class)->write($this->subject, NoticeType::MfaChanged, $recipients, request: $request));

        return DB::table('auth_security_notice_outbox')->where('request_id', $request)->first();
    }

    private function reject(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected notice rejection');
        } catch (NoticeUnavailable|QueryException|\RuntimeException $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }
    }

    private function shortLease(string $id): object
    {
        DB::update("UPDATE auth_security_notice_outbox SET state='leased',attempts=attempts+1,lease_token=gen_random_uuid(),lease_until=clock_timestamp()+interval '100 milliseconds' WHERE id=?", [$id]);
        $row = DB::table('auth_security_notice_outbox')->where('id', $id)->first();
        usleep(150000);

        return $row;
    }

    public function test_commit_and_crash_before_commit_are_atomic_without_queue(): void
    {
        $before = DB::table('auth_security_notice_outbox')->count();
        DB::beginTransaction();
        DB::table('users')->where('id', $this->subject)->increment('auth_version');
        $this->write();
        DB::rollBack();
        $this->assertSame($before, DB::table('auth_security_notice_outbox')->count());
        $this->assertSame(0, DB::table('users')->where('id', $this->subject)->value('auth_version'));
        DB::transaction(function () {
            DB::table('users')->where('id', $this->subject)->increment('auth_version');
            $this->write();
        });
        $row = DB::table('auth_security_notice_outbox')->where('subject_user_id', $this->subject)->first();
        $this->assertSame('pending', $row->state);
        $this->assertSame(1, DB::table('users')->where('id', $this->subject)->value('auth_version'));
        $this->assertSame(1, DB::table('auth_audit_events')->where('request_id', $row->request_id)->where('event_type', 'notice.queued')->count());
    }

    public function test_notice_or_audit_failure_rolls_back_mutation_without_raw_exception(): void
    {
        foreach (['notice', 'audit'] as $fault) {
            DB::beginTransaction();
            if ($fault === 'audit') {
                DB::unprepared("CREATE FUNCTION b4_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'CANARY secret'; END $$;
                    CREATE TRIGGER b4_fault BEFORE INSERT ON auth_audit_events FOR EACH ROW EXECUTE FUNCTION b4_fault();");
            }
            try {
                DB::transaction(function () use ($fault) {
                    DB::table('users')->where('id', $this->subject)->increment('auth_version');
                    app(NoticeWriter::class)->write($this->subject, NoticeType::MfaChanged, [$fault === 'notice' ? 'wrong' : 'current' => 'CANARY@example.invalid']);
                });
                $this->fail('Notice fault ignored');
            } catch (NoticeUnavailable $e) {
                $this->assertNull($e->getPrevious());
                $this->assertStringNotContainsString('CANARY', (string) $e);
            }
            $this->assertSame(0, DB::table('users')->where('id', $this->subject)->value('auth_version'));
            DB::rollBack();
        }
    }

    public function test_duplicate_signal_idempotency_and_mismatches_are_not_silently_ignored(): void
    {
        $row = $this->write();
        $this->write($row->request_id);
        $this->assertSame(1, DB::table('auth_security_notice_outbox')->where('request_id', $row->request_id)->count());
        $this->reject(fn () => $this->write($row->request_id, ['current' => 'changed@example.invalid']));
        $this->reject(fn () => $this->write($row->request_id, ['independent' => 'synthetic-handle']));
        $this->reject(fn () => DB::transaction(fn () => app(NoticeWriter::class)->write($this->subject, NoticeType::EmailChanged, ['current' => 'CANARY@example.invalid'], request: $row->request_id)));
        $this->reject(fn () => $this->write(null, ['old' => 'old@example.invalid']));
        try {
            app(NoticeWriter::class)->write($this->subject, NoticeType::MfaChanged, ['current' => 'x@example.invalid']);
            $this->fail('Missing transaction accepted');
        } catch (NoticeUnavailable) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_distinct_recipient_rows_and_immutable_old_snapshot(): void
    {
        $request = DB::transaction(fn () => app(NoticeWriter::class)->write($this->subject, NoticeType::EmailChanged, ['old' => 'OLD@example.invalid', 'current' => 'new@example.invalid']));
        DB::table('users')->where('id', $this->subject)->update(['email' => 'later@example.invalid']);
        $rows = DB::table('auth_security_notice_outbox')->where('request_id', $request)->get();
        $this->assertCount(2, $rows);
        $old = $rows->firstWhere('recipient_slot', 'old');
        $this->assertSame('OLD@example.invalid', app(RecipientCodec::class)->decrypt($old->recipient_ciphertext, $old->recipient_key_version));
        $this->reject(fn () => DB::table('auth_security_notice_outbox')->where('id', $old->id)->update(['recipient_ciphertext' => 'replacement']));
        $this->reject(fn () => DB::table('auth_security_notice_outbox')->where('id', $old->id)->update(['subject_user_id' => $this->subject + 1]));
    }

    public function test_send_outside_transaction_terminal_scrub_and_duplicate_signal(): void
    {
        $row = $this->write();
        // Synthetic backlog попередніх тестів теж дозволений; знайти цей claim.
        $claims = $this->dispatcher->claim(50);
        $claim = collect($claims)->firstWhere('id', $row->id);
        $this->assertNotNull($claim);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue($this->dispatcher->deliver($claim));
        $this->assertFalse($this->dispatcher->deliver($claim));
        $after = DB::table('auth_security_notice_outbox')->where('id', $row->id)->first();
        $this->assertSame('sent', $after->state);
        $this->assertNull($after->recipient_ciphertext);
        $this->assertNull($after->recipient_key_version);
        $this->assertCount(1, $this->fake->messages);
        $this->reject(fn () => DB::table('auth_security_notice_outbox')->where('id', $row->id)->update(['state' => 'pending']));
        $this->reject(fn () => DB::table('auth_security_notice_outbox')->where('id', $row->id)->update(['attempts' => 0]));
        $this->reject(fn () => DB::table('auth_security_notice_outbox')->where('id', $row->id)->delete());
    }

    public function test_crash_after_send_reclaims_with_new_token_and_stale_ack_is_rejected(): void
    {
        $row = $this->write();
        $old = $this->shortLease($row->id);
        // SMTP acceptance без ack моделюється fake; intent залишається leased.
        $this->fake->send(new SecretValue('CANARY@example.invalid'), NoticeType::MfaChanged, $row->created_at, $row->id, 10);
        $new = collect($this->dispatcher->claim(50))->firstWhere('id', $row->id);
        $this->assertNotSame($old->lease_token, $new->lease_token);
        $this->assertFalse($this->dispatcher->acknowledge($old->id, $old->lease_token, 'sent'));
        $this->assertTrue($this->dispatcher->deliver($new));
        $this->assertCount(2, $this->fake->messages);
        $this->assertSame(2, DB::table('auth_security_notice_outbox')->where('id', $row->id)->value('attempts'));
    }

    public function test_transient_timeout_permanent_and_key_errors_are_bounded(): void
    {
        foreach ([DeliveryResult::Transient, DeliveryResult::Timeout, DeliveryResult::Permanent] as $result) {
            $row = $this->write();
            $claim = collect($this->dispatcher->claim(50))->firstWhere('id', $row->id);
            $this->fake->result = $result;
            $this->dispatcher->deliver($claim);
            $after = DB::table('auth_security_notice_outbox')->where('id', $row->id)->first();
            $this->assertSame($result === DeliveryResult::Permanent ? 'dead' : 'pending', $after->state);
            if ($after->state === 'pending') {
                $delay = DB::selectOne('SELECT extract(epoch FROM ?::timestamptz-clock_timestamp()) AS n', [$after->next_attempt_at])->n;
                $this->assertGreaterThan(28, (float) $delay);
                $this->assertLessThan(34, (float) $delay);
            }
        }
        $row = $this->write();
        $claim = collect($this->dispatcher->claim(50))->firstWhere('id', $row->id);
        config(['auth_notices.keys.app_v1' => null]);
        $this->dispatcher->deliver($claim);
        $after = DB::table('auth_security_notice_outbox')->where('id', $row->id)->first();
        $this->assertSame('dead', $after->state);
        $this->assertSame('key', $after->last_error_code);
        $this->assertNull($after->recipient_ciphertext);
    }

    public function test_expired_before_claim_and_during_send_never_reopens(): void
    {
        $id = (string) Str::uuid();
        DB::table('auth_security_notice_outbox')->insert(['id' => $id, 'request_id' => Str::uuid(), 'subject_user_id' => $this->subject,
            'notice_type' => 'mfa_changed', 'contract_version' => 1, 'recipient_slot' => 'current', 'recipient_ciphertext' => 'synthetic', 'recipient_key_version' => 'app_v1',
            'created_at' => now()->subDays(2), 'expires_at' => now()->subDay(), 'next_attempt_at' => now()->subDays(2)]);
        $this->dispatcher->claim(50);
        $this->assertSame('expired', DB::table('auth_security_notice_outbox')->where('id', $id)->value('state'));
        $this->assertNull(DB::table('auth_security_notice_outbox')->where('id', $id)->value('recipient_ciphertext'));
        // Short synthetic TTL; wall clock закінчується між send та ack.
        $id = (string) Str::uuid();
        DB::insert("INSERT INTO auth_security_notice_outbox(id,request_id,subject_user_id,notice_type,contract_version,recipient_slot,recipient_ciphertext,recipient_key_version,created_at,expires_at,next_attempt_at)
            VALUES(?::uuid,gen_random_uuid(),?,'mfa_changed',1,'current',?,'app_v1',clock_timestamp(),clock_timestamp()+interval '1 second',clock_timestamp())",
            [$id, $this->subject, app(RecipientCodec::class)->encrypt('CANARY@example.invalid', 'app_v1')]);
        $claim = collect($this->dispatcher->claim(50))->firstWhere('id', $id);
        $adapter = new class implements NoticeDelivery
        {
            public bool $accepted = false;

            public function send(SecretValue $recipient, NoticeType $type, string $occurredAt, string $messageId, int $timeoutSeconds): DeliveryResult
            {
                usleep(1100000);
                $this->accepted = true;

                return DeliveryResult::Sent;
            }
        };
        app()->instance(NoticeDelivery::class, $adapter);
        $this->dispatcher->deliver($claim);
        $this->assertTrue($adapter->accepted);
        $this->assertSame('expired', DB::table('auth_security_notice_outbox')->where('id', $id)->value('state'));
    }

    public function test_twenty_claims_end_dead_and_terminal_pii_is_scrubbed(): void
    {
        $row = $this->write();
        for ($i = 0; $i < 20; $i++) {
            if ($i === 0) {
                $this->shortLease($row->id);
            } else {
                DB::update("UPDATE auth_security_notice_outbox SET attempts=attempts+1,lease_token=gen_random_uuid(),lease_until=clock_timestamp()+interval '100 milliseconds' WHERE id=?", [$row->id]);
                usleep(150000);
            }
        }
        $this->dispatcher->claim(50);
        $after = DB::table('auth_security_notice_outbox')->where('id', $row->id)->first();
        $this->assertSame('dead', $after->state);
        $this->assertSame(20, $after->attempts);
        $this->assertNull($after->recipient_ciphertext);
        $this->assertTrue(app(NoticeMetrics::class)->snapshot()['alarm']);
    }

    public function test_adapter_exception_job_metrics_and_audit_do_not_expose_pii(): void
    {
        Log::spy();
        $row = $this->write();
        $claim = collect($this->dispatcher->claim(50))->firstWhere('id', $row->id);
        $adapter = new class implements NoticeDelivery
        {
            public bool $outsideTransaction = false;

            public int $timeout = 0;

            public function send(SecretValue $recipient, NoticeType $type, string $occurredAt, string $messageId, int $timeoutSeconds): DeliveryResult
            {
                $this->outsideTransaction = DB::transactionLevel() === 0;
                $this->timeout = $timeoutSeconds;
                throw new \RuntimeException('CANARY@example.invalid password OTP secret');
            }
        };
        app()->instance(NoticeDelivery::class, $adapter);
        $this->dispatcher->deliver($claim);
        $this->assertTrue($adapter->outsideTransaction);
        $this->assertSame(10, $adapter->timeout);
        $data = json_encode(DB::table('auth_audit_events')->where('request_id', $row->request_id)->get()).json_encode(app(NoticeMetrics::class)->snapshot()).serialize(new PollSecurityNotices).json_encode(DB::table('failed_jobs')->get());
        $this->assertStringNotContainsString('CANARY', $data);
        $this->assertStringNotContainsString('password', $data);
        Log::shouldNotHaveReceived('error');
        $this->assertSame('pending', DB::table('auth_security_notice_outbox')->where('id', $row->id)->value('state'));
    }

    public function test_retry_schedule_all_stages_and_current_lease_cannot_be_stolen(): void
    {
        foreach ([1 => 30, 2 => 120, 3 => 600, 4 => 1800, 5 => 3600] as $attempt => $seconds) {
            $row = $this->write();
            for ($i = 1; $i < $attempt; $i++) {
                $this->shortLease($row->id);
            }
            $claim = collect($this->dispatcher->claim(50))->firstWhere('id', $row->id);
            $this->assertSame($attempt, $claim->attempts);
            $this->reject(fn () => DB::update("UPDATE auth_security_notice_outbox SET attempts=attempts+1,lease_token=gen_random_uuid(),lease_until=clock_timestamp()+interval '90 seconds' WHERE id=?", [$row->id]));
            $this->dispatcher->acknowledge($claim->id, $claim->lease_token, 'timeout');
            $delay = (float) DB::selectOne('SELECT extract(epoch FROM next_attempt_at-clock_timestamp()) n FROM auth_security_notice_outbox WHERE id=?', [$row->id])->n;
            $this->assertGreaterThan($seconds - 2, $delay);
            $this->assertLessThan($seconds * 1.1 + 1, $delay);
        }
    }

    public function test_ack_audit_fault_preserves_lease_and_allows_safe_retry(): void
    {
        $row = $this->write();
        $claim = collect($this->dispatcher->claim(50))->firstWhere('id', $row->id);
        DB::unprepared("CREATE FUNCTION b4_ack_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_type='notice.sent' THEN RAISE EXCEPTION 'sanitized fault'; END IF; RETURN NEW; END $$;
            CREATE TRIGGER b4_ack_fault BEFORE INSERT ON auth_audit_events FOR EACH ROW EXECUTE FUNCTION b4_ack_fault();");
        try {
            try {
                $this->dispatcher->deliver($claim);
                $this->fail('Ack audit fault ignored');
            } catch (NoticeUnavailable) {
                $this->assertSame('leased', DB::table('auth_security_notice_outbox')->where('id', $row->id)->value('state'));
                $this->assertNotNull(DB::table('auth_security_notice_outbox')->where('id', $row->id)->value('recipient_ciphertext'));
            }
        } finally {
            DB::unprepared('DROP TRIGGER b4_ack_fault ON auth_audit_events; DROP FUNCTION b4_ack_fault();');
        }
        $this->assertTrue($this->dispatcher->deliver($claim));
        $this->assertCount(2, $this->fake->messages);
    }

    public function test_sql_contract_and_batch_bounds_reject_invalid_direct_writes(): void
    {
        $row = $this->write();
        foreach ([
            ['contract_version' => 2], ['attempts' => -1], ['attempts' => 21],
            ['recipient_slot' => 'unknown'], ['notice_type' => 'arbitrary_json'],
            ['state' => 'sent'], ['state' => 'leased'], ['recipient_key_version' => null],
        ] as $changes) {
            $this->reject(fn () => DB::table('auth_security_notice_outbox')->where('id', $row->id)->update($changes));
        }
        $this->reject(fn () => $this->dispatcher->claim(0));
        $this->reject(fn () => $this->dispatcher->claim(51));
    }

    public function test_independent_channel_has_no_email_fallback(): void
    {
        $row = $this->write(null, ['independent' => 'synthetic-destination']);
        $claim = collect($this->dispatcher->claim(50))->firstWhere('id', $row->id);
        $this->assertFalse(app()->bound(IndependentNoticeDelivery::class));
        $this->dispatcher->deliver($claim);
        $this->assertSame('dead', DB::table('auth_security_notice_outbox')->where('id', $row->id)->value('state'));
        $this->assertCount(0, $this->fake->messages);
    }
}

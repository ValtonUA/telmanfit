<?php

namespace Tests\Auth6B5;

use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Auth\Retention\RetentionService;
use App\Support\Session\ContextDatabaseSessionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HoldScrubTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function user(): int
    {
        return DB::table('users')->insertGetId(['name' => 'Synthetic hold scrub', 'role' => 'client']);
    }

    private function hold(int $user, string $scope, int $at): void
    {
        $creator = (string) Str::uuid();
        DB::table('auth_recovery_principals')->insert(['id' => $creator, 'authority' => 'reviewer',
            'identity_record_digest' => str_repeat('c', 64), 'created_at' => DB::raw('clock_timestamp()')]);
        $selector = ['subject_user_id' => $user];
        if ($scope === 'time') {
            $selector = ['audit_from' => date('c', $at - 60), 'audit_to' => date('c', $at + 60)];
        } elseif ($scope === 'case') {
            $target = (string) Str::uuid();
            DB::table('auth_recovery_principals')->insert(['id' => $target, 'linked_user_id' => $user, 'authority' => 'operator',
                'identity_record_digest' => str_repeat('d', 64), 'created_at' => DB::raw('clock_timestamp()')]);
            $case = (string) Str::uuid();
            DB::table('auth_recovery_cases')->insert(['id' => $case, 'target_user_id' => $user, 'target_principal_id' => $target,
                'initiator_principal_id' => $creator, 'target_role' => 'client', 'action' => 'replace_mfa', 'expected_auth_version' => 0,
                'registry_version' => 1, 'policy_version' => 1, 'deployment_id' => 'synthetic',
                'restore_epoch' => SyntheticEpoch::INITIAL, 'intent_nonce' => (string) Str::uuid(),
                'intent_digest' => hash('sha256', $case), 'created_at' => DB::raw('clock_timestamp()'),
                'expires_at' => DB::raw("clock_timestamp()+interval '24 hours'"), 'reason_code' => 'lost_mfa']);
            $selector = ['case_id' => $case];
        }
        DB::table('auth_retention_holds')->insert($selector + ['id' => (string) Str::uuid(), 'reason_code' => 'legal', 'created_by_principal_id' => $creator]);
    }

    private function sessionFixture(int $user, string $audience, int $at, bool $absolute = false): string
    {
        $id = (string) Str::uuid();
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $audience === 'guest' ? null : $user,
            'payload' => 'SYNTHETIC-SESSION-SECRET-CANARY', 'last_activity' => $at]);
        if ($audience !== 'guest') {
            DB::table('auth_session_states')->insert(['session_id' => $id, 'user_id' => $user, 'audience' => $audience,
                'browser_binding_hash' => str_repeat('b', 64), 'auth_version' => 0,
                'primary_method' => $audience === 'admin' ? 'password' : 'email_otp',
                'primary_verified_at' => DB::raw("clock_timestamp()-interval '200 days'"),
                'mfa_method' => $audience === 'admin' ? 'totp' : null,
                'mfa_verified_at' => $audience === 'admin' ? DB::raw("clock_timestamp()-interval '199 days'") : null,
                'absolute_expires_at' => DB::raw($absolute ? "clock_timestamp()-interval '1 second'" : "clock_timestamp()+interval '1 hour'")]);
        }

        return $id;
    }

    private function gc(): int
    {
        return (new ContextDatabaseSessionHandler(DB::connection(), 'sessions', 720, app()))->gc(43200);
    }

    private function metadata(string $id)
    {
        return DB::table('auth_audit_events')->where('event_type', 'retention.session_scrubbed')->where('session_fingerprint', hash('sha256', $id));
    }

    public static function sessionScopes(): array
    {
        $cases = [];
        foreach (['user', 'case', 'time'] as $scope) {
            foreach (['admin', 'client', 'guest'] as $audience) {
                $cases[$scope.' '.$audience] = [$scope, $audience];
            }
        }

        return $cases;
    }

    #[DataProvider('sessionScopes')]
    public function test_expired_held_session_exports_only_metadata_then_deletes_payload_and_assurance(string $scope, string $audience): void
    {
        $user = $this->user();
        $at = time() - 181 * 86400;
        $id = $this->sessionFixture($user, $audience, $at);
        $this->hold($user, $scope, $at);
        $this->gc();
        $this->assertFalse(DB::table('sessions')->where('id', $id)->exists());
        $this->assertFalse(DB::table('auth_session_states')->where('session_id', $id)->exists());
        $this->assertSame(1, $this->metadata($id)->count());
        $event = $this->metadata($id)->first();
        $this->assertSame($at, strtotime($event->occurred_at));
        $this->assertSame($audience === 'guest' ? null : $user, $event->subject_user_id);
        $this->assertSame('session_'.($audience === 'guest' ? 'unknown' : $audience).'_metadata', $event->reason_code);
        $this->assertStringNotContainsString('SYNTHETIC-SESSION-SECRET-CANARY', json_encode($event));
        $this->assertStringNotContainsString($id, json_encode($event));
        $this->gc();
        $this->assertSame(1, $this->metadata($id)->count());
        // Навіть exact verified archive не дозволяє purge metadata під hold.
        $row = DB::selectOne('SELECT to_jsonb(a)::text bytes FROM auth_audit_events a WHERE id=?', [$event->id]);
        $mid = (string) Str::uuid();
        DB::table('auth_archive_manifests')->insert(['id' => $mid, 'entity_type' => 'audit', 'first_id' => $event->id, 'last_id' => $event->id,
            'first_occurred_at' => $event->occurred_at, 'last_occurred_at' => $event->occurred_at, 'row_count' => 1,
            'content_digest' => hash('sha256', '['.$row->bytes.']'), 'object_ref' => (string) Str::uuid(),
            'exported_at' => DB::raw('clock_timestamp()'), 'verified_at' => DB::raw('clock_timestamp()'),
            'retain_until' => DB::raw("clock_timestamp()+interval '365 days'"), 'request_id' => (string) Str::uuid()]);
        $run = app(RetentionService::class)->start();
        try {
            DB::transaction(function () use ($run, $mid, $event) {
                DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
                DB::select('SELECT auth_retention_purge_audit(?::uuid,1,?::uuid,?::bigint[])', [$run, $mid, '{'.$event->id.'}']);
            });
            $this->fail('Held metadata purged');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Legal hold', $e->getMessage());
        }
        $this->assertSame(1, $this->metadata($id)->count());
    }

    public function test_holds_do_not_change_audience_idle_or_absolute_ttl(): void
    {
        $user = $this->user();
        $this->hold($user, 'user', time());
        $adminLive = $this->sessionFixture($user, 'admin', time() - 300);
        $adminOld = $this->sessionFixture($user, 'admin', time() - 901);
        $clientLive = $this->sessionFixture($user, 'client', time() - 901);
        $clientOld = $this->sessionFixture($user, 'client', time() - 43201);
        $absolute = $this->sessionFixture($user, 'client', time(), true);
        $this->gc();
        foreach ([$adminLive, $clientLive] as $id) {
            $this->assertTrue(DB::table('sessions')->where('id', $id)->exists());
            $this->assertSame(0, $this->metadata($id)->count());
        }
        foreach ([$adminOld, $clientOld, $absolute] as $id) {
            $this->assertFalse(DB::table('sessions')->where('id', $id)->exists());
            $this->assertSame(1, $this->metadata($id)->count());
        }
    }

    public function test_assurance_identifies_subject_and_ambiguous_owner_preserves_evidence(): void
    {
        $user = $this->user();
        $this->hold($user, 'user', time());
        $known = $this->sessionFixture($user, 'client', time() - 50000);
        DB::table('sessions')->where('id', $known)->update(['user_id' => null]);
        $ambiguous = $this->sessionFixture($user, 'client', time() - 50000);
        DB::table('sessions')->where('id', $ambiguous)->update(['user_id' => $this->user()]);
        $this->gc();
        // GC може зупинити весь pass на conflict: безпечний повтор окремо.
        $this->assertTrue(DB::table('sessions')->where('id', $ambiguous)->exists());
        $this->assertSame(0, $this->metadata($ambiguous)->count());
        DB::table('sessions')->where('id', $ambiguous)->update(['user_id' => $user]);
        $this->gc();
        $this->assertFalse(DB::table('sessions')->where('id', $known)->exists());
        $this->assertSame($user, $this->metadata($known)->value('subject_user_id'));
    }

    public function test_session_audit_failure_and_delete_failure_roll_back_the_whole_item(): void
    {
        $user = $this->user();
        $this->hold($user, 'user', time());
        $id = $this->sessionFixture($user, 'client', time() - 50000);
        foreach (['auth_audit_events' => 'INSERT', 'sessions' => 'DELETE'] as $table => $operation) {
            DB::unprepared("CREATE FUNCTION b5_gc_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic fault'; END \$\$;
                CREATE TRIGGER b5_gc_fault BEFORE $operation ON $table FOR EACH ROW EXECUTE FUNCTION b5_gc_fault();");
            $this->gc();
            $this->assertTrue(DB::table('sessions')->where('id', $id)->exists());
            $this->assertTrue(DB::table('auth_session_states')->where('session_id', $id)->exists());
            $this->assertSame(0, $this->metadata($id)->count());
            $this->assertSame(1, DB::selectOne('SELECT 1 n')->n);
            DB::unprepared("DROP TRIGGER b5_gc_fault ON $table; DROP FUNCTION b5_gc_fault();");
        }
        $this->gc();
        $this->assertFalse(DB::table('sessions')->where('id', $id)->exists());
        $this->assertSame(1, $this->metadata($id)->count());
    }

    public static function factorScopes(): array
    {
        return [['user'], ['case'], ['time']];
    }

    #[DataProvider('factorScopes')]
    public function test_confirmed_pending_scrub_preserves_factor_and_held_pending_only(string $scope): void
    {
        $confirmed = $this->user();
        $pending = $this->user();
        $at = time() - 3600;
        foreach ([$confirmed, $pending] as $user) {
            DB::table('user_totp_factors')->insert(['user_id' => $user, 'pending_secret_ciphertext' => str_repeat('p', 100),
                'pending_key_version' => 'test_v1', 'pending_expires_at' => date('c', $at), 'created_at' => date('c', $at - 60)]
                + ($user === $confirmed ? ['secret_ciphertext' => str_repeat('s', 100), 'key_version' => 'test_v1',
                    'confirmed_at' => date('c', $at - 30), 'last_used_step' => 777] : []));
            $this->hold($user, $scope, $at);
        }
        $before = DB::table('user_totp_factors')->where('user_id', $confirmed)->first();
        $only = DB::table('user_totp_factors')->where('user_id', $pending)->first();
        $service = app(RetentionService::class);
        $this->assertContains((string) $confirmed, array_column($service->dryRun('pending_totp'), 'id'));
        $this->assertNotContains((string) $pending, array_column($service->dryRun('pending_totp'), 'id'));
        $run = $service->start();
        DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
        $this->assertSame(1, $service->cleanup($run, 1, 'pending_totp'));
        $this->assertSame(1, $service->cleanup($run, 1, 'pending_totp'));
        DB::statement('RESET ROLE');
        $after = DB::table('user_totp_factors')->where('user_id', $confirmed)->first();
        foreach (['pending_secret_ciphertext', 'pending_key_version', 'pending_expires_at'] as $field) {
            $this->assertNull($after->$field);
        }
        foreach (['secret_ciphertext', 'key_version', 'confirmed_at', 'last_used_step', 'disabled_at', 'recovery_batch_id'] as $field) {
            $this->assertSame($before->$field, $after->$field);
        }
        $this->assertEquals($only, DB::table('user_totp_factors')->where('user_id', $pending)->first());
        $this->assertSame(0, DB::table('users')->where('id', $confirmed)->value('auth_version'));
    }

    public function test_pending_scrub_audit_failure_restores_secret_and_creates_no_receipt(): void
    {
        $user = $this->user();
        DB::table('user_totp_factors')->insert(['user_id' => $user, 'secret_ciphertext' => str_repeat('s', 100), 'key_version' => 'test_v1',
            'confirmed_at' => DB::raw("clock_timestamp()-interval '1 hour'"), 'last_used_step' => 778,
            'pending_secret_ciphertext' => str_repeat('p', 100), 'pending_key_version' => 'test_v1',
            'pending_expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->hold($user, 'user', time());
        $run = app(RetentionService::class)->start();
        DB::unprepared("CREATE FUNCTION b5_batch_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
            IF NEW.event_type='retention.batch' THEN RAISE EXCEPTION 'synthetic fault'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER b5_batch_fault BEFORE INSERT ON auth_audit_events FOR EACH ROW EXECUTE FUNCTION b5_batch_fault();");
        try {
            DB::transaction(function () use ($run) {
                DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
                DB::select("SELECT auth_retention_cleanup(?::uuid,1,'pending_totp')", [$run]);
            });
            $this->fail('Unaudited scrub committed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic fault', $e->getMessage());
            $this->assertSame(str_repeat('p', 100), DB::table('user_totp_factors')->where('user_id', $user)->value('pending_secret_ciphertext'));
            $this->assertSame(0, DB::table('auth_retention_batches')->where('run_id', $run)->count());
        }
    }

    public function test_hold_registry_outage_cannot_turn_pending_scrub_into_unchecked_cleanup(): void
    {
        $user = $this->user();
        DB::table('user_totp_factors')->insert(['user_id' => $user, 'secret_ciphertext' => str_repeat('s', 100), 'key_version' => 'test_v1',
            'confirmed_at' => DB::raw("clock_timestamp()-interval '1 hour'"), 'last_used_step' => 779,
            'pending_secret_ciphertext' => str_repeat('p', 100), 'pending_key_version' => 'test_v1',
            'pending_expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->hold($user, 'user', time());
        $run = app(RetentionService::class)->start();
        DB::statement('ALTER TABLE auth_retention_holds RENAME TO b5_hold_registry_outage');
        try {
            try {
                DB::transaction(function () use ($run) {
                    DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
                    app(RetentionService::class)->cleanup($run, 1, 'pending_totp');
                });
                $this->fail('Missing holds accepted');
            } catch (\RuntimeException $e) {
                $this->assertSame('retention_unavailable', $e->getMessage());
            }
            $this->assertSame(str_repeat('p', 100), DB::table('user_totp_factors')->where('user_id', $user)->value('pending_secret_ciphertext'));
            $this->assertSame(0, DB::table('auth_retention_batches')->where('run_id', $run)->count());
        } finally {
            DB::statement('ALTER TABLE b5_hold_registry_outage RENAME TO auth_retention_holds');
        }
    }
}

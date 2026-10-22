<?php

namespace Tests\Auth6B6;

use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\BudgetUnavailable;
use App\Services\Auth\Budgets\LegacyBudgetMap;
use App\Services\Auth\Budgets\PostgresBudgets;
use App\Services\Auth\Client\AuthBudget;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\Restore\ExternalEpoch;
use App\Services\Auth\Restore\SyntheticEpoch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BudgetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        putenv('TELMANFIT_B6_TEST=1');
        parent::tearDown();
    }

    public static function policies(): array
    {
        $rows = json_decode(file_get_contents(__DIR__.'/policies.json'), true);

        return array_combine(array_column($rows, 0), $rows);
    }

    private function key(BudgetScope $scope): BudgetKey
    {
        return new BudgetKey($scope, bin2hex(random_bytes(16)));
    }

    private function operation(BudgetKey $key, bool $failure): bool
    {
        return $failure ? DB::transaction(fn () => app(PostgresBudgets::class)->failures([$key], true)->allowed)
            : app(PostgresBudgets::class)->admit([$key])->allowed;
    }

    #[DataProvider('policies')]
    public function test_last_allowed_first_denied_and_exact_boundary(string $scope, int $cap, int $seconds, int $pause, bool $failure): void
    {
        $key = $this->key(BudgetScope::from($scope));
        $policy = DB::selectOne('SELECT * FROM auth6b6_policy(?)', [$scope]);
        $this->assertSame([$cap, $seconds, $pause, $failure], [$policy->cap, $policy->window_seconds, $policy->pause_seconds, $policy->failure]);
        // Реальні commits для кожного scope, включно global 1000/h.
        for ($i = 0; $i < $cap; $i++) {
            $this->assertTrue($this->operation($key, $failure));
        }
        $before = DB::table('auth_rate_budgets')->where('scope', $scope)->where('key_hash', $key->hash)->first();
        $this->assertFalse($this->operation($key, $failure));
        $after = DB::table('auth_rate_budgets')->where('scope', $scope)->where('key_hash', $key->hash)->first();
        $this->assertSame($before->events, $after->events);
        $this->assertSame($before->blocked_until, $after->blocked_until);
        $this->assertSame($pause > 0, $after->blocked_until !== null);
        $r = DB::selectOne("SELECT cardinality(auth6b6_events(ARRAY['2026-01-01'::timestamptz-make_interval(secs=>?), '2026-01-01'::timestamptz-make_interval(secs=>?)+interval '1 microsecond'], '2026-01-01'::timestamptz, ?)) n", [$seconds, $seconds, $scope]);
        $this->assertSame(1, $r->n);
    }

    public function test_outer_rollback_cannot_refund_committed_admission(): void
    {
        $key = $this->key(BudgetScope::OtpCooldown);
        DB::beginTransaction();
        $pid = DB::selectOne('SELECT pg_backend_pid() pid')->pid;
        $this->assertTrue(app(PostgresBudgets::class)->admit([$key])->allowed);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame($pid, DB::selectOne('SELECT pg_backend_pid() pid')->pid);
        DB::rollBack();
        $this->assertFalse(app(PostgresBudgets::class)->admit([$key])->allowed);
    }

    public function test_group_denial_and_sql_error_never_partially_charge(): void
    {
        $a = $this->key(BudgetScope::OtpCooldown);
        $b = $this->key(BudgetScope::OtpEmailAll);
        $this->assertTrue(app(PostgresBudgets::class)->admit([$a])->allowed);
        $this->assertFalse(app(PostgresBudgets::class)->admit([$b, $a])->allowed);
        $this->assertSame('{}', DB::table('auth_rate_budgets')->where('scope', $b->scope->value)->where('key_hash', $b->hash)->value('events'));
        $this->unavailable(fn () => app(PostgresBudgets::class)->admit([$b, new BudgetKey(BudgetScope::OtpFailure, 'wrong-mode')]));
        $this->assertSame('{}', DB::table('auth_rate_budgets')->where('scope', $b->scope->value)->where('key_hash', $b->hash)->value('events'));
    }

    public function test_failure_commit_rollback_and_pause_not_extended(): void
    {
        $key = $this->key(BudgetScope::OtpFailure);
        DB::beginTransaction();
        $this->assertTrue(app(PostgresBudgets::class)->failures([$key], true)->allowed);
        DB::rollBack();
        $this->assertFalse(DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->exists());
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->operation($key, true));
        }
        $before = DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->value('blocked_until');
        $this->assertFalse($this->operation($key, true));
        $this->assertSame($before, DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->value('blocked_until'));
        $this->unavailable(fn () => app(PostgresBudgets::class)->failures([$key], true));
    }

    public function test_hmac_scope_framing_and_no_raw_identifiers(): void
    {
        $email = 'redaction-canary@example.invalid';
        $a = new BudgetKey(BudgetScope::OtpEmailAll, $email);
        $b = new BudgetKey(BudgetScope::AdminEmail, $email);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $a->hash);
        $this->assertNotSame(hash('sha256', $email), $a->hash);
        $this->assertNotSame($a->hash, $b->hash);
        $this->assertTrue(app(PostgresBudgets::class)->admit([$a])->allowed);
        $this->assertStringNotContainsString($email, json_encode(DB::table('auth_rate_budgets')->where('key_hash', $a->hash)->first()));
        $this->unavailable(fn () => LegacyBudgetMap::key('unknown:'.$email, 1, 60));
        $this->unavailable(fn () => LegacyBudgetMap::key('issue:all:'.$email, 1000000, 1));
    }

    private function unavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected closed budget');
        } catch (BudgetUnavailable $e) {
            $this->assertSame('rate_budget_unavailable', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_restore_epoch_missing_mismatch_and_issuance_fence(): void
    {
        $key = $this->key(BudgetScope::OtpCooldown);
        foreach ([null, '60605050-0000-4000-8000-000000000002'] as $epoch) {
            app()->instance(ExternalEpoch::class, new SyntheticEpoch($epoch));
            $this->unavailable(fn () => app(PostgresBudgets::class)->admit([$key]));
        }
        app()->instance(ExternalEpoch::class, new SyntheticEpoch);
        DB::table('auth_security_runtime')->where('id', 1)->update(['issuance_not_before' => DB::raw("clock_timestamp()+interval '24 hours'")]);
        try {
            $this->unavailable(fn () => app(PostgresBudgets::class)->admit([$key]));
            $this->assertFalse(DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->exists());
        } finally {
            DB::table('auth_security_runtime')->where('id', 1)->update(['issuance_not_before' => DB::raw('clock_timestamp()')]);
        }
    }

    public function test_synthetic_dual_observation_including_charge_on_later_denial(): void
    {
        $input = bin2hex(random_bytes(16));
        $old = $new = [];
        putenv('TELMANFIT_B6_TEST=0');
        for ($i = 0; $i < 4; $i++) {
            $old[] = app(ControlledAuth::class)->transaction(fn () => app(AuthBudget::class)->take('issue:15:login:'.$input, 3, 900));
        }
        putenv('TELMANFIT_B6_TEST=1');
        for ($i = 0; $i < 4; $i++) {
            $new[] = app(ControlledAuth::class)->transaction(fn () => app(AuthBudget::class)->take('issue:15:login:'.$input, 3, 900));
        }
        $this->assertSame([true, true, true, false], $new);
        $this->assertSame($old, $new);
        $ip = $this->key(BudgetScope::OtpIpShort);
        $global = $this->key(BudgetScope::OtpCooldown);
        app(PostgresBudgets::class)->admit([$global]);
        $this->assertFalse(app(PostgresBudgets::class)->admit([$ip])->allowed && app(PostgresBudgets::class)->admit([$global])->allowed);
        $this->assertSame(1, DB::selectOne('SELECT cardinality(events) n FROM auth_rate_budgets WHERE scope=? AND key_hash=?', [$ip->scope->value, $ip->hash])->n);
    }

    public function test_dual_observation_all_existing_cache_policies_at_last_and_first_boundary(): void
    {
        $matrix = [
            ['cooldown:login:', 'otp_cooldown', 1, 60], ['issue:15:login:', 'otp_email_short', 3, 900],
            ['issue:day:login:', 'otp_email_day', 10, 86400], ['issue:all:', 'otp_email_all', 15, 86400],
            ['issue:ip:15:', 'otp_ip_short', 20, 900], ['issue:ip:day:', 'otp_ip_day', 100, 86400],
            ['verify:ip:', 'otp_verify_ip', 10, 60], ['exchange:ip:', 'invitation_ip', 10, 60],
            ['exchange:token:', 'invitation_token', 5, 900], ['complete:ip:', 'registration_ip', 10, 60],
            ['complete:flow:', 'registration_flow', 5, 900], ['mfa:ip:', 'mfa_ip', 20, 900],
            ['invitation-stepup:ip:', 'stepup_ip', 20, 900], ['invitation-stepup:user:', 'stepup_user', 5, 900],
            ['recovery.exchange:ip:', 'recovery_exchange_ip', 10, 60], ['recovery.exchange:case:', 'recovery_exchange_case', 5, 900],
            ['recovery.totp:flow:', 'recovery_totp_flow', 5, 900], ['recovery.totp:user:', 'recovery_totp_user', 10, 900],
            ['recovery.totp:ip:', 'recovery_totp_ip', 20, 900],
        ];
        $observations = [];
        foreach ($matrix as [$prefix, $scope, $cap, $window]) {
            $legacy = $prefix.bin2hex(random_bytes(16));
            $typed = LegacyBudgetMap::key($legacy, $cap, $window);
            $this->assertSame($scope, $typed->scope->value);
            $now = (float) DB::selectOne('SELECT extract(epoch FROM clock_timestamp()) n')->n;
            putenv('TELMANFIT_B6_TEST=0');
            Cache::store('database')->put('auth4:'.app(ControlledAuth::class)->fingerprint($legacy), array_fill(0, $cap - 1, $now - 1), $window + 1);
            $old = [];
            for ($i = 0; $i < 2; $i++) {
                $old[] = app(ControlledAuth::class)->transaction(fn () => app(AuthBudget::class)->take($legacy, $cap, $window));
            }
            putenv('TELMANFIT_B6_TEST=1');
            DB::statement("INSERT INTO auth_rate_budgets(scope,key_hash,events,last_clock_at,gc_after) VALUES(?,?,array_fill(clock_timestamp()-interval '1 second',ARRAY[?::integer]),clock_timestamp(),clock_timestamp()+interval '2 days')", [$scope, $typed->hash, $cap - 1]);
            $new = [];
            for ($i = 0; $i < 2; $i++) {
                $new[] = app(ControlledAuth::class)->transaction(fn () => app(AuthBudget::class)->take($legacy, $cap, $window));
            }
            $this->assertSame([true, false], $old);
            $this->assertSame($old, $new);
            $observations[] = ['scope' => $scope, 'old' => $old, 'new' => $new, 'seed_events' => $cap - 1];
        }
        file_put_contents(base_path('docs/evidence/auth6b-b6/dual-observation.json'), json_encode($observations, JSON_PRETTY_PRINT)."\n");
        chmod(base_path('docs/evidence/auth6b-b6/dual-observation.json'), 0644);
    }

    public function test_migration_source_and_runtime_privileges(): void
    {
        $this->assertSame(file_get_contents(base_path('docs/09_auth_rate_budgets.sql')), file_get_contents(database_path('sql/auth6b6/00_budgets.sql')));
        DB::beginTransaction();
        DB::statement('SET LOCAL ROLE telmanfit_budget_runtime');
        $key = str_repeat('a', 64);
        $this->assertTrue(DB::selectOne('SELECT * FROM auth6b6_group(?::text[],?::text[],?)', ['{otp_cooldown}', '{'.$key.'}', 'consume'])->allowed);
        foreach (["INSERT INTO auth_rate_budgets(scope,key_hash,last_clock_at,gc_after) VALUES('otp_cooldown',repeat('b',64),clock_timestamp(),clock_timestamp()+interval '1 day')", 'DELETE FROM auth_rate_budgets', 'TRUNCATE auth_rate_budgets', 'SET ROLE telmanfit_budget_owner'] as $sql) {
            DB::statement('SAVEPOINT denied_privilege');
            try {
                DB::statement($sql);
                $this->fail('Privilege escalation');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
                DB::statement('ROLLBACK TO SAVEPOINT denied_privilege');
            }
        }
        DB::rollBack();
    }

    public function test_malformed_rows_invalid_scope_nulls_future_and_clock_rollback(): void
    {
        foreach (["'{NULL}'::timestamptz[]", "ARRAY[clock_timestamp()+interval '1 hour']", "ARRAY[clock_timestamp(),clock_timestamp()-interval '1 second']", 'array_fill(clock_timestamp(),ARRAY[1001])'] as $events) {
            DB::beginTransaction();
            try {
                DB::statement("INSERT INTO auth_rate_budgets(scope,key_hash,events,last_clock_at,gc_after) VALUES('otp_email_all',repeat('e',64),$events,clock_timestamp(),clock_timestamp()+interval '2 days')");
                $this->fail('Malformed row');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            } finally {
                DB::rollBack();
            }
        }
        foreach ([[null, null, 'consume'], ['{invalid}', '{'.str_repeat('a', 64).'}', 'consume'], ['{otp_cooldown}', '{'.str_repeat('a', 64).'}', null]] as $args) {
            DB::beginTransaction();
            try {
                DB::select('SELECT * FROM auth6b6_group(?::text[],?::text[],?)', $args);
                $this->fail('Malformed API');
            } catch (QueryException $e) {
                $this->assertSame('22023', $e->errorInfo[0]);
            } finally {
                DB::rollBack();
            }
        }
        // Відтворюємо вже persisted future last_clock, який лишився після OS clock rollback.
        $key = $this->key(BudgetScope::OtpCooldown);
        app(PostgresBudgets::class)->admit([$key]);
        DB::statement('ALTER TABLE auth_rate_budgets DISABLE TRIGGER auth_rate_validate');
        try {
            DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->update(['last_clock_at' => DB::raw("clock_timestamp()+interval '1 hour'")]);
        } finally {
            DB::statement('ALTER TABLE auth_rate_budgets ENABLE TRIGGER auth_rate_validate');
        }
        $this->unavailable(fn () => app(PostgresBudgets::class)->admit([$key]));
        DB::statement('ALTER TABLE auth_rate_budgets DISABLE TRIGGER auth_rate_validate');
        DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->delete();
        DB::statement('ALTER TABLE auth_rate_budgets ENABLE TRIGGER auth_rate_validate');
    }
}

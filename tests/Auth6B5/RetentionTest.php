<?php

namespace Tests\Auth6B5;

use App\Services\Auth\Retention\ArchiveVerification;
use App\Services\Auth\Retention\RetentionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RetentionTest extends TestCase
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

    private function reject(callable $call): void
    {
        try {
            DB::transaction($call);
            $this->fail('Unsafe operation accepted');
        } catch (QueryException|\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function rows(int $count = 1, string $age = '181 days'): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = DB::table('auth_audit_events')->insertGetId(['occurred_at' => DB::raw("clock_timestamp()-interval '$age'"),
                'event_type' => 'test.synthetic', 'outcome' => 'success', 'request_id' => (string) Str::uuid()]);
        }

        return $ids;
    }

    private function receipt(array $ids, bool $stale = false): string
    {
        $bounds = DB::selectOne("SELECT min(id) first_id,max(id) last_id,min(occurred_at) first_time,max(occurred_at) last_time,
            encode(sha256(convert_to(jsonb_agg(to_jsonb(a) ORDER BY id)::text,'UTF8')),'hex') digest FROM auth_audit_events a WHERE id=ANY(?::bigint[])", ['{'.implode(',', $ids).'}']);
        $id = (string) Str::uuid();
        DB::table('auth_archive_manifests')->insert(['id' => $id, 'entity_type' => 'audit', 'first_id' => $bounds->first_id,
            'last_id' => $bounds->last_id, 'first_occurred_at' => $bounds->first_time, 'last_occurred_at' => $bounds->last_time,
            'row_count' => count($ids), 'content_digest' => $bounds->digest, 'object_ref' => (string) Str::uuid(),
            'exported_at' => DB::raw($stale ? "clock_timestamp()-interval '3 days'" : 'clock_timestamp()'), 'verified_at' => DB::raw($stale ? "clock_timestamp()-interval '2 days'" : 'clock_timestamp()'),
            'retain_until' => DB::raw("clock_timestamp()+interval '365 days'"), 'request_id' => (string) Str::uuid()]);

        return $id;
    }

    private function createRun(): string
    {
        $id = (string) Str::uuid();
        DB::table('auth_retention_runs')->insert(['id' => $id, 'mode' => 'apply', 'policy_version' => 1,
            'cutoff' => DB::raw('clock_timestamp()'), 'state' => 'running', 'request_id' => (string) Str::uuid()]);

        return $id;
    }

    private function purge(string $run, string $manifest, array $ids, int $batch = 1): int
    {
        DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
        try {
            return (int) DB::selectOne('SELECT auth_retention_purge_audit(?::uuid,?,?::uuid,?::bigint[]) n', [$run, $batch, $manifest, '{'.implode(',', $ids).'}'])->n;
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    public function test_dry_run_changes_nothing_and_keeps_young_rows(): void
    {
        $old = $this->rows();
        $young = $this->rows(age: '179 days');
        $before = DB::selectOne('SELECT (SELECT count(*) FROM auth_audit_events) a,(SELECT count(*) FROM auth_retention_runs) r,(SELECT count(*) FROM auth_retention_batches) b');
        $ids = array_column(app(RetentionService::class)->dryRun(), 'id');
        $this->assertContains($old[0], $ids);
        $this->assertNotContains($young[0], $ids);
        $this->assertEquals($before, DB::selectOne('SELECT (SELECT count(*) FROM auth_audit_events) a,(SELECT count(*) FROM auth_retention_runs) r,(SELECT count(*) FROM auth_retention_batches) b'));
    }

    public function test_verified_exact_archive_batch_500_idempotency_and_rollback(): void
    {
        $ids = $this->rows(500);
        $mid = $this->receipt($ids);
        $run = $this->createRun();
        DB::statement('SAVEPOINT before_purge');
        $this->assertSame(500, $this->purge($run, $mid, $ids));
        $this->assertSame(500, $this->purge($run, $mid, $ids));
        $this->assertSame(0, DB::table('auth_audit_events')->whereIn('id', $ids)->count());
        DB::statement('ROLLBACK TO SAVEPOINT before_purge');
        $this->assertSame(500, DB::table('auth_audit_events')->whereIn('id', $ids)->count());
        $this->assertSame(500, $this->purge($run, $mid, $ids));
        $this->assertSame(1, DB::table('auth_retention_batches')->where('run_id', $run)->count());
        $this->reject(fn () => $this->purge($run, $mid, array_reverse($ids)));
        $this->reject(fn () => $this->purge($run, $mid, range(1, 501), 2));
    }

    public function test_missing_wrong_or_stale_archive_and_age_never_delete(): void
    {
        $ids = $this->rows();
        $run = $this->createRun();
        $this->reject(fn () => $this->purge($run, (string) Str::uuid(), $ids));
        $stale = $this->receipt($ids, stale: true);
        $this->reject(fn () => $this->purge($run, $stale, $ids));
        $other = $this->rows();
        $this->reject(fn () => $this->purge($run, $this->receipt($other), $ids));
        $young = $this->rows(age: '179 days');
        $mid = $this->receipt($young);
        $this->reject(fn () => $this->purge($run, $mid, $young));
        $this->assertSame(1, DB::table('auth_audit_events')->whereIn('id', $ids)->count());
        $this->reject(fn () => DB::table('auth_archive_manifests')->where('id', $mid)->update(['verified_at' => DB::raw("clock_timestamp()-interval '2 days'")]));
        $this->reject(fn () => app(RetentionService::class)->apply($run, 1, $mid, $young));
    }

    public function test_durable_hold_blocks_purge_and_release_preserves_history(): void
    {
        $ids = $this->rows();
        $mid = $this->receipt($ids);
        $run = $this->createRun();
        $creator = (string) Str::uuid();
        $reviewer = (string) Str::uuid();
        foreach ([$creator, $reviewer] as $p) {
            DB::table('auth_recovery_principals')->insert(['id' => $p, 'authority' => 'reviewer', 'identity_record_digest' => str_repeat('a', 64), 'created_at' => DB::raw('clock_timestamp()')]);
        }
        $hold = (string) Str::uuid();
        DB::table('auth_retention_holds')->insert(['id' => $hold, 'audit_from' => DB::raw("clock_timestamp()-interval '182 days'"),
            'audit_to' => DB::raw("clock_timestamp()-interval '180 days'"), 'reason_code' => 'legal', 'created_by_principal_id' => $creator]);
        $this->reject(fn () => $this->purge($run, $mid, $ids));
        $this->assertNotContains($ids[0], array_column(app(RetentionService::class)->dryRun(), 'id'));
        $this->reject(fn () => DB::table('auth_retention_holds')->where('id', $hold)->delete());
        $this->reject(fn () => DB::table('auth_retention_holds')->where('id', $hold)->update(['released_at' => DB::raw('clock_timestamp()'), 'released_by_principal_id' => $creator]));
        DB::table('auth_retention_holds')->where('id', $hold)->update(['released_at' => DB::raw('clock_timestamp()'), 'released_by_principal_id' => $reviewer]);
        $this->assertSame(1, $this->purge($run, $mid, $ids));
        $this->assertSame(1, DB::table('auth_retention_holds')->where('id', $hold)->count());
    }

    public function test_executor_runtime_public_cannot_bypass_history_or_owner(): void
    {
        $this->rows();
        foreach (['telmanfit_retention_executor', 'telmanfit_notice_writer', 'telmanfit_notice_dispatcher'] as $role) {
            foreach (['DELETE FROM auth_audit_events', "UPDATE auth_audit_events SET outcome='error'", 'TRUNCATE auth_audit_events',
                'SET ROLE telmanfit_retention_owner', 'ALTER TABLE auth_audit_events DISABLE TRIGGER ALL',
                'DELETE FROM auth_retention_holds', 'UPDATE auth_archive_manifests SET verified_at=clock_timestamp()'] as $sql) {
                $this->reject(function () use ($role, $sql) {
                    DB::statement('SET LOCAL ROLE '.$role);
                    DB::statement($sql);
                });
            }
        }
        $this->assertSame(0, DB::selectOne("SELECT count(*) n FROM pg_proc p CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE p.proname='auth_retention_purge_audit' AND a.grantee=0 AND a.privilege_type='EXECUTE'")->n);
        $this->assertFalse(DB::selectOne("SELECT pg_has_role('telmanfit_test_runner','telmanfit_retention_owner','MEMBER') b")->b);
        $this->reject(fn () => DB::statement('TRUNCATE auth_retention_runs'));

    }

    public function test_archive_history_is_immutable_and_authoritative_sql_matches(): void
    {
        $ids = $this->rows();
        $mid = $this->receipt($ids);
        $this->reject(fn () => DB::table('auth_archive_manifests')->where('id', $mid)->delete());
        $this->assertSame(file_get_contents(base_path('docs/08_auth_retention_restore.sql')), file_get_contents(database_path('sql/auth6b5/00_retention.sql')));
    }

    private function cleanup(string $run, string $kind, int $batch = 1): int
    {
        return DB::transaction(function () use ($run, $kind, $batch) {
            DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
            $n = (int) DB::selectOne('SELECT auth_retention_cleanup(?::uuid,?,?) n', [$run, $batch, $kind])->n;
            DB::statement('RESET ROLE');

            return $n;
        });
    }

    public function test_child_first_cleanup_does_not_change_live_authority_or_watermarks(): void
    {
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic cleanup', 'role' => 'client']);
        $flow = DB::table('auth_flows')->insertGetId(['public_id' => (string) Str::uuid(), 'user_id' => $user,
            'auth_version_at_start' => 0, 'browser_binding_hash' => str_repeat('a', 64), 'audience' => 'client', 'purpose' => 'login',
            'created_at' => DB::raw("clock_timestamp()-interval '4 days'"), 'expires_at' => DB::raw("clock_timestamp()-interval '3 days'")]);
        $challenge = DB::table('auth_challenges')->insertGetId(['public_id' => (string) Str::uuid(), 'flow_id' => $flow, 'generation' => 1,
            'email_normalized' => 'synthetic@example.invalid', 'code_mac' => str_repeat('c', 64), 'key_version' => 'test_v1', 'max_attempts' => 5,
            'created_at' => DB::raw("clock_timestamp()-interval '4 days'"), 'expires_at' => DB::raw("clock_timestamp()-interval '3 days'")]);
        DB::table('user_totp_factors')->insert(['user_id' => $user, 'secret_ciphertext' => str_repeat('s', 100), 'key_version' => 'test_v1',
            'confirmed_at' => DB::raw('clock_timestamp()'), 'last_used_step' => 777,
            'pending_secret_ciphertext' => str_repeat('p', 100), 'pending_key_version' => 'test_v1',
            'pending_expires_at' => DB::raw("clock_timestamp()-interval '1 second'"), 'created_at' => DB::raw("clock_timestamp()-interval '1 hour'")]);
        $run = $this->createRun();
        $this->assertSame(0, $this->cleanup($run, 'flows'));
        $this->assertSame(1, $this->cleanup($run, 'challenges', 2));
        $this->assertSame(1, $this->cleanup($run, 'challenges', 2));
        $this->assertSame(1, $this->cleanup($run, 'flows', 3));
        $this->assertFalse(DB::table('auth_challenges')->where('id', $challenge)->exists());
        $this->assertFalse(DB::table('auth_flows')->where('id', $flow)->exists());
        $this->assertSame(1, $this->cleanup($run, 'pending_totp', 4));
        $factor = DB::table('user_totp_factors')->where('user_id', $user)->first();
        $this->assertSame(777, $factor->last_used_step);
        $this->assertSame(str_repeat('s', 100), $factor->secret_ciphertext);
        $this->assertNull($factor->pending_secret_ciphertext);
        $this->assertSame(0, DB::table('users')->where('id', $user)->value('auth_version'));
        $this->reject(fn () => $this->cleanup($run, 'users', 5));
    }

    public function test_active_invitations_and_current_codes_survive_and_limits_use_run_cutoff(): void
    {
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic retention', 'role' => 'admin']);
        $active = DB::table('client_invitations')->insertGetId(['token_hash' => hash('sha256', 'active'), 'created_by_user_id' => $user,
            'coach_user_id' => $user, 'created_at' => DB::raw("clock_timestamp()-interval '400 days'")]);
        $terminal = DB::table('client_invitations')->insertGetId(['token_hash' => hash('sha256', 'terminal'), 'created_by_user_id' => $user,
            'coach_user_id' => $user, 'created_at' => DB::raw("clock_timestamp()-interval '400 days'"),
            'revoked_at' => DB::raw("clock_timestamp()-interval '366 days'"), 'revoked_by_user_id' => $user]);
        $young = DB::table('client_invitations')->insertGetId(['token_hash' => hash('sha256', 'young'), 'created_by_user_id' => $user,
            'coach_user_id' => $user, 'created_at' => DB::raw("clock_timestamp()-interval '400 days'"),
            'revoked_at' => DB::raw("clock_timestamp()-interval '364 days'"), 'revoked_by_user_id' => $user]);
        $batch = (string) Str::uuid();
        DB::table('user_totp_factors')->insert(['user_id' => $user, 'secret_ciphertext' => str_repeat('s', 100), 'key_version' => 'test_v1',
            'confirmed_at' => DB::raw('clock_timestamp()'), 'last_used_step' => 888, 'recovery_batch_id' => $batch]);
        $current = DB::table('mfa_recovery_codes')->insertGetId(['user_id' => $user, 'selector' => (string) Str::uuid(), 'batch_id' => $batch,
            'code_hash' => password_hash('synthetic', PASSWORD_ARGON2ID), 'created_at' => DB::raw("clock_timestamp()-interval '400 days'"),
            'used_at' => DB::raw("clock_timestamp()-interval '366 days'")]);
        $run = $this->createRun();
        $this->assertSame(1, $this->cleanup($run, 'invitations'));
        $this->assertTrue(DB::table('client_invitations')->where('id', $active)->exists());
        $this->assertTrue(DB::table('client_invitations')->where('id', $young)->exists());
        $this->assertFalse(DB::table('client_invitations')->where('id', $terminal)->exists());
        $this->assertSame(0, $this->cleanup($run, 'codes', 2));
        $this->assertTrue(DB::table('mfa_recovery_codes')->where('id', $current)->exists());
    }

    public function test_independent_archive_object_must_match_immutable_receipt(): void
    {
        $ids = $this->rows();
        $mid = $this->receipt($ids);
        $run = $this->createRun();
        $archive = new SyntheticArchive;
        app()->instance(ArchiveVerification::class, $archive);
        $this->reject(fn () => app(RetentionService::class)->apply($run, 1, $mid, $ids));
        $manifest = DB::table('auth_archive_manifests')->where('id', $mid)->first();
        $archive->objects[$manifest->object_ref] = 'synthetic corrupted object';
        $this->reject(fn () => app(RetentionService::class)->apply($run, 1, $mid, $ids));
        $archive->objects[$manifest->object_ref] = DB::selectOne('SELECT jsonb_agg(to_jsonb(a) ORDER BY id)::text bytes FROM auth_audit_events a WHERE id=ANY(?::bigint[])', ['{'.implode(',', $ids).'}'])->bytes;
        DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
        $this->assertSame(1, app(RetentionService::class)->apply($run, 1, $mid, $ids));
        DB::statement('RESET ROLE');
    }

    public function test_unavailable_hold_registry_blocks_delete_and_run_history_cannot_reopen(): void
    {
        $ids = $this->rows();
        $mid = $this->receipt($ids);
        $run = $this->createRun();
        DB::statement('SAVEPOINT registry_available');
        DB::statement('REVOKE SELECT ON auth_retention_holds FROM telmanfit_retention_owner');
        $this->reject(fn () => $this->purge($run, $mid, $ids));
        $this->assertTrue(DB::table('auth_audit_events')->where('id', $ids[0])->exists());
        DB::statement('ROLLBACK TO SAVEPOINT registry_available');
        DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
        app(RetentionService::class)->finish($run);
        DB::statement('RESET ROLE');
        $this->assertSame('completed', DB::table('auth_retention_runs')->where('id', $run)->value('state'));
        $this->reject(fn () => $this->purge($run, $mid, $ids));
        $this->reject(fn () => DB::table('auth_retention_runs')->where('id', $run)->update(['state' => 'running', 'finished_at' => null]));
    }

    public function test_failed_payload_is_scrubbed_after_24_hours_even_under_hold(): void
    {
        $p = (string) Str::uuid();
        $reviewer = (string) Str::uuid();
        foreach ([$p, $reviewer] as $id) {
            DB::table('auth_recovery_principals')->insert(['id' => $id, 'authority' => 'reviewer', 'identity_record_digest' => str_repeat('e', 64), 'created_at' => DB::raw('clock_timestamp()')]);
        }
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic failure hold', 'role' => 'client']);
        $hold = (string) Str::uuid();
        DB::table('auth_retention_holds')->insert(['id' => $hold, 'subject_user_id' => $user, 'reason_code' => 'incident', 'created_by_principal_id' => $p]);
        $job = DB::table('failed_jobs')->insertGetId(['uuid' => (string) Str::uuid(), 'connection' => 'synthetic', 'queue' => 'auth',
            'payload' => 'SYNTHETIC-CANARY-ENCRYPTED-PAYLOAD', 'exception' => 'synthetic transport', 'failed_at' => DB::raw("clock_timestamp()-interval '25 hours'")]);
        $run = $this->createRun();
        $this->assertSame(1, $this->cleanup($run, 'failed_jobs'));
        $this->assertSame('[redacted]', DB::table('failed_jobs')->where('id', $job)->value('payload'));
        $this->assertSame('[redacted]', DB::table('failed_jobs')->where('id', $job)->value('exception'));
        $this->assertSame(0, $this->cleanup($run, 'failed_jobs', 2));
        DB::table('auth_retention_holds')->where('id', $hold)->update(['released_at' => DB::raw('clock_timestamp()'), 'released_by_principal_id' => $reviewer]);
        $this->assertSame(1, $this->cleanup($run, 'failed_jobs', 3));
        $this->assertFalse(DB::table('failed_jobs')->where('id', $job)->exists());
    }

    public function test_exact_180_day_cutoff_and_one_microsecond_younger(): void
    {
        $cutoff = DB::selectOne('SELECT clock_timestamp() t')->t;
        $run = (string) Str::uuid();
        DB::table('auth_retention_runs')->insert(['id' => $run, 'mode' => 'apply', 'policy_version' => 1, 'cutoff' => $cutoff, 'state' => 'running', 'request_id' => (string) Str::uuid()]);
        $at = DB::selectOne("SELECT ?::timestamptz-interval '180 days' boundary,?::timestamptz-interval '180 days'+interval '1 microsecond' young", [$cutoff, $cutoff]);
        foreach ([$at->boundary, $at->young] as $i => $time) {
            $id = DB::table('auth_audit_events')->insertGetId(['occurred_at' => $time, 'event_type' => 'test.boundary', 'outcome' => 'success', 'request_id' => (string) Str::uuid()]);
            $mid = $this->receipt([$id]);
            if ($i === 0) {
                $this->assertSame(1, $this->purge($run, $mid, [$id], 1));
            } else {
                $this->reject(fn () => $this->purge($run, $mid, [$id], 2));
                $this->assertTrue(DB::table('auth_audit_events')->where('id', $id)->exists());
            }
        }
    }
}

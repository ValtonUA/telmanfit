<?php

namespace Tests\AuthPostgres;

use App\Support\Auth\AuthSecretCodec;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Спільний fail-closed guard виконується до тестових записів.
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        DB::table('users')->insert(['id' => 5000000000, 'name' => 'Synthetic admin', 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function reject(string $state, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Мала бути відмова SQL '.$state);
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0], $e->getMessage());
        }
    }

    private function invitation(array $changes = []): int
    {
        return DB::table('client_invitations')->insertGetId(array_merge([
            'token_hash' => hash('sha256', random_bytes(32)),
            'created_by_user_id' => 5000000000,
            'coach_user_id' => 5000000000,
            'created_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ], $changes));
    }

    private function flow(array $changes = []): int
    {
        return DB::table('auth_flows')->insertGetId(array_merge([
            'public_id' => (string) Str::uuid(), 'browser_binding_hash' => hash('sha256', random_bytes(32)),
            'audience' => 'client', 'purpose' => 'login', 'user_id' => 5000000000,
            'auth_version_at_start' => 0, 'created_at' => now()->subMinute(), 'expires_at' => now()->addHour(),
        ], $changes));
    }

    private function factor(): void
    {
        $codec = new AuthSecretCodec;
        DB::table('user_totp_factors')->insert([
            'user_id' => 5000000000, 'secret_ciphertext' => $codec->encryptTotp('SYNTHETICSECRET'),
            'key_version' => 'test_v1', 'created_at' => now()->subMinute(), 'confirmed_at' => now(),
        ]);
    }

    public function test_sql_snapshot_matches_migrations_and_all_user_keys_are_bigint(): void
    {
        $parts = glob(database_path('sql/auth2/*.sql'));
        $this->assertCount(8, $parts);
        $snapshot = file_get_contents(base_path('docs/05_auth_schema.sql'));
        foreach ($parts as $part) {
            $this->assertStringContainsString(file_get_contents($part), $snapshot);
        }
        $keys = DB::select("SELECT c.conrelid::regclass::text AS tbl, a.attname AS col, format_type(a.atttypid,a.atttypmod) AS type FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey) WHERE c.contype='f' AND c.confrelid IN ('users'::regclass, 'user_totp_factors'::regclass) AND c.conrelid::regclass::text NOT LIKE 'auth_recovery_%' AND c.conrelid::regclass::text NOT IN ('auth_security_notice_outbox','auth_retention_holds')");
        $this->assertCount(15, $keys);
        foreach ($keys as $key) {
            $this->assertSame('bigint', $key->type);
        }
        $this->assertSame('bigint', DB::selectOne("SELECT format_type(atttypid,atttypmod) AS type FROM pg_attribute WHERE attrelid='users'::regclass AND attname='id'")->type);
    }

    public function test_live_auth_catalog_matches_executable_documentation(): void
    {
        DB::unprepared('CREATE SCHEMA auth2_reference; SET LOCAL search_path TO auth2_reference,public;
            CREATE TABLE users(id bigint PRIMARY KEY, email varchar(255));
            CREATE TABLE clients(id integer PRIMARY KEY);
            CREATE TABLE sessions(id varchar(255) PRIMARY KEY);');
        DB::unprepared(file_get_contents(base_path('docs/05_auth_schema.sql')));
        // B5 — єдина погоджена заміна AUTH-2 audit guard, перевіряємо точний новий source.
        $b5 = file_get_contents(base_path('docs/08_auth_retention_restore.sql'));
        preg_match('/CREATE OR REPLACE FUNCTION auth2_guard_audit\(\).*?END \$\$;/s', $b5, $guard);
        DB::unprepared($guard[0]);
        DB::statement('SET LOCAL search_path TO public');
        $queries = [
            'columns' => "SELECT c.relname || '.' || a.attname AS name, format_type(a.atttypid,a.atttypmod) || '|' || a.attnotnull || '|' || a.attidentity::text || '|' || coalesce(pg_get_expr(d.adbin,d.adrelid),'') AS definition FROM pg_class c JOIN pg_attribute a ON a.attrelid=c.oid LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum WHERE c.relnamespace=?::regnamespace AND c.relkind='r' AND a.attnum>0 AND NOT a.attisdropped",
            'constraints' => "SELECT t.relname || '.' || c.conname AS name, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid WHERE c.connamespace=?::regnamespace",
            'indexes' => 'SELECT tablename || \'.\' || indexname AS name, indexdef AS definition FROM pg_indexes WHERE schemaname=?',
            'triggers' => "SELECT c.relname || '.' || t.tgname AS name, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace=?::regnamespace AND NOT t.tgisinternal",
            'functions' => "SELECT proname AS name, pg_get_functiondef(oid) AS definition FROM pg_proc WHERE pronamespace=?::regnamespace AND (proname LIKE 'auth2_%' OR proname LIKE 'auth5_%')",
        ];
        foreach ($queries as $kind => $sql) {
            $catalogs = [];
            foreach (['public', 'auth2_reference'] as $schema) {
                $catalog = [];
                foreach (DB::select($sql, [$schema]) as $row) {
                    // AUTH-2/5 projection лишається точним; additive B3 guards перевіряє MigrationTest.
                    if ($kind === 'triggers' && str_contains($row->name, '.auth6b_')) {
                        continue;
                    }
                    $table = explode('.', $row->name)[0];
                    if ($kind !== 'functions' && ! in_array($table, ['client_invitations', 'auth_flows', 'auth_challenges', 'user_totp_factors', 'mfa_recovery_codes', 'auth_session_states', 'auth_audit_events'], true)) {
                        continue;
                    }
                    $catalog[$row->name] = str_replace(['auth2_reference.', 'public.'], '', $row->definition);
                }
                ksort($catalog);
                $catalogs[$schema] = $catalog;
            }
            $this->assertNotEmpty($catalogs['public'], $kind);
            $this->assertSame($catalogs['public'], $catalogs['auth2_reference'], $kind);
        }
    }

    public function test_invitations_have_fk_unique_expiry_pairing_and_irreversible_consumption(): void
    {
        $card = DB::table('clients')->insertGetId(['coach_user_id' => 5000000000]);
        $id = $this->invitation(['target_client_id' => $card]);
        $this->reject('23505', fn () => $this->invitation(['target_client_id' => $card]));
        $this->reject('23503', fn () => $this->invitation(['coach_user_id' => 999]));
        $this->reject('23503', fn () => $this->invitation(['target_client_id' => 999]));
        $this->reject('23514', fn () => $this->invitation(['token_hash' => 'plaintext']));
        $this->reject('23514', fn () => $this->invitation(['expires_at' => now()->subHour()]));
        $this->reject('23514', fn () => DB::table('client_invitations')->where('id', $id)->update(['consumed_at' => now()]));
        DB::table('client_invitations')->where('id', $id)->update(['consumed_at' => now(), 'consumed_by_user_id' => 5000000000]);
        $this->reject('23514', fn () => DB::table('client_invitations')->where('id', $id)->update(['consumed_at' => null, 'consumed_by_user_id' => null]));
        $this->assertGreaterThan(0, $this->invitation(['target_client_id' => $card]));
    }

    public function test_indefinite_invitations_can_be_consumed_once_or_revoked(): void
    {
        // Омітований expiry теж дає NULL; P3 не встановлює автоматичного TTL.
        $id = DB::table('client_invitations')->insertGetId([
            'token_hash' => hash('sha256', random_bytes(32)),
            'created_by_user_id' => 5000000000, 'coach_user_id' => 5000000000,
            'created_at' => now()->subYears(2),
        ]);
        $this->assertNull(DB::table('client_invitations')->where('id', $id)->value('expires_at'));
        $consume = fn (int $id): int => DB::update('UPDATE client_invitations SET consumed_at=clock_timestamp(), consumed_by_user_id=5000000000
            WHERE id=? AND consumed_at IS NULL AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>clock_timestamp())', [$id]);
        $this->assertSame(1, $consume($id));
        $this->assertSame(0, $consume($id));
        $this->reject('23514', fn () => DB::table('client_invitations')->where('id', $id)->update(['consumed_at' => null, 'consumed_by_user_id' => null]));
        $this->reject('23514', fn () => DB::table('client_invitations')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by_user_id' => 5000000000]));
        $revoked = $this->invitation(['expires_at' => null]);
        DB::table('client_invitations')->where('id', $revoked)->update(['revoked_at' => now(), 'revoked_by_user_id' => 5000000000]);
        $this->assertSame(0, $consume($revoked));
        $this->reject('23514', fn () => DB::table('client_invitations')->where('id', $revoked)->update(['revoked_at' => null, 'revoked_by_user_id' => null]));
    }

    public function test_optional_invitation_expiry_and_mandatory_temporary_proof_ttls(): void
    {
        $created = '2026-01-01 00:00:00+00';
        $expiry = '2026-01-02 00:00:00+00';
        $this->reject('23514', fn () => $this->invitation(['created_at' => $created, 'expires_at' => $created]));
        $this->reject('23514', fn () => $this->invitation(['created_at' => $expiry, 'expires_at' => $created]));
        $id = $this->invitation(['created_at' => $created, 'expires_at' => $expiry]);
        $this->assertSame(0, DB::update('UPDATE client_invitations SET consumed_at=clock_timestamp(), consumed_by_user_id=5000000000
            WHERE id=? AND consumed_at IS NULL AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>clock_timestamp())', [$id]));
        foreach ([$expiry, '2026-01-02 00:00:01+00'] as $time) {
            $this->reject('23514', fn () => DB::table('client_invitations')->where('id', $id)->update(['consumed_at' => $time, 'consumed_by_user_id' => 5000000000]));
        }
        DB::table('client_invitations')->where('id', $id)->update(['consumed_at' => '2026-01-01 23:59:59+00', 'consumed_by_user_id' => 5000000000]);
        $this->assertNotNull(DB::table('client_invitations')->where('id', $id)->value('consumed_at'));
        $this->reject('23502', fn () => $this->flow(['expires_at' => null]));
        $this->reject('23502', fn () => $this->flow(['purpose' => 'mfa_recovery', 'capability_hash' => str_repeat('b', 64), 'expires_at' => null]));
        $flow = $this->flow();
        $this->reject('23502', fn () => DB::table('auth_challenges')->insert(['public_id' => (string) Str::uuid(), 'flow_id' => $flow, 'generation' => 1,
            'email_normalized' => 'test@example.invalid', 'code_mac' => str_repeat('a', 64), 'key_version' => 'test', 'max_attempts' => 5, 'expires_at' => null]));
    }

    public function test_every_new_foreign_key_rejects_missing_parent_and_accepts_bigint(): void
    {
        $invitation = $this->invitation(['consumed_by_user_id' => 5000000000, 'consumed_at' => now()]);
        $this->invitation(['revoked_by_user_id' => 5000000000, 'revoked_at' => now()]);
        $this->flow(['purpose' => 'register', 'invitation_id' => $invitation]);
        $this->factor();
        $baseInvitation = ['token_hash' => hash('sha256', random_bytes(32)), 'created_by_user_id' => 5000000000, 'coach_user_id' => 5000000000, 'created_at' => now()->subMinute(), 'expires_at' => now()->addHour()];
        foreach (['created_by_user_id', 'coach_user_id', 'target_client_id'] as $column) {
            $this->reject('23503', fn () => DB::table('client_invitations')->insert(array_replace($baseInvitation, [$column => 999])));
        }
        foreach (['consumed', 'revoked'] as $terminal) {
            $this->reject('23503', fn () => DB::table('client_invitations')->insert(array_replace($baseInvitation, [$terminal.'_at' => now(), $terminal.'_by_user_id' => 999])));
        }
        $this->reject('23503', fn () => $this->flow(['purpose' => 'register', 'invitation_id' => 999]));
        $this->reject('23503', fn () => DB::table('auth_challenges')->insert(['public_id' => (string) Str::uuid(), 'flow_id' => 999, 'generation' => 1, 'email_normalized' => 'test@example.invalid', 'code_mac' => str_repeat('a', 64), 'key_version' => 'test', 'max_attempts' => 5, 'expires_at' => now()->addHour()]));
        $factor = ['user_id' => 999, 'secret_ciphertext' => (new AuthSecretCodec)->encryptTotp('SYNTHETIC'), 'key_version' => 'test', 'confirmed_at' => now(), 'created_at' => now()->subMinute()];
        $this->reject('23503', fn () => DB::table('user_totp_factors')->insert($factor));
        $this->reject('23503', fn () => DB::table('mfa_recovery_codes')->insert(['user_id' => 999, 'selector' => (string) Str::uuid(), 'batch_id' => (string) Str::uuid(), 'code_hash' => (new AuthSecretCodec)->hashRecoveryCode('synthetic')]));
        DB::table('sessions')->insert(['id' => 'fk_test', 'user_id' => 5000000000, 'payload' => '', 'last_activity' => time()]);
        $this->reject('23503', fn () => DB::table('auth_session_states')->insert(['session_id' => 'fk_test', 'user_id' => 999, 'audience' => 'client', 'browser_binding_hash' => str_repeat('a', 64), 'auth_version' => 0, 'primary_method' => 'email_otp', 'primary_verified_at' => now(), 'absolute_expires_at' => now()->addHour()]));
        foreach (['actor_user_id', 'subject_user_id'] as $column) {
            $this->reject('23503', fn () => DB::table('auth_audit_events')->insert([$column => 999, 'event_type' => 'test', 'outcome' => 'denied', 'request_id' => (string) Str::uuid()]));
        }
        DB::table('auth_audit_events')->insert(['actor_user_id' => 5000000000, 'subject_user_id' => 5000000000, 'event_type' => 'test', 'outcome' => 'success', 'request_id' => (string) Str::uuid()]);
        $this->assertSame(1, DB::table('auth_audit_events')->count());
    }

    public function test_email_preflight_reports_collisions_without_pii_or_writes(): void
    {
        DB::table('users')->insert([
            ['name' => 'one', 'email' => ' Case@example.invalid ', 'email_verified_at' => null],
            ['name' => 'two', 'email' => 'case@example.invalid', 'email_verified_at' => null],
            ['name' => 'unicode', 'email' => 'ї@example.invalid', 'email_verified_at' => null],
        ]);
        DB::table('clients')->insert(['coach_user_id' => 5000000000]);
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        // SET TRANSACTION потребує власної зовнішньої транзакції.
        DB::commit();
        try {
            $this->assertSame(1, Artisan::call('auth:email-preflight'));
            $output = Artisan::output();
            $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($report['read_only']);
            $this->assertTrue($report['application_blocked']);
            $this->assertSame(1, $report['counts']['candidate_collision_groups']);
            $this->assertSame(2, $report['counts']['candidate_collision_users']);
            $this->assertSame(3, $report['counts']['unverified_emails']);
            $this->assertSame(1, $report['counts']['manual_review_emails']);
            $this->assertSame(1, $report['counts']['null_client_cards']);
            $this->assertStringNotContainsString('example.invalid', $output);
            $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        } finally {
            DB::table('clients')->delete();
            DB::table('users')->delete();
            DB::beginTransaction();
        }
    }

    public function test_normalized_email_unique_and_versions_preserve_nullable_legacy_fields(): void
    {
        DB::table('users')->where('id', 5000000000)->update(['email' => 'Case@example.invalid', 'email_normalized' => 'case@example.invalid']);
        $this->reject('23505', fn () => DB::table('users')->insert(['name' => 'collision', 'email' => 'case@example.invalid', 'email_normalized' => 'case@example.invalid']));
        $this->reject('23514', fn () => DB::table('users')->insert(['name' => 'invalid', 'email_normalized' => 'case@example.invalid']));
        $this->reject('23514', fn () => DB::table('users')->where('id', 5000000000)->update(['auth_version' => -1]));
        DB::table('users')->where('id', 5000000000)->update(['auth_version' => 2]);
        $this->reject('23514', fn () => DB::table('users')->where('id', 5000000000)->update(['auth_version' => 1]));
        $row = DB::table('users')->find(5000000000);
        $this->assertNull($row->email_verified_at);
        $this->assertNull($row->password);
        $this->assertSame('admin', $row->role);
        DB::table('users')->insert(['name' => 'null_one']);
        DB::table('users')->insert(['name' => 'null_two']);
    }

    public function test_flow_and_challenge_constraints_and_terminal_states(): void
    {
        $flow = $this->flow();
        $this->reject('23514', fn () => $this->flow(['purpose' => 'register']));
        $this->reject('23514', fn () => $this->flow(['purpose' => 'reauth']));
        $this->reject('23514', fn () => $this->flow(['state' => 'completed']));
        $this->reject('23503', fn () => $this->flow(['user_id' => 999]));
        $challenge = ['flow_id' => $flow, 'public_id' => (string) Str::uuid(), 'generation' => 1,
            'email_normalized' => 'synthetic@example.invalid', 'code_mac' => str_repeat('a', 64),
            'key_version' => 'test', 'max_attempts' => 5, 'created_at' => now()->subMinute(), 'expires_at' => now()->addHour()];
        $id = DB::table('auth_challenges')->insertGetId($challenge);
        $this->reject('23505', fn () => DB::table('auth_challenges')->insert(array_replace($challenge, ['public_id' => (string) Str::uuid()])));
        $this->reject('23514', fn () => DB::table('auth_challenges')->where('id', $id)->update(['attempts' => 6]));
        DB::table('auth_challenges')->where('id', $id)->update(['attempts' => 1]);
        $this->reject('23514', fn () => DB::table('auth_challenges')->where('id', $id)->update(['attempts' => 0]));
        DB::table('auth_challenges')->where('id', $id)->update(['consumed_at' => now()]);
        $this->reject('23514', fn () => DB::table('auth_challenges')->where('id', $id)->update(['consumed_at' => null]));
        DB::table('auth_flows')->where('id', $flow)->update(['state' => 'primary_verified', 'primary_verified_at' => now()]);
        $this->reject('23514', fn () => DB::table('auth_flows')->where('id', $flow)->update(['state' => 'pending']));
        DB::table('auth_flows')->where('id', $flow)->update(['state' => 'completed', 'primary_verified_at' => now(), 'consumed_at' => now()]);
        $this->reject('23514', fn () => DB::table('auth_flows')->where('id', $flow)->update(['state' => 'pending', 'consumed_at' => null]));
    }

    public function test_totp_encrypted_roundtrip_watermark_and_recovery_hashes(): void
    {
        $this->factor();
        $codec = new AuthSecretCodec;
        $stored = DB::table('user_totp_factors')->where('user_id', 5000000000)->first();
        $this->assertStringNotContainsString('SYNTHETICSECRET', $stored->secret_ciphertext);
        $this->assertSame('SYNTHETICSECRET', $codec->decryptTotp($stored->secret_ciphertext));
        $this->reject('23514', fn () => DB::table('user_totp_factors')->where('user_id', 5000000000)->update(['secret_ciphertext' => 'plaintext']));
        DB::table('user_totp_factors')->where('user_id', 5000000000)->update(['last_used_step' => 10000000000]);
        foreach ([null, 9999999999, -1] as $step) {
            $this->reject('23514', fn () => DB::table('user_totp_factors')->where('user_id', 5000000000)->update(['last_used_step' => $step]));
        }
        $hash = $codec->hashRecoveryCode('synthetic-random-code');
        $this->assertTrue(Hash::driver('argon2id')->check('synthetic-random-code', $hash));
        $this->assertFalse(Hash::driver('argon2id')->check('wrong', $hash));
        $id = DB::table('mfa_recovery_codes')->insertGetId(['user_id' => 5000000000, 'selector' => (string) Str::uuid(), 'created_at' => now()->subMinute(), 'code_hash' => $hash, 'batch_id' => (string) Str::uuid()]);
        $this->reject('23514', fn () => DB::table('mfa_recovery_codes')->where('id', $id)->update(['code_hash' => 'plaintext']));
        DB::table('mfa_recovery_codes')->where('id', $id)->update(['used_at' => now()]);
        $this->reject('23514', fn () => DB::table('mfa_recovery_codes')->where('id', $id)->update(['used_at' => null]));
        $token = bin2hex(random_bytes(32));
        $this->assertNotSame($token, $codec->hashInvitationToken($token));
    }

    public function test_session_context_checks_rotation_and_audit_append_only(): void
    {
        $state = ['session_id' => 'synthetic', 'user_id' => 5000000000, 'audience' => 'admin',
            'browser_binding_hash' => str_repeat('a', 64), 'auth_version' => 0, 'primary_method' => 'password',
            'primary_verified_at' => now(), 'absolute_expires_at' => now()->addHour()];
        $this->reject('23514', fn () => DB::table('auth_session_states')->insert($state));
        $state += ['mfa_verified_at' => now(), 'mfa_method' => 'totp'];
        $this->reject('23503', fn () => DB::table('auth_session_states')->insert($state));
        DB::table('sessions')->insert(['id' => 'synthetic', 'user_id' => 5000000000, 'payload' => 'synthetic', 'last_activity' => time()]);
        DB::table('auth_session_states')->insert($state);
        $this->reject('23514', fn () => DB::table('auth_session_states')->where('session_id', 'synthetic')->update(['audience' => 'client']));
        DB::table('auth_session_states')->where('session_id', 'synthetic')->update(['revoked_at' => now()]);
        $this->reject('23514', fn () => DB::table('auth_session_states')->where('session_id', 'synthetic')->update(['revoked_at' => null]));
        DB::table('sessions')->where('id', 'synthetic')->delete();
        $this->assertSame(0, DB::table('auth_session_states')->count());
        $id = DB::table('auth_audit_events')->insertGetId(['actor_user_id' => 5000000000, 'subject_user_id' => 5000000000, 'event_type' => 'mfa.test', 'outcome' => 'denied', 'request_id' => (string) Str::uuid()]);
        $this->reject('23514', fn () => DB::table('auth_audit_events')->where('id', $id)->update(['event_type' => 'changed']));
        $this->reject('23514', fn () => DB::table('auth_audit_events')->delete());
        $this->reject('23514', fn () => DB::statement('TRUNCATE auth_audit_events'));
        $this->reject('23001', fn () => DB::table('users')->where('id', 5000000000)->delete());
    }

    public function test_rollback_refuses_every_nonempty_auth_table_and_user_state(): void
    {
        $this->invitation();
        $flow = $this->flow();
        DB::table('auth_challenges')->insert(['public_id' => (string) Str::uuid(), 'flow_id' => $flow, 'generation' => 1, 'email_normalized' => 'test@example.invalid', 'code_mac' => str_repeat('a', 64), 'key_version' => 'test', 'max_attempts' => 5, 'expires_at' => now()->addHour()]);
        $this->factor();
        DB::table('mfa_recovery_codes')->insert(['user_id' => 5000000000, 'selector' => (string) Str::uuid(), 'code_hash' => (new AuthSecretCodec)->hashRecoveryCode('synthetic'), 'batch_id' => (string) Str::uuid()]);
        DB::table('sessions')->insert(['id' => 'test', 'payload' => '', 'last_activity' => time()]);
        DB::table('auth_session_states')->insert(['session_id' => 'test', 'user_id' => 5000000000, 'audience' => 'client', 'browser_binding_hash' => str_repeat('a', 64), 'auth_version' => 0, 'primary_method' => 'email_otp', 'primary_verified_at' => now(), 'absolute_expires_at' => now()->addHour()]);
        DB::table('auth_audit_events')->insert(['event_type' => 'test', 'outcome' => 'success', 'request_id' => (string) Str::uuid()]);
        DB::table('users')->where('id', 5000000000)->update(['auth_version' => 1]);
        foreach (glob(database_path('migrations/2026_10_22_*.php')) as $file) {
            // Цей fixture містить AUTH-2/5 rows; populated B3/B4 down перевіряють RecoveryTest/SchemaTest.
            if (in_array(basename($file), ['2026_10_22_002000_create_auth_recovery.php', '2026_10_22_003000_create_auth_security_notice_outbox.php', '2026_10_22_004000_create_auth_retention_restore.php'], true)) {
                continue;
            }
            try {
                DB::transaction(fn () => (require $file)->down());
                $this->fail('Відкат мав зупинитися');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Відкат скасовано', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('auth_audit_events')->count());
        $this->assertSame(1, DB::table('user_totp_factors')->count());
    }
}

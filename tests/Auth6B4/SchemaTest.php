<?php

namespace Tests\Auth6B4;

use App\Services\Auth\Notices\NoticeDispatcher;
use App\Services\Auth\Notices\NoticeType;
use App\Services\Auth\Notices\NoticeWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    public function test_authoritative_sql_empty_populated_upgrade_and_down_protection(): void
    {
        require __DIR__.'/../pg/auth2-guard.php';
        $this->assertSame(file_get_contents(base_path('docs/07_auth_security_notice_outbox.sql')), file_get_contents(database_path('sql/auth6b4/00_outbox.sql')));
        DB::beginTransaction();
        try {
            // OutboxTest залишає durable histories; тут перевіряється незалежна synthetic схема.
            DB::statement('CREATE SCHEMA b4_migration');
            DB::statement('SET LOCAL search_path=b4_migration,public');
            DB::statement('CREATE TABLE b4_migration.users (id bigint PRIMARY KEY)');
            DB::statement('CREATE TABLE b4_migration.auth_audit_events (LIKE public.auth_audit_events INCLUDING ALL)');
            DB::unprepared(str_replace('public.', 'b4_migration.', file_get_contents(database_path('sql/auth6b4/00_outbox.sql'))));
            $migration = require database_path('migrations/2026_10_22_003000_create_auth_security_notice_outbox.php');
            $migration->down();
            DB::insert('INSERT INTO b4_migration.users VALUES(9000000001)');
            $sql = str_replace('public.', 'b4_migration.', file_get_contents(database_path('sql/auth6b4/00_outbox.sql')));
            DB::unprepared($sql);
            $this->assertSame(9000000001, DB::table('users')->value('id'));
            DB::insert("INSERT INTO auth_security_notice_outbox(id,request_id,subject_user_id,notice_type,contract_version,recipient_slot,recipient_ciphertext,recipient_key_version,created_at,expires_at,next_attempt_at)
                VALUES(gen_random_uuid(),gen_random_uuid(),9000000001,'mfa_changed',1,'current','synthetic','v1',statement_timestamp(),statement_timestamp()+interval '1 hour',statement_timestamp())");
            try {
                $migration->down();
                $this->fail('Populated down accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('forward fix', $e->getMessage());
            }
            $this->assertSame(1, DB::table('auth_security_notice_outbox')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_writer_and_dispatcher_roles_cannot_bypass_mutation_or_recovery_boundaries(): void
    {
        require __DIR__.'/../pg/auth2-guard.php';
        DB::unprepared(file_get_contents(database_path('sql/auth6b4/01_test_acl.sql')));
        foreach ([
            'telmanfit_notice_writer' => [
                "UPDATE auth_security_notice_outbox SET state='pending'", 'DELETE FROM auth_security_notice_outbox',
                'TRUNCATE auth_security_notice_outbox', 'SELECT auth_notice_claim(1)',
                "SELECT auth_notice_ack(gen_random_uuid(),gen_random_uuid(),'sent')", 'ALTER TABLE auth_security_notice_outbox DISABLE TRIGGER ALL',
                'SELECT auth6b_complete(gen_random_uuid())', 'SET ROLE postgres',
            ],
            'telmanfit_notice_dispatcher' => [
                'INSERT INTO auth_security_notice_outbox(id) VALUES(gen_random_uuid())',
                'UPDATE auth_security_notice_outbox SET attempts=0', 'DELETE FROM auth_security_notice_outbox',
                'TRUNCATE auth_security_notice_outbox', 'ALTER TABLE auth_security_notice_outbox DISABLE TRIGGER ALL',
                'UPDATE users SET auth_version=auth_version+1', 'SELECT auth6b_complete(gen_random_uuid())',
                'SET ROLE postgres',
            ],
        ] as $role => $statements) {
            foreach ($statements as $sql) {
                DB::beginTransaction();
                try {
                    DB::statement('SET LOCAL ROLE '.$role);
                    try {
                        DB::statement($sql);
                        $this->fail('Unexpected privilege');
                    } catch (QueryException $e) {
                        $this->assertSame('42501', $e->errorInfo[0]);
                    }
                } finally {
                    DB::rollBack();
                }
            }
        }
        $subject = DB::table('users')->insertGetId(['name' => 'Synthetic restricted writer', 'role' => 'client']);
        try {
            DB::statement('SET ROLE telmanfit_notice_writer');
            $request = DB::transaction(fn () => app(NoticeWriter::class)->write($subject, NoticeType::MfaChanged, ['current' => 'restricted@example.invalid']));
            $id = DB::table('auth_security_notice_outbox')->where('request_id', $request)->value('id');
            DB::statement('RESET ROLE');
            DB::statement('SET ROLE telmanfit_notice_dispatcher');
            $dispatcher = app(NoticeDispatcher::class);
            $claim = collect($dispatcher->claim(50))->firstWhere('id', $id);
            $this->assertNotNull($claim);
            $this->assertTrue($dispatcher->acknowledge($id, $claim->lease_token, 'sent'));
            $this->assertNull(DB::table('auth_security_notice_outbox')->where('id', $id)->value('recipient_ciphertext'));
        } finally {
            DB::statement('RESET ROLE');
        }
        $this->assertSame(0, DB::selectOne("SELECT count(*) n FROM pg_proc p CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
            WHERE p.proname IN ('auth_notice_claim','auth_notice_ack','auth_notice_audit') AND a.grantee=0 AND a.privilege_type='EXECUTE'")->n);
    }
}

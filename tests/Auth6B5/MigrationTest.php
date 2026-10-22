<?php

namespace Tests\Auth6B5;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MigrationTest extends TestCase
{
    public function test_additive_empty_and_populated_projection_preserves_rows_and_refuses_down(): void
    {
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        try {
            DB::unprepared('CREATE SCHEMA b5_reference; SET LOCAL search_path=b5_reference,public;
                CREATE TABLE users(id bigint PRIMARY KEY);
                CREATE TABLE auth_recovery_principals(id uuid PRIMARY KEY);
                CREATE TABLE auth_recovery_cases(id uuid PRIMARY KEY,target_user_id bigint);');
            DB::statement('INSERT INTO b5_reference.users VALUES(9000000001)');
            $sql = str_replace('public.', 'b5_reference.', file_get_contents(base_path('docs/08_auth_retention_restore.sql')));
            DB::unprepared($sql);
            $this->assertSame(9000000001, DB::table('b5_reference.users')->value('id'));
            $this->assertSame(0, DB::table('b5_reference.auth_security_runtime')->count());
            DB::statement('SET LOCAL search_path=public');
            $queries = [
                "SELECT c.relname||'.'||a.attname n,format_type(a.atttypid,a.atttypmod)||'|'||a.attnotnull v FROM pg_class c JOIN pg_attribute a ON a.attrelid=c.oid WHERE c.relnamespace=?::regnamespace AND c.relname IN ('auth_security_runtime','auth_retention_holds','auth_retention_runs','auth_retention_batches','auth_archive_manifests') AND a.attnum>0 AND NOT a.attisdropped ORDER BY n",
                "SELECT t.relname||'.'||c.conname n,pg_get_constraintdef(c.oid) v FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid WHERE c.connamespace=?::regnamespace AND t.relname IN ('auth_security_runtime','auth_retention_holds','auth_retention_runs','auth_retention_batches','auth_archive_manifests') ORDER BY n",
            ];
            foreach ($queries as $query) {
                $normalize = fn ($schema) => array_map(fn ($r) => [$r->n, str_replace(['public.', 'b5_reference.'], '', $r->v)], DB::select($query, [$schema]));
                $this->assertSame($normalize('public'), $normalize('b5_reference'));
            }
            $migration = require database_path('migrations/2026_10_22_004000_create_auth_retention_restore.php');
            $this->expectException(\RuntimeException::class);
            $migration->down();
        } finally {
            DB::rollBack();
        }
    }
}

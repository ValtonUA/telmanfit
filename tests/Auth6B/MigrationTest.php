<?php

namespace Tests\Auth6B;

use App\Support\Auth\AuthSecretCodec;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    public function test_empty_down_populated_legacy_upgrade_and_authoritative_sql_match(): void
    {
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        try {
            // Історична B3 projection до B5: порожню additive holds table
            // прибираємо тільки всередині synthetic transaction; rollback повертає всі FK.
            $this->assertSame(0, DB::table('auth_retention_holds')->count());
            DB::statement('DROP TABLE auth_retention_holds');
            $migration = require database_path('migrations/2026_10_22_002000_create_auth_recovery.php');
            $this->assertSame(0, DB::table('auth_recovery_principals')->count());
            $this->assertSame(0, DB::table('auth_recovery_cases')->count());
            $migration->down();
            DB::table('users')->insert(['id' => 8999999999, 'name' => 'Synthetic populated upgrade', 'role' => 'admin']);
            DB::table('user_totp_factors')->insert(['user_id' => 8999999999, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp('SYNTHETICFACTOR'),
                'key_version' => 'test_v1', 'confirmed_at' => now()->subMinute(), 'created_at' => now()->subHour(), 'last_used_step' => 100]);
            $before = DB::table('user_totp_factors')->where('user_id', 8999999999)->first();
            $migration->up();
            $this->assertEquals($before, DB::table('user_totp_factors')->where('user_id', 8999999999)->first());
            $this->assertSame(0, DB::table('auth_recovery_principals')->count());
            $this->assertSame(2, DB::selectOne("SELECT count(*) n FROM pg_trigger WHERE tgname IN ('auth6b_flow_gate','auth6b_session_gate')")->n);
            $this->assertSame(3, DB::selectOne("SELECT count(*) n FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey)
                WHERE c.contype='f' AND c.confrelid='users'::regclass AND c.conrelid::regclass::text LIKE 'auth_recovery_%' AND format_type(a.atttypid,a.atttypmod)='bigint'")->n);
            $this->assertSame(file_get_contents(database_path('sql/auth6b/00_recovery.sql')), file_get_contents(base_path('docs/06_auth_recovery_schema.sql')));
            $functions = DB::select("SELECT p.proname,p.prosecdef,p.proconfig FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.proname LIKE 'auth6b_%'");
            $this->assertCount(14, $functions);
            foreach ($functions as $function) {
                $this->assertStringContainsString('search_path=pg_catalog, public', $function->proconfig);
            }
            $this->assertSame(0, DB::selectOne("SELECT count(*) n FROM pg_proc p CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
                WHERE p.proname LIKE 'auth6b_%' AND a.grantee=0 AND a.privilege_type='EXECUTE'")->n);
        } finally {
            DB::rollBack();
        }
    }
}

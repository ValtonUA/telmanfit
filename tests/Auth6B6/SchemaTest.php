<?php

namespace Tests\Auth6B6;

use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\PostgresBudgets;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
    }

    public function test_live_schema_matches_authoritative_sql_catalog(): void
    {
        DB::beginTransaction();
        try {
            DB::statement('CREATE SCHEMA b6_reference');
            DB::unprepared(str_replace('public.', 'b6_reference.', file_get_contents(base_path('docs/09_auth_rate_budgets.sql'))));
            $queries = [
                "SELECT a.attname n,format_type(a.atttypid,a.atttypmod)||'|'||a.attnotnull||'|'||coalesce(pg_get_expr(d.adbin,d.adrelid),'') v FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=?::regclass AND a.attnum>0 ORDER BY n",
                'SELECT conname n,pg_get_constraintdef(oid) v FROM pg_constraint WHERE conrelid=?::regclass ORDER BY n',
                'SELECT indexrelid::regclass::text n,pg_get_indexdef(indexrelid) v FROM pg_index WHERE indrelid=?::regclass ORDER BY n',
            ];
            foreach ($queries as $query) {
                $a = json_encode(DB::select($query, ['public.auth_rate_budgets']));
                $b = json_encode(DB::select($query, ['b6_reference.auth_rate_budgets']));
                $this->assertSame(str_replace('public.', '', $a), str_replace('b6_reference.', '', $b));
            }
            foreach (['auth6b6_policy', 'auth6b6_events', 'auth6b6_validate', 'auth6b6_group', 'auth6b6_cleanup'] as $function) {
                $a = DB::selectOne("SELECT p.prosrc,p.proconfig FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE p.proname=? AND n.nspname='public'", [$function]);
                $b = DB::selectOne("SELECT p.prosrc,p.proconfig FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE p.proname=? AND n.nspname='b6_reference'", [$function]);
                $this->assertSame($a->prosrc, str_replace('b6_reference.', 'public.', $b->prosrc));
                $this->assertSame($a->proconfig, $b->proconfig);
            }
            $migration = require database_path('migrations/2026_10_22_006000_create_auth_rate_budgets.php');
            try {
                $migration->down();
                $this->fail('Unsafe downgrade');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('cannot be reset', $e->getMessage());
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_cleanup_retains_window_and_pause_and_deletes_only_expired_row(): void
    {
        $old = new BudgetKey(BudgetScope::OtpCooldown, bin2hex(random_bytes(16)));
        $active = new BudgetKey(BudgetScope::OtpFailure, bin2hex(random_bytes(16)));
        DB::statement("INSERT INTO auth_rate_budgets(scope,key_hash,last_clock_at,gc_after) VALUES(?,?,clock_timestamp()-interval '2 hours',clock_timestamp()-interval '1 hour')", [$old->scope->value, $old->hash]);
        for ($i = 0; $i < 5; $i++) {
            DB::transaction(fn () => app(PostgresBudgets::class)->failures([$active], true));
        }
        $before = DB::table('auth_rate_budgets')->where('key_hash', $active->hash)->first();
        $this->assertGreaterThanOrEqual(1, app(PostgresBudgets::class)->cleanup());
        $this->assertFalse(DB::table('auth_rate_budgets')->where('key_hash', $old->hash)->exists());
        $this->assertEquals($before, DB::table('auth_rate_budgets')->where('key_hash', $active->hash)->first());
    }
}

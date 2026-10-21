<?php

namespace Tests\Postgres;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class BusinessSchemaTest extends PostgresTestCase
{
    public static function sqlScenarios(): array
    {
        return array_map(fn (string $file): array => [$file], glob(__DIR__.'/../pg/t0[1-5]*.sql'));
    }

    #[DataProvider('sqlScenarios')]
    public function test_reference_sql_scenarios(string $file): void
    {
        $sql = file_get_contents($file);
        // Виконуємо незмінене тіло сценаріїв; psql-команду виводу замінює PHPUnit.
        $body = explode('\\echo', $sql)[0];
        $body = preg_replace('/^BEGIN;\s*$/m', '', $body);
        DB::unprepared($body);
        $results = DB::select('SELECT id, status, detail FROM _r ORDER BY seq');
        $expected = ['t01' => 9, 't02' => 16, 't03' => 24, 't04' => 12, 't05' => 15];
        $this->assertCount($expected[substr(basename($file), 0, 3)], $results);
        foreach ($results as $result) {
            $this->assertSame('PASS', $result->status, "$result->id: $result->detail");
        }
    }

    public function test_schema_inventory_and_no_business_residue(): void
    {
        $tables = $this->businessTables();
        $this->assertCount(37, $tables);
        foreach ($tables as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(19, (int) DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace='public'::regnamespace)")->n);
        $this->assertSame(6, (int) DB::selectOne("SELECT count(*) AS n FROM pg_indexes WHERE schemaname='public' AND indexdef LIKE '%WHERE%'")->n);
        $this->assertSame(4, (int) DB::selectOne("SELECT count(*) AS n FROM pg_indexes WHERE schemaname='public' AND indexdef LIKE 'CREATE UNIQUE INDEX%WHERE%'")->n);
        $this->assertSame(4, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE connamespace='public'::regnamespace AND conname LIKE 'fk_%'")->n);
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE connamespace='public'::regnamespace AND contype='x' AND condeferrable")->n);
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgname='food_has_child_after_insert' AND tgdeferrable AND tginitdeferred")->n);
    }

    public function test_live_catalog_matches_reference_sql_except_approved_r1_changes(): void
    {
        // Еталон застосовується лише в тимчасовій схемі поточної тестової транзакції.
        DB::statement('CREATE SCHEMA stage1_reference');
        DB::statement('SET LOCAL search_path TO stage1_reference, public');
        foreach (glob(base_path('docs/0[1-4]_*.sql')) as $file) {
            $sql = preg_replace('/^(BEGIN|COMMIT);\s*$/m', '', file_get_contents($file));
            DB::unprepared($sql);
        }
        DB::statement('SET LOCAL search_path TO public');

        $queries = [
            'columns' => <<<'SQL'
                SELECT c.relname || '.' || a.attname AS name,
                    format_type(a.atttypid,a.atttypmod) || '|' || a.attnotnull || '|' || a.attidentity::text || '|' || coalesce(pg_get_expr(d.adbin,d.adrelid),'') AS definition
                FROM pg_class c JOIN pg_attribute a ON a.attrelid=c.oid
                LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum
                WHERE c.relnamespace=?::regnamespace AND c.relkind='r' AND a.attnum>0 AND NOT a.attisdropped
                SQL,
            'constraints' => <<<'SQL'
                SELECT c.relname || '.' || k.conname AS name, pg_get_constraintdef(k.oid) AS definition
                FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid
                WHERE c.relnamespace=?::regnamespace
                SQL,
            'indexes' => <<<'SQL'
                SELECT tablename || '.' || indexname AS name, indexdef AS definition
                FROM pg_indexes WHERE schemaname=?
                SQL,
            'triggers' => <<<'SQL'
                SELECT c.relname || '.' || t.tgname AS name, pg_get_triggerdef(t.oid) AS definition
                FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
                WHERE c.relnamespace=?::regnamespace AND NOT t.tgisinternal
                SQL,
            'functions' => <<<'SQL'
                SELECT proname AS name, pg_get_functiondef(oid) AS definition
                FROM pg_proc WHERE pronamespace=?::regnamespace AND prolang=(SELECT oid FROM pg_language WHERE lanname='plpgsql')
                SQL,
        ];
        foreach ($queries as $kind => $query) {
            $catalogs = [];
            foreach (['public', 'stage1_reference'] as $schema) {
                $catalog = [];
                foreach (DB::select($query, [$schema]) as $row) {
                    $table = explode('.', $row->name)[0];
                    if ($kind !== 'functions' && ($table === 'users' || ! in_array($table, $this->businessTables(), true))) {
                        continue;
                    }
                    $definition = str_replace(['stage1_reference.', 'public.'], '', $row->definition);
                    if ($kind === 'columns' && in_array($row->name, ['clients.user_id', 'clients.coach_user_id', 'client_meal_plan_assignments.created_by', 'client_metric_assignments.created_by', 'client_events.actor_user_id'], true)) {
                        $definition = preg_replace('/^integer\|/', 'bigint|', $definition);
                    }
                    $catalog[$row->name] = $definition;
                }
                ksort($catalog);
                $catalogs[$schema] = $catalog;
            }
            $this->assertNotEmpty($catalogs['stage1_reference'], $kind);
            $this->assertSame($catalogs['stage1_reference'], $catalogs['public'], $kind);
        }
    }

    public function test_all_user_foreign_keys_accept_bigint_and_reject_missing_users(): void
    {
        $id = 2147483648;
        DB::table('users')->insert(['id' => $id, 'name' => 'Synthetic coach', 'role' => 'coach']);
        DB::table('users')->insert(['id' => $id + 1, 'name' => 'Synthetic client']);
        $client = DB::table('clients')->insertGetId(['user_id' => $id + 1, 'coach_user_id' => $id]);
        $metric = DB::table('metrics')->insertGetId(['code' => 'test', 'name' => 'Test']);
        DB::table('client_metric_assignments')->insert(['client_id' => $client, 'metric_id' => $metric, 'created_by' => $id]);
        DB::table('client_events')->insert(['client_id' => $client, 'event_type' => 'test', 'actor_user_id' => $id]);

        // Побудова коректного published-плану з наявного перевіреного SQL fixture.
        $fixture = file_get_contents(__DIR__.'/../pg/t04_assignments.sql');
        $fixture = substr($fixture, strpos($fixture, 'INSERT INTO uoms'), strpos($fixture, '-- T4.1') - strpos($fixture, 'INSERT INTO uoms'));
        DB::unprepared('CREATE TEMP TABLE _ids (k text PRIMARY KEY, val integer) ON COMMIT DROP;'.$fixture);
        $version = DB::table('_ids')->where('k', 'ver_pub')->value('val');
        DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $version, 'start_date' => '2026-01-01', 'created_by' => $id]);

        $keys = DB::select("SELECT c.conrelid::regclass::text AS tbl, a.attname AS col, format_type(a.atttypid,a.atttypmod) AS type FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey) WHERE c.contype='f' AND c.confrelid='public.users'::regclass ORDER BY tbl,col");
        $this->assertCount(5, $keys);
        foreach ($keys as $key) {
            $this->assertSame('bigint', $key->type, "$key->tbl.$key->col");
            $this->assertSqlState('23503', fn () => DB::table($key->tbl)->where('id', DB::table($key->tbl)->min('id'))->update([$key->col => $id + 999]));
        }
        $this->assertSqlState('23001', fn () => DB::table('users')->where('id', $id)->delete());
    }

    public function test_users_constraints_and_safe_alignment_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_21_000000_align_users_with_business_schema.php');
        $id = DB::table('users')->insertGetId(['name' => 'Synthetic', 'email' => 'test@example.test', 'password' => 'test']);
        $this->assertSame('client', DB::table('users')->where('id', $id)->value('role'));
        $this->assertSqlState('23514', fn () => DB::table('users')->where('id', $id)->update(['role' => 'invalid']));
        $this->assertSqlState('23505', fn () => DB::table('users')->insert(['name' => 'Duplicate', 'email' => 'test@example.test']));
        foreach ([['role' => 'admin'], ['telegram_user_id' => 999], ['email' => null], ['password' => null]] as $change) {
            DB::transaction(function () use ($migration, $id, $change): void {
                DB::table('users')->where('id', $id)->update($change);
                try {
                    $migration->down();
                    $this->fail('Відкат мав зупинитися без зміни схеми.');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('Відкат скасовано', $e->getMessage());
                    $this->assertSame(1, DB::table('users')->where('id', $id)->count());
                }
                DB::table('users')->where('id', $id)->update(['role' => 'client', 'telegram_user_id' => null, 'email' => 'test@example.test', 'password' => 'test']);
            });
        }
        DB::table('users')->insert(['name' => 'Telegram', 'telegram_user_id' => 123]);
        DB::table('users')->insert(['name' => 'Nullable']);
        $this->assertSqlState('23505', fn () => DB::table('users')->insert(['name' => 'Duplicate Telegram', 'telegram_user_id' => 123]));
        $this->assertTrue(Schema::hasColumns('users', ['remember_token', 'email_verified_at', 'created_at', 'updated_at']));
    }

    public function test_business_migrations_rollback_and_reapply_preserve_existing_user(): void
    {
        $id = DB::table('users')->insertGetId(['name' => 'Existing', 'email' => 'existing@example.test', 'password' => 'test', 'role' => 'admin', 'remember_token' => 'synthetic']);
        $before = (array) DB::table('users')->find($id);
        $files = glob(database_path('migrations/2026_10_21_000[1-5]00_*.php'));
        $this->assertCount(5, $files);
        foreach (array_reverse($files) as $file) {
            (require $file)->down();
        }
        $this->assertSame(['users'], $this->businessTables());
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE pronamespace='public'::regnamespace AND prolang=(SELECT oid FROM pg_language WHERE lanname='plpgsql')")->n);
        foreach ($files as $file) {
            (require $file)->up();
        }
        $this->assertCount(37, $this->businessTables());
        $this->assertSame($before, (array) DB::table('users')->find($id));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }

    public function test_alignment_round_trip_preserves_original_laravel_user(): void
    {
        $id = DB::table('users')->insertGetId(['name' => 'Existing', 'email' => 'existing@example.test', 'password' => 'test', 'remember_token' => 'synthetic']);
        $before = (array) DB::table('users')->find($id);
        $migration = require database_path('migrations/2026_10_21_000000_align_users_with_business_schema.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, (array) DB::table('users')->find($id));
    }

    public function test_real_admin_login_and_panel_role_access(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
        foreach (['admin' => 200, 'coach' => 403, 'client' => 403] as $role => $status) {
            $user = new User;
            $user->forceFill(['name' => 'Synthetic '.$role, 'email' => $role.'@example.test', 'password' => Hash::make('test-password'), 'role' => $role])->save();
            $this->assertTrue(auth()->attempt(['email' => $user->email, 'password' => 'test-password']));
            $this->actingAs($user)->get('/admin')->assertStatus($status);
            auth()->logout();
        }
    }

    private function businessTables(): array
    {
        $framework = ['migrations', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'password_reset_tokens'];
        $names = array_map(fn ($row) => $row->name, DB::select("SELECT relname AS name FROM pg_class WHERE relnamespace='public'::regnamespace AND relkind='r' ORDER BY relname"));

        return array_values(array_diff($names, $framework));
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('SQL мав відхилити операцію: '.$state);
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0], $e->getMessage());
        }
    }
}

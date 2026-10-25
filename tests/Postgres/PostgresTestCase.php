<?php

namespace Tests\Postgres;

use App\Services\Auth\Restore\SyntheticEpoch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class PostgresTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Жодного неявного fallback на .env або реальну БД.
        if (getenv('TELMANFIT_PG_TEST') !== '1'
            || ! preg_match('~^/tmp/telmanfit-stage1\.[a-zA-Z0-9]+/socket$~', (string) getenv('DB_HOST'))
            || getenv('DB_PORT') !== '5432'
            || getenv('DB_DATABASE') !== 'telmanfit_stage1_test'
            || getenv('DB_USERNAME') !== 'telmanfit_test_runner') {
            throw new \RuntimeException('Потрібна явна ізольована Stage 1 БД; див. docs/stage-1.md.');
        }

        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('telmanfit_stage1_test', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('telmanfit_test_runner', DB::selectOne('SELECT current_user AS name')->name);
        $this->assertSame('off', DB::selectOne('SHOW is_superuser')->is_superuser);
        $this->assertFalse(DB::selectOne('SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS privileged FROM pg_roles WHERE rolname=current_user')->privileged);
        $this->assertSame(18, intdiv((int) DB::selectOne('SHOW server_version_num')->server_version_num, 10000));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => array_map(fn ($file) => str_replace(base_path().'/', '', $file), array_merge(glob(database_path('migrations/0001_*.php')), glob(database_path('migrations/2026_10_21_*.php'))))]));
        DB::beginTransaction();
        (require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
    }

    protected function installAuthSchema(): void
    {
        // Gate-aware HTTP/Filament fixture; DDL відкочується outer synthetic transaction.
        if (! Schema::hasTable('auth_recovery_gates')) {
            foreach (glob(database_path('migrations/2026_10_22_*.php')) as $file) {
                (require $file)->up();
            }
        }
        if (! Schema::hasTable('client_profiles')) {
            (require database_path('migrations/2026_10_24_000000_revise_client_profiles.php'))->up();
        }
        DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL, 'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()'), 'state' => 'ready']);
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        parent::tearDown();
    }
}

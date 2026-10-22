<?php

namespace Tests\Auth5;

use App\Support\Auth\AuthSecretCodec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FactorMigrationTest extends TestCase
{
    public function test_additive_upgrade_preserves_populated_factor_and_empty_down_restores_checks(): void
    {
        require __DIR__.'/../pg/auth2-guard.php';
        foreach ([false, true] as $populated) {
            DB::beginTransaction();
            try {
                DB::unprepared('CREATE SCHEMA auth5_upgrade; SET LOCAL search_path TO auth5_upgrade,public;
                    CREATE TABLE users(id bigint PRIMARY KEY, email varchar(255));
                    CREATE TABLE clients(id integer PRIMARY KEY); CREATE TABLE sessions(id varchar(255) PRIMARY KEY);');
                foreach (glob(database_path('sql/auth2/*.sql')) as $file) {
                    DB::unprepared(file_get_contents($file));
                }
                $beforeChecks = DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='auth5_upgrade.user_totp_factors'::regclass ORDER BY conname");
                if ($populated) {
                    DB::table('users')->insert(['id' => 1, 'email' => 'migration@example.invalid']);
                    DB::table('user_totp_factors')->insert(['user_id' => 1, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp('SYNTHETICFACTOR'),
                        'key_version' => 'test_v1', 'created_at' => now()->subMinute(), 'confirmed_at' => now(), 'last_used_step' => 100]);
                    DB::table('mfa_recovery_codes')->insert(['user_id' => 1, 'selector' => (string) Str::uuid(),
                        'code_hash' => app(AuthSecretCodec::class)->hashRecoveryCode('synthetic'), 'batch_id' => (string) Str::uuid()]);
                }
                $before = DB::table('user_totp_factors')->get()->map(fn ($row) => (array) $row)->all();
                $migration = require database_path('migrations/2026_10_22_001000_add_disabled_at_to_user_totp_factors.php');
                $migration->up();
                $after = DB::table('user_totp_factors')->get()->map(function ($row) {
                    $this->assertNull($row->disabled_at);
                    $row = (array) $row;
                    unset($row['disabled_at']);

                    return $row;
                })->all();
                $this->assertSame($before, $after);
                if ($populated) {
                    $this->assertSame(1, DB::table('mfa_recovery_codes')->count());
                    try {
                        $migration->down();
                        $this->fail('Populated auth history rollback accepted');
                    } catch (\RuntimeException $exception) {
                        $this->assertStringContainsString('Відкат скасовано', $exception->getMessage());
                    }
                } else {
                    $migration->down();
                    $this->assertEquals($beforeChecks, DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='auth5_upgrade.user_totp_factors'::regclass ORDER BY conname"));
                }
            } finally {
                DB::rollBack();
            }
        }
        $this->assertStringContainsString(file_get_contents(database_path('sql/auth5/00_disabled_factor.sql')), file_get_contents(base_path('docs/05_auth_schema.sql')));
    }
}

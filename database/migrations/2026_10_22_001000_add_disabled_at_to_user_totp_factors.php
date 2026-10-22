<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/auth5/00_disabled_factor.sql')));
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE user_totp_factors IN ACCESS EXCLUSIVE MODE');
        if (DB::table('user_totp_factors')->exists()) {
            throw new RuntimeException('Відкат скасовано: user_totp_factors містить auth-історію. Потрібен окремий погоджений план.');
        }
        DB::unprepared('DROP TRIGGER auth5_factor_transition ON user_totp_factors;
            DROP FUNCTION auth5_guard_factor();
            ALTER TABLE user_totp_factors DROP CONSTRAINT auth5_factor_secret_state;
            ALTER TABLE user_totp_factors DROP CONSTRAINT auth5_factor_material;
            ALTER TABLE user_totp_factors DROP CONSTRAINT auth5_factor_disabled_state;
            ALTER TABLE user_totp_factors DROP COLUMN disabled_at;
            ALTER TABLE user_totp_factors ADD CONSTRAINT user_totp_factors_check1 CHECK((secret_ciphertext IS NULL) = (confirmed_at IS NULL));
            ALTER TABLE user_totp_factors ADD CONSTRAINT user_totp_factors_check5 CHECK(secret_ciphertext IS NOT NULL OR pending_secret_ciphertext IS NOT NULL);');
    }
};

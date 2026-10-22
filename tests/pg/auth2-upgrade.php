<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/auth2-bootstrap.php';
$files = array_values(array_filter(glob(database_path('migrations/2026_10_22_*.php')), fn ($file) => ! in_array(basename($file), ['2026_10_22_002000_create_auth_recovery.php', '2026_10_22_003000_create_auth_security_notice_outbox.php', '2026_10_22_004000_create_auth_retention_restore.php', '2026_10_22_005000_fix_auth_retention_hold_scrub.php'], true)));
// AUTH-2/5 upgrade projection; B3 має окремий MigrationTest із public-qualified procedures.
assert(count($files) === 9);

// Реальні up/down на порожній та заповненій Stage 1 схемі, без постійних фікстур.
foreach ([false, true] as $populated) {
    DB::beginTransaction();
    try {
        if ($populated) {
            DB::table('users')->insert([
                ['id' => 5000000000, 'name' => 'Синтетичний admin', 'email' => 'Case@example.invalid', 'password' => null, 'role' => 'admin', 'telegram_user_id' => null],
                ['id' => 5000000001, 'name' => 'Синтетичний client', 'email' => 'case@example.invalid', 'password' => null, 'role' => 'client', 'telegram_user_id' => 9000000001],
                ['id' => 5000000002, 'name' => 'Синтетичний NULL', 'email' => null, 'password' => null, 'role' => 'client', 'telegram_user_id' => null],
            ]);
            $id = DB::table('clients')->insertGetId(['user_id' => null, 'coach_user_id' => 5000000000]);
            DB::table('client_nutrition_profiles')->insert(['client_id' => $id, 'goal' => 'Зберегти синтетичні бізнес-дані']);
        }
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table->tablename] = DB::select('SELECT to_jsonb(t)::text AS row FROM "'.$table->tablename.'" t ORDER BY to_jsonb(t)::text');
        }
        foreach ($files as $file) {
            (require $file)->up();
        }
        if (DB::table('users')->whereNotNull('email_normalized')->exists() || DB::table('users')->where('auth_version', '<>', 0)->exists()) {
            throw new RuntimeException('Несподіваний backfill.');
        }
        foreach (array_reverse($files) as $file) {
            (require $file)->down();
        }
        foreach ($snapshot as $table => $before) {
            $after = DB::select('SELECT to_jsonb(t)::text AS row FROM "'.$table.'" t ORDER BY to_jsonb(t)::text');
            if ($before != $after) {
                throw new RuntimeException('Втрата даних: '.$table);
            }
        }
        echo $populated ? "AUTH-2 populated upgrade/down: PASS; усі старі рядки збережені\n" : "AUTH-2 empty upgrade/down: PASS\n";
    } finally {
        DB::rollBack();
    }
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
echo "AUTH-2 Laravel migrate: PASS\n";

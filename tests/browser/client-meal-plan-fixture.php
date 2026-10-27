<?php

use Illuminate\Support\Facades\DB;
use Tests\MealPlans\ClientMenuFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';

$mode = $argv[1] ?? 'setup';
$email = 't52-browser@example.invalid';
if ($mode !== 'setup') {
    $user = DB::table('users')->where('email_normalized', $email)->value('id');
    $client = DB::table('clients')->where('user_id', $user)->value('id');
    if ($mode === 'revoke') {
        DB::table('auth_session_states')->where('user_id', $user)->whereNull('revoked_at')->update(['revoked_at' => DB::raw('clock_timestamp()')]);
    } elseif ($mode === 'empty') {
        DB::table('client_meal_plan_assignments')->where('client_id', $client)->delete();
    } elseif (in_array($mode, ['replace', 'reset'], true)) {
        DB::transaction(function () use ($client, $mode): void {
            DB::table('client_meal_plan_assignments')->where('client_id', $client)->delete();
            $fixture = new class
            {
                use ClientMenuFixture;
            };
            $f = $fixture->menuVersion($mode === 'reset' ? 14 : 7, $mode === 'reset' ? 1 : (int) now()->isoWeekday(), $client);
            DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $f['version'], 'start_date' => now()->toDateString()]);
            if ($mode === 'reset') {
                DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['image_url' => 'https://images.example.com/t52.jpg']);
            }
        });
    } else {
        throw new RuntimeException('Невідома synthetic дія.');
    }
    echo "T5.2 fixture action complete\n";
    exit;
}
DB::transaction(function () use ($email): void {
    $coach = DB::table('users')->insertGetId(['name' => 'Тестовий тренер', 'role' => 'admin']);
    $user = DB::table('users')->insertGetId(['name' => 'Тестовий клієнт', 'email' => $email, 'email_normalized' => $email, 'email_verified_at' => now()]);
    $client = DB::table('clients')->insertGetId(['user_id' => $user, 'coach_user_id' => $coach]);
    $fixture = new class
    {
        use ClientMenuFixture;
    };
    $f = $fixture->menuVersion(14);
    DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $f['version'], 'start_date' => now()->toDateString()]);
    // HTTPS logical cover: browser test окремо перевіряє успішне й пошкоджене фото.
    DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['image_url' => 'https://images.example.com/t52.jpg']);
});
echo "T5.2 synthetic browser fixture ready\n";

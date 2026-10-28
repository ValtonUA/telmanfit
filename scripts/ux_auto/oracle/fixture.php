<?php

// Лише ізольована PG18: bootstrap/guard скопійовано з pinned checkout у /fixture.
use App\Services\Auth\Restore\SyntheticEpoch;
use Illuminate\Support\Facades\DB;
use Tests\MealPlans\PlanFixture;

require '/fixture/tests/pg/auth2-bootstrap.php';
$mode = $argv[1] ?? 'seed';
if (in_array($mode, ['runtime', 'seed'], true)) {
    DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL,
        'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()'), 'state' => 'ready']);
}
if ($mode === 'runtime') {
    exit;
}
if ($mode === 'seed') {
    foreach (['breakfast' => 'Сніданок', 'lunch' => 'Обід', 'dinner' => 'Вечеря', 'snack' => 'Перекус'] as $code => $title) {
        DB::table('meal_types')->insertOrIgnore(compact('code', 'title'));
    }
    $admin = DB::table('users')->where('email', 'auth5-admin@example.invalid')->sole();
    $fixture = new class
    {
        use PlanFixture;

        public function make(int $id): array
        {
            return $this->populatedPlan($id);
        }
    };
    $historical = DB::transaction(fn () => $fixture->make($admin->id));
    $plan = DB::table('meal_plans')->insertGetId(['title' => 'Oracle draft', 'week_start_weekday' => 1]);
    $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
    $days = [];
    for ($n = 1; $n <= 7; $n++) {
        $day = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $n]);
        $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $day, 'meal_type_id' => $historical['type']]);
        $days[] = ['day' => $day, 'meal' => $meal];
    }
    // Непорожня ціль для preview/cancel; UX-5 починає з порожнього першого дня.
    $rows = DB::table('meal_items')->where('meal_id', $historical['meal'])->orderBy('sort_order')->get()->all();
    $selected = DB::table('meal_items')->whereNotNull('recipe_version_id')->first();
    if ($selected === null) {
        throw new RuntimeException('Oracle потребує synthetic exact Model I fixture');
    }
    $rows[] = $selected;
    foreach ($rows as $order => $item) {
        $row = array_intersect_key((array) $item, array_flip(['food_id', 'quantity', 'uom_id',
            'resolved_quantity_grams', 'recipe_version_id', 'recipe_batch_format_id', 'recipe_portion_divisor']));
        $row['meal_id'] = $days[1]['meal'];
        $row['sort_order'] = $order;
        DB::table('meal_items')->insert($row);
    }
    echo json_encode(['version' => $version, 'days' => $days, 'historical' => $historical['version'],
        'food' => $historical['food'], 'g' => $historical['g']]);
    exit;
}
if ($mode === 'revoke') {
    $ids = DB::table('users')->whereIn('email', ['auth5-admin@example.invalid', 't52-browser@example.invalid'])->pluck('id');
    DB::table('auth_session_states')->whereIn('user_id', $ids)->whereNull('revoked_at')->update(['revoked_at' => DB::raw('clock_timestamp()')]);
    exit;
}
if ($mode === 'touch') {
    $meta = json_decode(file_get_contents('/tmp/oracle-meta.json'), true, flags: JSON_THROW_ON_ERROR);
    DB::table('meal_items')->where('meal_id', $meta['days'][1]['meal'])->where('food_id', $meta['food'])->update(['quantity' => '3', 'resolved_quantity_grams' => '3']);
    exit;
}
throw new RuntimeException('Невідома oracle fixture дія');

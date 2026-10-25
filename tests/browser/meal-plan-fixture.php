<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\MealPlans\PlanFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';
if (($argv[1] ?? '') === 'seed') {
    $fixture = new class
    {
        use PlanFixture;

        public function make(int $actor): array
        {
            return $this->populatedPlan($actor);
        }
    };
    $actor = User::where('email', 'recipe-browser@example.invalid')->sole();
    echo json_encode(DB::transaction(function () use ($fixture, $actor): array {
        $data = $fixture->make($actor->id);
        $data['extra_type'] = DB::table('meal_types')->insertGetId(['code' => 'synthetic-structure-browser-'.bin2hex(random_bytes(4)), 'title' => 'Синтетичний додатковий тип']);

        return $data;
    }));
    exit;
}
if (($argv[1] ?? '') === 'hash') {
    $state = [];
    $plans = DB::table('meal_plans')->where('title', 'Синтетичний історичний раціон')->pluck('id');
    $versions = DB::table('meal_plan_versions')->whereIn('meal_plan_id', $plans)->pluck('id');
    $days = DB::table('meal_plan_days')->whereIn('meal_plan_version_id', $versions)->pluck('id');
    $meals = DB::table('meals')->whereIn('meal_plan_day_id', $days)->pluck('id');
    $foods = DB::table('meal_items')->whereIn('meal_id', $meals)->pluck('food_id');
    foreach (['meal_plans' => ['id', $plans], 'meal_plan_versions' => ['id', $versions],
        'meal_plan_days' => ['id', $days], 'meals' => ['id', $meals], 'meal_items' => ['meal_id', $meals],
        'client_meal_plan_assignments' => ['meal_plan_version_id', $versions], 'foods' => ['id', $foods],
        'ingredients' => ['food_id', $foods], 'recipe_versions' => ['food_id', $foods]] as $table => [$column, $ids]) {
        $state[$table] = DB::table($table)->whereIn($column, $ids)->orderBy('id')->get()->all();
    }
    echo hash('sha256', json_encode($state));
    exit;
}
if (($argv[1] ?? '') === 'structure-hash') {
    $state = [];
    foreach (['meal_plan_days', 'meals', 'meal_items'] as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    echo hash('sha256', json_encode($state));
    exit;
}
if (($argv[1] ?? '') === 'count') {
    echo DB::table('meal_plans')->count();
    exit;
}
throw new RuntimeException('Невідома synthetic дія');

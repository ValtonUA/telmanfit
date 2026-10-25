<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\MealPlans\PlanFixture;
use Tests\Recipes\BatchFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';
if (($argv[1] ?? '') === 'seed') {
    $fixture = new class
    {
        use BatchFixture, PlanFixture;

        public function publishedBatch(): array
        {
            $b = $this->batch();
            DB::table('recipe_versions')->where('id', $b['version'])->update(['title' => 'Синтетичний batch writer', 'lock_version' => DB::raw('lock_version+1')]);
            $this->publish($b['version']);

            return $b;
        }

        public function make(int $actor): array
        {
            return $this->populatedPlan($actor);
        }
    };
    $actor = User::where('email', 'recipe-browser@example.invalid')->sole();
    echo json_encode(DB::transaction(function () use ($fixture, $actor): array {
        $data = $fixture->make($actor->id);
        $data['batch'] = $fixture->publishedBatch();
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
// Лише синтетичний disposable PG18 fixture; не є meal-item writer застосунку.
if (in_array($argv[1] ?? '', ['week-state', 'week-fill'], true)) {
    $version = DB::table('meal_plan_versions')->where('id', (int) ($argv[2] ?? 0))->where('status', 'draft')->first();
    if (! $version) {
        throw new RuntimeException('Потрібна synthetic draft version');
    }
    if ($argv[1] === 'week-fill') {
        $day = DB::table('meal_plan_days')->where('meal_plan_version_id', $version->id)->where('day_number', (int) ($argv[3] ?? 0))->sole();
        $meal = DB::table('meals')->where('meal_plan_day_id', $day->id)->orderBy('id')->first();
        $ingredientFood = DB::table('ingredients')->value('food_id');
        $recipeFood = DB::table('recipe_versions')->where('status', 'published')->whereNotNull('food_id')->value('food_id');
        DB::transaction(function () use ($meal, $ingredientFood, $recipeFood): void {
            DB::table('meal_items')->insert([
                ['meal_id' => $meal->id, 'food_id' => $ingredientFood, 'quantity' => '1.00', 'uom_id' => DB::table('uoms')->where('code', 'g')->value('id'), 'resolved_quantity_grams' => '1.00'],
                ['meal_id' => $meal->id, 'food_id' => $recipeFood, 'quantity' => '1.00', 'uom_id' => DB::table('uoms')->where('code', 'serving')->value('id'), 'resolved_quantity_grams' => null],
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }
    $days = DB::table('meal_plan_days')->where('meal_plan_version_id', $version->id)->orderBy('day_number')->get();
    $meals = DB::table('meals')->whereIn('meal_plan_day_id', $days->pluck('id'))->orderBy('id')->get();
    echo json_encode(['days' => $days, 'meals' => $meals, 'items' => DB::table('meal_items')->whereIn('meal_id', $meals->pluck('id'))->orderBy('id')->get()]);
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

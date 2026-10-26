<?php

use App\Models\Meal;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
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
if (in_array($argv[1] ?? '', ['editor-seed', 'polish-seed', 'repeat-seed', 'repeat-single-seed'], true)) {
    $polish = $argv[1] === 'polish-seed';
    $actor = User::where('email', 'recipe-browser@example.invalid')->sole();
    $writer = app(MealItemWriter::class);
    $structure = app(MealPlanStructure::class);
    $version = app(MealPlanFoundation::class)->create($actor, ['title' => 'Синтетичний UX-4E · повний тиждень', 'week_start_weekday' => $polish ? 2 : 1])->versions()->sole();
    $types = DB::table('meal_types')->whereIn('id', [(int) $argv[2], (int) $argv[3]])->pluck('id')->all();
    foreach ($argv[1] === 'repeat-single-seed' ? [1] : [1, 8] as $start) {
        $structure->generate($actor, $version, ['start_day' => $start, 'meal_type_ids' => $types]);
    }
    // Repeat перевіряє копії наявного food, не додає однойменні результати у catalog pagination інших сценаріїв.
    $denseFood = in_array($argv[1], ['repeat-seed', 'repeat-single-seed'], true) ? (int) $argv[4] : DB::transaction(function (): int {
        $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
        DB::table('ingredients')->insert(['food_id' => $food, 'name' => 'Тестова вівсяна каша з ягодами, горіхами та натуральним йогуртом — довга назва для щільного огляду', 'kcal_per_100g' => '100.00', 'protein_per_100g' => '10.00', 'fat_per_100g' => '1.00', 'carbs_per_100g' => '2.00']);

        return $food;
    });
    $brokenFood = null;
    if ($polish) {
        DB::table('ingredients')->where('food_id', $denseFood)->update(['image_url' => 'https://images.example.com/cover.png']);
        $brokenFood = DB::transaction(function (): int {
            $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
            DB::table('ingredients')->insert(['food_id' => $food, 'name' => 'Тестове недоступне фото', 'image_url' => 'https://images.example.com/broken.png', 'kcal_per_100g' => '100.00', 'protein_per_100g' => '10.00', 'fat_per_100g' => '1.00', 'carbs_per_100g' => '2.00']);

            return $food;
        });
    }
    foreach ($version->days()->where('day_number', '<=', 7)->with('meals')->get() as $day) {
        foreach ($day->meals as $meal) {
            foreach (($polish && $day->day_number === 1 ? array_fill(0, 20, '2.50') : ['1.00', '2.50', '3.75']) as $order => $quantity) {
                $food = $polish ? [$denseFood, $brokenFood, (int) $argv[4]][$order % 3] : ($order === 1 ? $denseFood : (int) $argv[4]);
                $catalog = $writer->catalog($actor, $version, $food);
                $writer->save($actor, $version, ['meal_id' => $meal->id, 'food_id' => $food, 'quantity' => $quantity, 'uom_id' => $catalog['default'], 'sort_order' => $order,
                    'expected' => $writer->mealToken($actor, $version, $meal->id), 'catalog_expected' => $catalog['token']]);
            }
        }
    }
    $days = $version->days()->orderBy('day_number')->get();
    $day = $days->first();
    echo json_encode(['photo_food' => $denseFood, 'broken_food' => $brokenFood, 'version' => $version->id, 'day' => $day->id, 'day8' => $days->get(7)?->id, 'meals' => $day->meals()->orderBy('id')->pluck('id')->all()]);
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
if (in_array($argv[1] ?? '', ['week-state', 'week-fill', 'week-edit-empty'], true)) {
    $version = DB::table('meal_plan_versions')->where('id', (int) ($argv[2] ?? 0))->where('status', 'draft')->first();
    if (! $version) {
        throw new RuntimeException('Потрібна synthetic draft version');
    }
    if ($argv[1] === 'week-edit-empty') {
        $day = DB::table('meal_plan_days')->where('meal_plan_version_id', $version->id)->where('day_number', (int) $argv[3])->sole();
        $meal = Meal::where('meal_plan_day_id', $day->id)->orderBy('id')->firstOrFail();
        if ($meal->items()->exists()) {
            throw new RuntimeException('Потрібен synthetic прийом без позицій');
        }
        app(MealPlanStructure::class)->saveMeal(User::where('email', 'recipe-browser@example.invalid')->sole(),
            MealPlanVersion::findOrFail($version->id), ['day_id' => $day->id, 'meal_type_id' => $meal->meal_type_id,
                'title' => (string) $argv[4], 'sort_order' => (int) $argv[5], 'expected' => MealPlanStructure::token($meal)], $meal->id);
    }
    if ($argv[1] === 'week-fill') {
        $day = DB::table('meal_plan_days')->where('meal_plan_version_id', $version->id)->where('day_number', (int) ($argv[3] ?? 0))->sole();
        $meal = DB::table('meals')->where('meal_plan_day_id', $day->id)->orderBy('id')->first();
        $ingredientFood = DB::table('ingredients')->value('food_id');
        // Цей fixture перевіряє legacy serving; batch має окремі explicit-selector сценарії.
        $recipeFood = DB::table('recipe_versions')->where('status', 'published')->where('editor_contract', 'legacy_v1')->whereNotNull('food_id')->orderBy('id')->value('food_id');
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
if (($argv[1] ?? '') === 'plan-weekday') {
    echo DB::table('meal_plans')->join('meal_plan_versions as v', 'v.meal_plan_id', '=', 'meal_plans.id')->where('v.id', (int) $argv[2])->value('week_start_weekday');
    exit;
}
if (($argv[1] ?? '') === 'count') {
    echo DB::table('meal_plans')->count();
    exit;
}
throw new RuntimeException('Невідома synthetic дія');

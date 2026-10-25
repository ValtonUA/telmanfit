<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\Pages\EditMealPlanDay;
use App\Models\Catalog\Ingredient;
use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Catalog\RecipeCoverWriter;
use App\Services\MealPlans\MealItemResolver;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
use App\Support\Presentation\FoodCards;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\PlanFixture;
use Tests\Recipes\BatchFixture;

class MealPlanPresentationTest extends PostgresTestCase
{
    use BatchFixture, PlanFixture;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic presentation admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function rejected(callable $command, string $exception): void
    {
        DB::beginTransaction();
        try {
            $command();
            $this->fail('Операцію потрібно відхилити');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        } finally {
            DB::rollBack();
        }
    }

    private function frozen(): string
    {
        $state = [];
        foreach (['recipe_versions', 'recipe_ingredients', 'recipe_batch_formats', 'recipe_batch_quantities', 'recipe_directions', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments'] as $table) {
            $state[$table] = DB::table($table)->orderByRaw('to_jsonb('.$table.')::text')->get()->all();
        }

        return hash('sha256', json_encode($state));
    }

    public function test_creation_default_explicit_weekday_and_database_guards(): void
    {
        $service = app(MealPlanFoundation::class);
        $default = $service->create($this->actor, ['title' => 'Типовий']);
        $tuesday = $service->create($this->actor, ['title' => 'Вівторок', 'week_start_weekday' => 2]);
        $this->assertSame(1, $default->week_start_weekday);
        $this->assertSame(2, $tuesday->week_start_weekday);
        foreach ([0, 8, null, 'bad'] as $value) {
            $this->rejected(fn () => $service->create($this->actor, ['title' => 'Невалідний', 'week_start_weekday' => $value]), ValidationException::class);
        }
        foreach ([0, 8, null] as $value) {
            $this->rejected(fn () => DB::table('meal_plans')->insert(['title' => 'Невалідний', 'week_start_weekday' => $value]), QueryException::class);
        }
        $this->rejected(fn () => DB::table('meal_plans')->where('id', $tuesday->id)->update(['week_start_weekday' => 1]), QueryException::class);
        $type = DB::table('meal_types')->insertGetId(['code' => 'weekday-test', 'title' => 'Тест']);
        $version = $tuesday->versions()->sole();
        foreach ([1, 8] as $start) {
            app(MealPlanStructure::class)->generate($this->actor, $version, ['start_day' => $start, 'meal_type_ids' => [$type]]);
        }
        $page = Livewire::test(EditMealPlanDay::class, ['record' => $version->id, 'day' => $version->days()->where('day_number', 8)->value('id')]);
        $page->assertSee('День 8 (Вт)');
        $this->assertSame(2, $tuesday->fresh()->week_start_weekday);
    }

    public function test_nonempty_upgrade_and_logical_covers_preserve_published_history_and_source_hashes(): void
    {
        // Лише disposable PG18: відтворюємо legacy shape до SQL15 на заповненій БД.
        DB::statement('ALTER TABLE ingredients DROP COLUMN image_url');
        DB::statement('ALTER TABLE recipes DROP COLUMN image_url');
        DB::statement('DROP TRIGGER plan_week_start_immutable ON meal_plans');
        DB::statement('ALTER TABLE meal_plans DROP COLUMN week_start_weekday');
        $f = $this->populatedPlan($this->actor->id);
        DB::table('uoms')->where('code', 'g')->update(['label' => 'gram']);
        $b = $this->batch();
        DB::table('recipe_directions')->insert(['recipe_version_id' => $b['version'], 'direction_number' => 1, 'direction_text' => 'Синтетичний крок', 'image_url' => 'https://images.example.com/step.png']);
        $this->publish($b['version']);
        $legacyFood = DB::table('meal_items')->where('meal_id', $f['meal'])->where('food_id', '!=', $f['food'])->value('food_id');
        $legacy = DB::table('recipe_versions')->where('food_id', $legacyFood)->first();
        $second = DB::table('recipe_versions')->insertGetId(['recipe_id' => $legacy->recipe_id, 'title' => 'Інша точна версія']);
        DB::table('recipe_ingredients')->insert(['recipe_version_id' => $second, 'ingredient_id' => $f['ingredient'], 'quantity' => '100.00', 'uom_id' => $f['g'], 'quantity_grams' => '100.00']);
        $secondFood = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
        DB::table('recipe_versions')->where('id', $second)->update(['status' => 'published', 'food_id' => $secondFood, 'version_number' => 2, 'published_at' => now(), 'kcal_per_serving' => '100.00', 'protein_per_serving' => '10.00', 'fat_per_serving' => '1.00', 'carbs_per_serving' => '2.00']);
        $before = $this->frozen();
        $hash = DB::selectOne('SELECT recipe_batch_source_hash(?) hash', [$f['ingredient']])->hash;
        (require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
        $this->assertSame($before, $this->frozen());
        $this->assertSame(1, DB::table('meal_plans')->where('id', $f['plan'])->value('week_start_weekday'));
        $this->assertSame($hash, DB::selectOne('SELECT recipe_batch_source_hash(?) hash', [$f['ingredient']])->hash);
        $recipeId = DB::table('recipe_versions')->where('id', $b['version'])->value('recipe_id');
        $food = DB::table('recipe_versions')->where('id', $b['version'])->value('food_id');
        $writer = app(RecipeCoverWriter::class);
        config(['presentation.image_hosts' => []]);
        $url = 'https://images.example.com/cover.png';
        $this->assertNull(app(FoodCards::class)->lookup([$food])[$food]['image']);
        $this->rejected(fn () => $writer->save($this->actor, $recipeId, $url, $writer->token($this->actor, $recipeId)), ValidationException::class);
        config(['presentation.image_hosts' => ['images.example.com']]);
        foreach (['javascript:alert(1)', 'https://images.example.com/a.svg', 'https://user@images.example.com/a.png', 'https://images.example.com/" onerror="x'] as $invalid) {
            $this->rejected(fn () => $writer->save($this->actor, $recipeId, $invalid, $writer->token($this->actor, $recipeId)), ValidationException::class);
        }
        $writer->save($this->actor, $legacy->recipe_id, $url, $writer->token($this->actor, $legacy->recipe_id));
        $cards = app(FoodCards::class)->lookup([$legacyFood, $secondFood]);
        $this->assertSame($url, $cards[$legacyFood]['image']);
        $this->assertSame($url, $cards[$secondFood]['image']);
        $this->assertSame('Інша точна версія', $cards[$secondFood]['label']);
        $this->assertSame($legacy->title, $cards[$legacyFood]['label']);
        $stale = $writer->token($this->actor, $recipeId);
        $writer->save($this->actor, $recipeId, $url, $stale);
        $this->assertSame($url, app(FoodCards::class)->lookup([$food])[$food]['image']);
        $this->assertSame('https://images.example.com/step.png', DB::table('recipe_directions')->where('recipe_version_id', $b['version'])->value('image_url'));
        $this->rejected(fn () => $writer->save($this->actor, $recipeId, null, $stale), ValidationException::class);
        DB::table('ingredients')->where('id', $f['ingredient'])->update(['image_url' => $url]);
        $this->assertSame($url, app(FoodCards::class)->lookup([$f['food']])[$f['food']]['image']);
        $this->assertSame($hash, DB::selectOne('SELECT recipe_batch_source_hash(?) hash', [$f['ingredient']])->hash);
        $this->assertSame($before, $this->frozen());
        $this->rejected(function () use ($writer, $recipeId): void {
            DB::table('auth_security_runtime')->where('id', 1)->update(['restore_epoch' => (string) Str::uuid()]);
            $writer->save($this->actor, $recipeId, null, $writer->token($this->actor, $recipeId));
        }, HttpException::class);
        $writer->save($this->actor, $recipeId, '', $writer->token($this->actor, $recipeId));
        $this->assertNull(app(FoodCards::class)->lookup([$food])[$food]['image']);
        $writer->save($this->actor, $recipeId, $url, $writer->token($this->actor, $recipeId));
        $this->assertSame($before, $this->frozen());
        fwrite(STDERR, 'Presentation upgrade/frozen hash='.$before.' unchanged; source_hash='.$hash.' unchanged; exact shared covers + step images preserved'.PHP_EOL);
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
        $this->rejected(fn () => $writer->save($this->actor, $recipeId, null, $writer->token($this->actor, $recipeId)), HttpException::class);
        $this->assertSame($before, $this->frozen());
    }

    public function test_unflagged_admin_cover_action_and_row_edit_delete_preload(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $version = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Metadata actions'])->versions()->sole();
        app(MealPlanStructure::class)->generate($this->actor, $version, ['start_day' => 1, 'meal_type_ids' => [$f['type']]]);
        $day = $version->days()->orderBy('day_number')->first();
        $meal = $day->meals()->sole();
        $item = DB::table('meal_items')->insertGetId(['meal_id' => $meal->id, 'food_id' => $f['food'], 'quantity' => '0.50', 'uom_id' => $f['g'], 'resolved_quantity_grams' => '0.50']);
        $recipe = DB::table('recipes')->value('id');
        $ingredient = Ingredient::findOrFail($f['ingredient']);
        $catalog = app(CatalogWriter::class);
        $input = $ingredient->getAttributes() + ['conversions' => []];
        config(['presentation.image_hosts' => ['images.example.com']]);
        $ingredient = $catalog->save($this->actor, $ingredient, array_replace($input, ['image_url' => 'https://images.example.com/ingredient.png']), $catalog->revision($ingredient));
        $this->assertSame('https://images.example.com/ingredient.png', app(FoodCards::class)->lookup([$f['food']])[$f['food']]['image']);
        $ingredient = $catalog->save($this->actor, $ingredient, array_replace($input, ['image_url' => '']), $catalog->revision($ingredient));
        $this->assertNull($ingredient->image_url);
        $frozen = $this->frozen();
        $this->assertFalse(config('recipes.batch_editor_enabled'));
        config(['presentation.image_hosts' => ['images.example.com']]);
        $page = Livewire::test(EditMealPlanDay::class, ['record' => $version->id, 'day' => $day->id]);
        $page->mountAction('recipeCover')->set('mountedActions.0.data.recipe_id', $recipe)
            ->set('mountedActions.0.data.image_url', 'https://images.example.com/cover.png')
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('https://images.example.com/cover.png', DB::table('recipes')->where('id', $recipe)->value('image_url'));
        $this->assertSame($frozen, $this->frozen());
        $page->mountAction('editItem', ['item' => $item])->assertActionDataSet(['item_id' => $item, 'quantity' => '0.50', 'food_id' => $f['food']])
            ->set('mountedActions.0.data.quantity', '1.25')->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('1.25', DB::table('meal_items')->where('id', $item)->value('quantity'));
        $page->mountAction('deleteItem', ['item' => $item])->assertActionDataSet(['item_id' => $item])->unmountAction();
        $this->assertSame(1, DB::table('meal_items')->where('id', $item)->count());
    }

    public function test_dense_day_bulk_resolver_is_exact_and_query_count_does_not_grow(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $v = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Щільний день'])->versions()->sole();
        app(MealPlanStructure::class)->generate($this->actor, $v, ['start_day' => 1, 'meal_type_ids' => [$f['type']]]);
        $day = $v->days()->orderBy('day_number')->first();
        $meal = $day->meals()->sole();
        $renderCounts = [];
        foreach ([10, 40] as $count) {
            $existing = DB::table('meal_items')->where('meal_id', $meal->id)->count();
            for ($i = $existing; $i < $count; $i++) {
                DB::table('meal_items')->insert(['meal_id' => $meal->id, 'food_id' => $f['food'], 'quantity' => '0.50', 'uom_id' => $f['g'], 'resolved_quantity_grams' => '0.50', 'sort_order' => $i]);
            }
            $ids = DB::table('meal_items')->where('meal_id', $meal->id)->pluck('id')->all();
            $resolver = app(MealItemResolver::class);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $scalar = collect($ids)->mapWithKeys(fn ($id) => [$id => $resolver->resolve($id)]);
            $scalarCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $start = hrtime(true);
            $bulk = $resolver->resolveMany($ids);
            $bulkMs = (hrtime(true) - $start) / 1e6;
            $this->assertCount(1, DB::getQueryLog());
            $this->assertEquals($scalar, $bulk);
            DB::flushQueryLog();
            $start = hrtime(true);
            Livewire::test(EditMealPlanDay::class, ['record' => $v->id, 'day' => $day->id])->assertSuccessful();
            $renderMs = (hrtime(true) - $start) / 1e6;
            $renderCounts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            fwrite(STDERR, sprintf("Presentation benchmark items=%d scalar_queries=%d bulk_queries=1 bulk_ms=%.2f render_queries=%d render_ms=%.2f\n", $count, $scalarCount, $bulkMs, end($renderCounts), $renderMs));
        }
        $this->assertSame($renderCounts[0], $renderCounts[1]);
        $this->assertLessThan(300, $renderCounts[1]);
    }
}

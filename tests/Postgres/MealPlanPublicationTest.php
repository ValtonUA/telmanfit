<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Filament\Resources\MealPlans\Pages\EditMealPlanDay;
use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\MealItem;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemResolver;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanPublisher;
use App\Services\MealPlans\MealPlanStructure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\PlanFixture;
use Tests\Recipes\BatchFixture;

class MealPlanPublicationTest extends PostgresTestCase
{
    use BatchFixture, PlanFixture;

    private User $actor;

    private MealPlanVersion $version;

    private array $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic publication admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        DB::table('uoms')->insert(['code' => 'g', 'label' => 'gram', 'kind' => 'mass']);
        $this->history = $this->populatedPlan($this->actor->id);
        $this->version = $this->legacyEmptyPlan('Публікація')->versions()->sole();
        $this->generate(1);
        foreach ($this->version->days()->with('meals')->get() as $day) {
            $this->add($day->meals->sole()->id);
        }
    }

    private function generate(int $start): void
    {
        app(MealPlanStructure::class)->generate($this->actor, $this->version, ['start_day' => $start, 'meal_type_ids' => [$this->history['type']]]);
    }

    private function add(int $meal): MealItem
    {
        $writer = app(MealItemWriter::class);
        $catalog = $writer->catalog($this->actor, $this->version, $this->history['food']);

        return $writer->save($this->actor, $this->version, ['meal_id' => $meal, 'food_id' => $this->history['food'], 'quantity' => '12.34',
            'uom_id' => $this->history['g'], 'sort_order' => -3, 'expected' => $writer->mealToken($this->actor, $this->version, $meal), 'catalog_expected' => $catalog['token']]);
    }

    private function publisher(): MealPlanPublisher
    {
        return app(MealPlanPublisher::class);
    }

    public function test_publish_preserves_exact_model_i_and_all_history(): void
    {
        $batch = $this->batch();
        $this->publish($batch['version']);
        $meal = $this->version->days()->first()->meals()->sole()->id;
        $serving = DB::table('uoms')->where('code', 'serving')->value('id');
        $legacy = DB::table('recipe_versions')->where('editor_contract', 'legacy_v1')->where('status', 'published')->sole();
        DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $legacy->food_id, 'quantity' => '0.25', 'uom_id' => $serving]);
        foreach ([['single', 1], ['week', 7], ['half_week', 3], ['half_week', 4]] as [$code, $divisor]) {
            DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id'),
                'quantity' => '0.50', 'uom_id' => $serving, 'recipe_version_id' => $batch['version'], 'recipe_batch_format_id' => $batch['formats'][$code], 'recipe_portion_divisor' => $divisor]);
        }
        $before = $this->protectedState();
        $ids = DB::table('meal_items')->pluck('id')->all();
        $nutrition = app(MealItemResolver::class)->resolveMany($ids)->toJson();
        $result = $this->publisher()->publish($this->actor, $this->version, $this->publisher()->preview($this->actor, $this->version));
        $this->assertSame('published', $result->status);
        $this->assertSame(1, $result->version_number);
        $this->assertNotNull($result->published_at);
        $this->assertSame($before, $this->protectedState());
        $this->assertSame($nutrition, app(MealItemResolver::class)->resolveMany($ids)->toJson());
    }

    private function protectedState(): array
    {
        $state = [];
        foreach (['meal_plan_days', 'meals', 'meal_items', 'foods', 'ingredients', 'recipes', 'recipe_versions', 'recipe_ingredients', 'recipe_components', 'recipe_directions', 'recipe_kitchen_equipment', 'recipe_batch_formats', 'recipe_batch_quantities', 'client_meal_plan_assignments', 'client_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy($table === 'recipe_batch_quantities' ? 'format_id' : 'id')->get()->toJson();
        }

        return $state;
    }

    public function test_all_empty_meals_and_missing_days_are_reported_without_writes(): void
    {
        $day = $this->version->days()->where('day_number', 2)->sole();
        DB::table('meal_items')->where('meal_id', $day->meals()->sole()->id)->delete();
        $last = $this->version->days()->where('day_number', 7)->sole();
        DB::table('meal_items')->where('meal_id', $last->meals()->sole()->id)->delete();
        $last->meals()->delete();
        $last->delete();
        $before = $this->protectedState();
        try {
            $this->publisher()->preview($this->actor, $this->version);
            $this->fail('Очікується відмова');
        } catch (ValidationException $e) {
            $messages = json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('День 2 · Синтетичний прийом', $messages);
            $this->assertArrayHasKey('weeks', $e->errors());
        }
        $this->assertSame($before, $this->protectedState());
        $this->assertSame('draft', $this->version->fresh()->status);
    }

    public function test_wrong_grams_are_reported_by_day_meal_and_item_and_sql_also_rejects(): void
    {
        $item = $this->version->days()->where('day_number', 1)->sole()->meals()->sole()->items()->sole();
        DB::table('meal_items')->where('id', $item->id)->update(['resolved_quantity_grams' => '99.00']);
        try {
            $this->publisher()->preview($this->actor, $this->version);
            $this->fail('Очікується відмова');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('День 1 · Синтетичний прийом', $e->errors()['item_'.$item->id][0]);
            $this->assertStringContainsString('Грамування', $e->errors()['item_'.$item->id][0]);
        }
        $this->sqlRejects(fn () => DB::table('meal_plan_versions')->where('id', $this->version->id)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]));
        $this->assertSame('draft', $this->version->fresh()->status);
    }

    public function test_stale_confirmation_and_duplicate_are_rejected(): void
    {
        $token = $this->publisher()->preview($this->actor, $this->version);
        $this->generate(8);
        try {
            $this->publisher()->publish($this->actor, $this->version, $token);
            $this->fail('Очікується stale');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('publication', $e->errors());
        }
        foreach ($this->version->days()->where('day_number', '>', 7)->with('meals')->get() as $day) {
            $this->add($day->meals->sole()->id);
        }
        $token = $this->publisher()->preview($this->actor, $this->version);
        $this->publisher()->publish($this->actor, $this->version, $token);
        try {
            $this->publisher()->publish($this->actor, $this->version, $token);
            $this->fail('Очікується 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(1, $this->version->fresh()->version_number);
    }

    public function test_fresh_role_fence_forged_token_and_cross_version_are_denied(): void
    {
        $token = $this->publisher()->preview($this->actor, $this->version);
        foreach (['forged', 'cross-version', 'role', 'fence'] as $case) {
            $candidate = $this->version;
            if ($case === 'cross-version') {
                $candidate = $this->legacyEmptyPlan('Інша версія')->versions()->sole();
            }
            if ($case === 'role') {
                DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
            }
            if ($case === 'fence') {
                DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
            }
            try {
                $this->publisher()->publish($this->actor, $candidate, $case === 'forged' ? str_repeat('0', 64) : $token);
                $this->fail('Очікується відмова: '.$case);
            } catch (ValidationException|HttpException $e) {
                $this->assertSame('draft', $this->version->fresh()->status);
            }
            DB::table('users')->where('id', $this->actor->id)->update(['role' => 'admin']);
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
        }
    }

    public function test_empty_day_hole_and_empty_plan_fail_service_and_sql(): void
    {
        $empty = $this->legacyEmptyPlan('Порожній')->versions()->sole();
        try {
            $this->publisher()->preview($this->actor, $empty);
            $this->fail('Очікується відмова');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('weeks', $e->errors());
        }
        $day = $this->version->days()->where('day_number', 3)->sole();
        DB::table('meal_items')->where('meal_id', $day->meals()->sole()->id)->delete();
        $day->meals()->delete();
        try {
            $this->publisher()->preview($this->actor, $this->version);
            $this->fail('Очікується відмова');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('День 3: немає', $e->errors()['day_3'][0]);
        }
        $this->sqlRejects(fn () => DB::table('meal_plan_versions')->where('id', $this->version->id)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]));
        $day->delete();
        try {
            $this->publisher()->preview($this->actor, $this->version);
            $this->fail('Очікується відмова');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('День 3: відсутній', $e->errors()['day_3'][0]);
        }
    }

    private function sqlRejects(callable $command): void
    {
        DB::beginTransaction();
        try {
            $command();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('SQL має відхилити');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_published_root_and_each_child_reject_update_delete_insert(): void
    {
        $token = $this->publisher()->preview($this->actor, $this->version);
        $this->publisher()->publish($this->actor, $this->version, $token);
        $day = $this->version->days()->first();
        $meal = $day->meals()->sole();
        $item = $meal->items()->sole();
        foreach (['meal_plan_versions' => $this->version->id, 'meal_plan_days' => $day->id, 'meals' => $meal->id, 'meal_items' => $item->id] as $table => $id) {
            $this->sqlRejects(fn () => DB::table($table)->where('id', $id)->update(['id' => $id]));
            $this->sqlRejects(fn () => DB::table($table)->where('id', $id)->delete());
        }
        $this->sqlRejects(fn () => DB::table('meal_plan_days')->insert(['meal_plan_version_id' => $this->version->id, 'day_number' => 8]));
        $this->sqlRejects(fn () => DB::table('meal_items')->insert(['meal_id' => $meal->id, 'food_id' => $item->food_id, 'quantity' => '1', 'uom_id' => $item->uom_id, 'resolved_quantity_grams' => '1']));
    }

    public function test_livewire_preview_cancel_publish_and_readonly(): void
    {
        $component = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->mountAction('publishPlan');
        $this->assertSame('draft', $this->version->fresh()->status);
        $component->call('unmountAction');
        $this->assertSame('draft', $this->version->fresh()->status);
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->callAction('publishPlan')->assertHasNoActionErrors();
        $this->assertSame('published', $this->version->fresh()->status);
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->assertActionHidden('publishPlan');
    }

    private function dayEditor(): Testable
    {
        return Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id,
            'day' => $this->version->days()->where('day_number', 1)->sole()->id]);
    }

    public function test_publish_action_is_in_week_overview_with_confirmation_and_readonly_redirect(): void
    {
        $editor = $this->dayEditor();
        $this->assertFalse(collect($editor->instance()->getCachedHeaderActions())
            ->contains(fn ($action) => $action instanceof Action && $action->getName() === 'publishPlan'));
        $overview = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $publish = collect($overview->instance()->getCachedHeaderActions())
            ->first(fn ($action) => $action instanceof Action && $action->getName() === 'publishPlan');
        $this->assertNotNull($publish);
        $this->assertSame('Опублікувати', $publish->getLabel());
        $this->assertTrue($publish->isVisible());
        $before = $this->protectedState();
        $root = $this->version->fresh()->getAttributes();
        $overview->mountAction('publishPlan')->assertActionMounted('publishPlan');
        $this->assertSame('Опублікувати версію раціону', $overview->instance()->getMountedAction()->getModalHeading());
        $this->assertStringContainsString('Після публікації ця версія незмінна.', $overview->instance()->getMountedAction()->getModalDescription());
        $overview->call('unmountAction')->assertNoRedirect();
        $this->assertSame($before, $this->protectedState());
        $this->assertSame($root, $this->version->fresh()->getAttributes());
        $overview->mountAction('publishPlan')->callMountedAction()->assertHasNoActionErrors()
            ->assertRedirect(MealPlanVersionResource::getUrl('view', ['record' => $this->version]));
        $this->assertSame('published', $this->version->fresh()->status);
        $this->assertSame($before, $this->protectedState());
        $this->assertFalse(collect($this->dayEditor()->instance()->getCachedHeaderActions())
            ->contains(fn ($action) => $action instanceof Action && $action->getName() === 'publishPlan'));
    }

    public function test_week_publish_rechecks_entire_version_and_rejects_stale_confirmation(): void
    {
        $editor = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->mountAction('publishPlan')->assertActionMounted('publishPlan');
        $this->generate(8);
        $before = $this->protectedState();
        $editor->callMountedAction()->assertHasActionErrors(['publication']);
        $this->assertSame($before, $this->protectedState());
        $this->assertSame('draft', $this->version->fresh()->status);
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->mountAction('publishPlan')->assertNotified('Структуру не збережено');
    }

    public function test_day_publish_rejects_unauthorized_actor_and_revocation_after_confirmation(): void
    {
        foreach (['role', 'fence'] as $case) {
            $editor = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->mountAction('publishPlan')->assertActionMounted('publishPlan');
            if ($case === 'role') {
                DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
            } else {
                DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
            }
            $before = $this->protectedState();
            $editor->callMountedAction()->assertForbidden();
            $this->assertSame($before, $this->protectedState());
            $this->assertSame('draft', $this->version->fresh()->status);
            if ($case === 'role') {
                $this->dayEditor()->assertForbidden();
            }
            DB::table('users')->where('id', $this->actor->id)->update(['role' => 'admin']);
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
        }
    }

    public function test_half_up_grams_and_next_version_number_preserve_first_publication(): void
    {
        $item = $this->version->days()->where('day_number', 1)->sole()->meals()->sole()->items()->sole();
        $unit = DB::table('uoms')->insertGetId(['code' => 'synthetic-publication-count', 'kind' => 'count', 'label' => 'шт']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $this->history['ingredient'], 'uom_id' => $unit, 'grams_per_uom' => '0.50']);
        DB::table('meal_items')->where('id', $item->id)->update(['quantity' => '0.01', 'uom_id' => $unit, 'resolved_quantity_grams' => '0.01']);
        $first = $this->publisher()->publish($this->actor, $this->version, $this->publisher()->preview($this->actor, $this->version));
        $frozen = $first->getAttributes();
        $this->assertSame(1, $first->version_number);
        // Synthetic наступна чернетка: UI створення версій/CoW лишається поза T4.4.
        $this->version = MealPlanVersion::findOrFail(DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $first->meal_plan_id]));
        $this->generate(1);
        foreach ($this->version->days()->with('meals')->get() as $day) {
            $this->add($day->meals->sole()->id);
        }
        $second = $this->publisher()->publish($this->actor, $this->version, $this->publisher()->preview($this->actor, $this->version));
        $this->assertSame(2, $second->version_number);
        $this->assertSame($frozen, $first->fresh()->getAttributes());
        $this->assertSame('0.01', $item->fresh()->resolved_quantity_grams);
    }

    public function test_late_constraint_failure_rolls_back_publication(): void
    {
        DB::unprepared("CREATE FUNCTION synthetic_plan_publish_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic late failure'; END $$;
            CREATE CONSTRAINT TRIGGER synthetic_plan_publish_failure AFTER UPDATE ON meal_plan_versions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION synthetic_plan_publish_failure();");
        $token = $this->publisher()->preview($this->actor, $this->version);
        try {
            $this->publisher()->publish($this->actor, $this->version, $token);
            $this->fail('Очікується rollback');
        } catch (QueryException) {
            $this->assertSame('draft', $this->version->fresh()->status);
            $this->assertNull($this->version->fresh()->published_at);
        }
    }
}

<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\Pages\EditMealPlanDay;
use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\MealItem;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemResolver;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanStructure;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\MealPlans\PlanFixture;
use Tests\Recipes\BatchFixture;

class MealPlanDayEditorTest extends PostgresTestCase
{
    use BatchFixture, PlanFixture;

    private User $actor;

    private MealPlanVersion $version;

    private MealItemWriter $writer;

    private int $day;

    private int $meal;

    private int $target;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        DB::table('uoms')->insert(['code' => 'g', 'kind' => 'mass', 'label' => 'gram']);
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic day admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->f = $this->populatedPlan($this->actor->id);
        $this->version = $this->legacyEmptyPlan('Редактор дня')->versions()->sole();
        $type = DB::table('meal_types')->insertGetId(['code' => 'editor-target', 'title' => 'Інший прийом']);
        app(MealPlanStructure::class)->generate($this->actor, $this->version, ['start_day' => 1, 'meal_type_ids' => [$this->f['type'], $type]]);
        $this->day = $this->version->days()->where('day_number', 1)->sole()->id;
        $meals = DB::table('meals')->where('meal_plan_day_id', $this->day)->orderBy('id')->pluck('id');
        [$this->meal, $this->target] = $meals->all();
        $this->writer = app(MealItemWriter::class);
    }

    private function add(int $order = 0): MealItem
    {
        $catalog = $this->writer->catalog($this->actor, $this->version, $this->f['food']);

        return $this->writer->save($this->actor, $this->version, ['meal_id' => $this->meal, 'food_id' => $this->f['food'],
            'uom_id' => $this->f['g'], 'quantity' => '2.50', 'sort_order' => $order,
            'expected' => $this->writer->mealToken($this->actor, $this->version, $this->meal), 'catalog_expected' => $catalog['token']]);
    }

    private function snapshot(): string
    {
        return $this->writer->dayToken($this->actor, $this->version, $this->day);
    }

    private function invalid(callable $command): void
    {
        $before = DB::table('meal_items')->orderBy('id')->get()->toJson();
        try {
            $command();
            $this->fail('Очікується stale/ownership validation');
        } catch (ValidationException) {
            $this->assertSame($before, DB::table('meal_items')->orderBy('id')->get()->toJson());
        }
    }

    public function test_stable_ties_atomic_reorder_and_move_keep_identity_and_catalog(): void
    {
        $catalog = DB::table('foods')->orderBy('id')->get()->toJson();
        $history = $this->historyHash();
        $a = $this->add(5);
        $b = $this->add(5);
        $c = $this->add(-1);
        $identity = $b->getAttributes();
        $expected = $this->snapshot();
        $this->writer->place($this->actor, $this->version, $this->day, $b->id, $this->meal, $c->id, $expected);
        $this->assertSame([$b->id, $c->id, $a->id], MealItem::where('meal_id', $this->meal)->orderBy('sort_order')->pluck('id')->all());
        $this->assertSame([0, 1, 2], MealItem::where('meal_id', $this->meal)->orderBy('sort_order')->pluck('sort_order')->all());
        $this->invalid(fn () => $this->writer->place($this->actor, $this->version, $this->day, $b->id, $this->meal, $c->id, $expected));
        $this->writer->place($this->actor, $this->version, $this->day, $b->id, $this->target, null, $this->snapshot());
        $this->assertSame($identity, array_replace($b->fresh()->getAttributes(), ['meal_id' => $identity['meal_id'], 'sort_order' => $identity['sort_order']]));
        $this->assertSame($catalog, DB::table('foods')->orderBy('id')->get()->toJson());
        $this->assertSame($history, $this->historyHash());
    }

    private function historyHash(): string
    {
        $version = MealPlanVersion::findOrFail($this->f['version']);
        $days = $version->days()->pluck('id');
        $meals = DB::table('meals')->whereIn('meal_plan_day_id', $days)->pluck('id');

        return hash('sha256', json_encode([
            $version->getAttributes(),
            DB::table('meal_plan_days')->whereIn('id', $days)->orderBy('id')->get(),
            DB::table('meals')->whereIn('id', $meals)->orderBy('id')->get(),
            DB::table('meal_items')->whereIn('meal_id', $meals)->orderBy('id')->get(),
            DB::table('client_meal_plan_assignments')->where('meal_plan_version_id', $version->id)->orderBy('id')->get(),
        ], JSON_THROW_ON_ERROR));
    }

    public function test_foreign_days_versions_items_and_before_ids_cannot_be_injected(): void
    {
        $a = $this->add();
        $otherDay = $this->version->days()->where('day_number', 2)->sole();
        $otherMeal = $otherDay->meals()->first()->id;
        foreach ([[$this->day, $a->id, $otherMeal, null], [$otherDay->id, $a->id, $otherMeal, null], [$this->day, $this->f['item'], $this->target, null], [$this->day, $a->id, $this->f['meal'], null], [$this->day, $a->id, $this->meal, $this->f['item']]] as [$day, $item, $meal, $before]) {
            $token = $this->writer->dayToken($this->actor, $this->version, $day);
            $this->invalid(fn () => $this->writer->place($this->actor, $this->version, $day, $item, $meal, $before, $token));
        }
        Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $this->f['day']])->assertNotFound();
        $this->invalid(fn () => $this->writer->dayToken($this->actor, $this->version, $this->f['day']));
    }

    public function test_readonly_get_exact_week_and_day_and_modal_never_write(): void
    {
        app(MealPlanStructure::class)->generate($this->actor, $this->version, ['start_day' => 8, 'meal_type_ids' => [$this->f['type']]]);
        $state = DB::table('meal_items')->get()->toJson();
        $day8 = $this->version->days()->where('day_number', 8)->sole()->id;
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->set('week', 2)->assertSee('День 8')->assertDontSee('День 1<', false);
        $this->assertCount(7, $page->instance()->selectedWeek());
        Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $day8])->assertSet('week', 2)->assertSee('До огляду тижня');
        $editor = Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $this->day]);
        $editor->mountAction('addItem', ['food' => $this->f['food'], 'meal' => $this->meal, 'expected' => $this->snapshot()])
            ->assertActionDataSet(['food_id' => $this->f['food'], 'quantity' => null, 'uom_id' => null, 'recipe_batch_format_id' => null, 'recipe_portion_divisor' => null]);
        $this->assertSame($state, DB::table('meal_items')->get()->toJson());
        $editor->unmountAction();
        $this->assertSame($state, DB::table('meal_items')->get()->toJson());
        $published = Livewire::test(EditMealPlanDay::class, ['record' => $this->f['version'], 'day' => $this->f['day']]);
        $published->assertDontSee('Каталог страв і продуктів')->assertDontSee('draggable="true"', false)->call('mountAction', 'addItem')->assertForbidden();
    }

    public function test_livewire_drop_persists_and_repeated_stale_drop_is_a_noop(): void
    {
        $a = $this->add();
        $expected = $this->snapshot();
        $editor = Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $this->day]);
        $editor->call('placeItem', $a->id, $this->target, null, $expected)->assertHasNoErrors();
        $this->assertSame($this->target, $a->fresh()->meal_id);
        $editor->call('placeItem', $a->id, $this->meal, null, $expected);
        $this->assertSame($this->target, $a->fresh()->meal_id);
        $this->assertSame(1, MealItem::whereIn('meal_id', [$this->meal, $this->target])->count());
    }

    public function test_open_editor_after_week_deletion_or_revocation_cannot_write(): void
    {
        $a = $this->add();
        $expected = $this->snapshot();
        $editor = Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $this->day]);
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
        $editor->call('placeItem', $a->id, $this->target, null, $expected)->assertForbidden();
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'admin']);
        $editor = Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $this->day]);
        $structure = app(MealPlanStructure::class);
        $snapshot = $structure->weekDeletionSnapshots($this->actor, $this->version)[0];
        $structure->deleteWeek($this->actor, $this->version, $snapshot + $structure->confirmWeekDeletion($this->actor, $this->version, $snapshot));
        $editor->call('placeItem', $a->id, $this->target, null, $expected)->assertNotFound();
        $this->assertNull($a->fresh());
    }

    public function test_place_preserves_exact_published_batch_portion_and_nutrition(): void
    {
        $batch = $this->batch();
        $this->publish($batch['version']);
        $food = DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id');
        $catalog = $this->writer->catalog($this->actor, $this->version, $food);
        $item = $this->writer->save($this->actor, $this->version, ['meal_id' => $this->meal, 'food_id' => $food,
            'quantity' => '0.50', 'uom_id' => $catalog['default'], 'sort_order' => 0,
            'recipe_version_id' => $batch['version'], 'recipe_batch_format_id' => $batch['formats']['half_week'], 'recipe_portion_divisor' => 4,
            'expected' => $this->writer->mealToken($this->actor, $this->version, $this->meal), 'catalog_expected' => $catalog['token']]);
        $before = $item->getAttributes();
        $resolver = app(MealItemResolver::class);
        $nutrition = $resolver->resolve($item)->nutrition;
        $physical = DB::table('recipe_batch_quantities')->orderBy('format_id')->get()->toJson();
        $this->writer->place($this->actor, $this->version, $this->day, $item->id, $this->target, null, $this->snapshot());
        $this->assertSame($before, array_replace($item->fresh()->getAttributes(), ['meal_id' => $this->meal]));
        $this->assertSame($nutrition, $resolver->resolve($item->fresh())->nutrition);
        $this->assertSame($physical, DB::table('recipe_batch_quantities')->orderBy('format_id')->get()->toJson());
    }

    public function test_return_week_tracks_same_day_after_preceding_week_is_deleted(): void
    {
        $structure = app(MealPlanStructure::class);
        foreach ([8, 15] as $start) {
            $structure->generate($this->actor, $this->version, ['start_day' => $start, 'meal_type_ids' => [$this->f['type']]]);
        }
        $day = $this->version->days()->where('day_number', 15)->sole();
        $editor = Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $day->id])->assertSet('week', 3);
        $structure->deleteWeek($this->actor, $this->version, $structure->weekDeletionSnapshots($this->actor, $this->version)[0]);
        $editor->call('$refresh')->assertSet('week', 2)->assertSet('dayId', $day->id)->assertSee('День 8');
        $this->assertSame(8, $day->fresh()->day_number);
    }

    public function test_day_save_keeps_catalog_context_without_redirect(): void
    {
        $editor = Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $this->day])
            ->set('search', 'Синтетичний')->set('catalogType', 'ingredient');
        $editor->mountAction('addItem', ['food' => $this->f['food'], 'meal' => $this->meal, 'expected' => $this->snapshot()])
            ->set('mountedActions.0.data.quantity', '0.50')->set('mountedActions.0.data.uom_id', $this->f['g'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNoRedirect()
            ->assertSet('search', 'Синтетичний')->assertSet('catalogType', 'ingredient');
        $this->assertSame('0.50', MealItem::where('meal_id', $this->meal)->sole()->quantity);
    }

    public function test_catalog_search_filter_and_pagination_stay_server_side(): void
    {
        for ($n = 1; $n <= 15; $n++) {
            $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
            DB::table('ingredients')->insert(['food_id' => $food, 'name' => 'Synthetic editor pagination '.$n,
                'kcal_per_100g' => '100.00', 'protein_per_100g' => '10.00', 'fat_per_100g' => '1.00', 'carbs_per_100g' => '2.00']);
        }
        $editor = Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $this->day])
            ->set('search', 'Synthetic editor pagination');
        $first = $editor->instance()->catalogRows()->take(12)->pluck('id');
        $this->assertCount(13, $editor->instance()->catalogRows());
        $editor->call('catalogNext', 1);
        $second = $editor->instance()->catalogRows()->pluck('id');
        $this->assertCount(3, $second);
        $this->assertCount(0, $first->intersect($second));
        $editor->set('catalogType', 'recipe_version')->assertSet('catalogPage', 1);
        $this->assertCount(0, $editor->instance()->catalogRows());
        $editor->set('search', '')->set('catalogType', 'ingredient');
        $this->assertTrue($editor->instance()->catalogRows()->every(fn ($row) => $row->food_type === 'ingredient'));
    }

    public function test_late_sql_failure_rolls_back_all_renumbering(): void
    {
        $a = $this->add();
        $b = $this->add();
        $state = DB::table('meal_items')->orderBy('id')->get()->toJson();
        DB::unprepared("CREATE FUNCTION day_editor_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic late failure'; END $$; CREATE CONSTRAINT TRIGGER day_editor_fail AFTER UPDATE ON meal_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION day_editor_fail();");
        try {
            $this->writer->place($this->actor, $this->version, $this->day, $b->id, $this->meal, $a->id, $this->snapshot());
            $this->fail('Late guard має відхилити всю транзакцію');
        } catch (QueryException) {
            $this->assertSame($state, DB::table('meal_items')->orderBy('id')->get()->toJson());
        }
    }
}

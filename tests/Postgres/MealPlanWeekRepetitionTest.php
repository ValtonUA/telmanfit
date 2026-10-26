<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\Pages\EditMealPlanDay;
use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\MealItem;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
use App\Services\MealPlans\MealPlanWeekRepeater;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\PlanFixture;
use Tests\Recipes\BatchFixture;

class MealPlanWeekRepetitionTest extends PostgresTestCase
{
    use BatchFixture, PlanFixture;

    private User $actor;

    private MealPlanVersion $version;

    private MealPlanWeekRepeater $repeater;

    private array $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic repeat admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        DB::table('uoms')->insert(['code' => 'g', 'label' => 'gram', 'kind' => 'mass']);
        $this->history = $this->populatedPlan($this->actor->id);
        $this->version = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Повторення'])->versions()->sole();
        $this->repeater = app(MealPlanWeekRepeater::class);
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

    private function input(int $source = 1, int $total = 4): array
    {
        return ['source_week' => $source, 'total_weeks' => $total, 'expected' => $this->repeater->snapshot($this->actor, $this->version)['expected']];
    }

    private function state(): string
    {
        $state = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'foods', 'ingredients', 'recipes', 'recipe_versions', 'recipe_ingredients', 'recipe_batch_formats', 'recipe_batch_quantities', 'client_meal_plan_assignments', 'client_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy($table === 'recipe_batch_quantities' ? 'format_id' : 'id')->get()->all();
        }

        return json_encode($state);
    }

    private function invalid(callable $command): void
    {
        $before = $this->state();
        try {
            $command();
            $this->fail('Очікується validation failure');
        } catch (ValidationException) {
            $this->assertSame($before, $this->state());
        }
    }

    private function week(int $number): array
    {
        return $this->version->days()->whereBetween('day_number', [($number - 1) * 7 + 1, $number * 7])->orderBy('day_number')->with(['meals' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'meals.items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->get()->map(fn ($day) => $day->meals->map(fn ($meal) => [
            'type' => $meal->meal_type_id, 'title' => $meal->title, 'sort_order' => $meal->sort_order,
            'items' => $meal->items->map(fn ($item) => collect($item->getAttributes())->except(['id', 'meal_id'])->all())->all(),
        ])->all())->all();
    }

    public function test_deep_copy_all_formats_decimal_exact_history_and_independent_edit(): void
    {
        $batch = $this->batch();
        $this->publish($batch['version']);
        $meal = $this->version->days()->orderBy('id')->first()->meals()->sole()->id;
        $serving = DB::table('uoms')->where('code', 'serving')->value('id');
        $legacy = DB::table('recipe_versions')->where('editor_contract', 'legacy_v1')->where('status', 'published')->sole();
        DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $legacy->food_id, 'quantity' => '0.25', 'uom_id' => $serving, 'sort_order' => 9]);
        foreach ([['single', 1], ['week', 7], ['half_week', 3], ['half_week', 4]] as [$code, $divisor]) {
            DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id'),
                'quantity' => '0.50', 'uom_id' => $serving, 'sort_order' => 9, 'recipe_version_id' => $batch['version'], 'recipe_batch_format_id' => $batch['formats'][$code], 'recipe_portion_divisor' => $divisor]);
        }
        DB::table('meals')->where('id', $meal)->update(['title' => 'Точна назва', 'sort_order' => -10]);
        $protected = [];
        foreach (['foods', 'ingredients', 'recipes', 'recipe_versions', 'recipe_ingredients', 'recipe_batch_formats', 'recipe_batch_quantities', 'client_meal_plan_assignments'] as $table) {
            $protected[$table] = DB::table($table)->orderBy($table === 'recipe_batch_quantities' ? 'format_id' : 'id')->get()->toJson();
        }
        $sourceIds = $this->repeater->snapshot($this->actor, $this->version);
        $source = $this->week(1);
        $input = $this->input();
        $this->repeater->repeat($this->actor, $this->version, $input);
        $this->assertSame(range(1, 28), $this->version->days()->orderBy('day_number')->pluck('day_number')->all());
        foreach ([1, 2, 3, 4] as $number) {
            $this->assertSame($source, $this->week($number));
        }
        foreach (['days', 'meals', 'items'] as $key) {
            $now = $this->repeater->snapshot($this->actor, $this->version)[$key];
            $this->assertCount(count($sourceIds[$key]) * 4, $now);
            $this->assertCount(count($now), array_unique(array_column($now, 'id')));
        }
        $item = MealItem::whereHas('meal.day', fn ($q) => $q->where('meal_plan_version_id', $this->version->id)->where('day_number', 15))->orderBy('id')->firstOrFail();
        $writer = app(MealItemWriter::class);
        $writer->save($this->actor, $this->version, ['meal_id' => $item->meal_id, 'food_id' => $item->food_id, 'quantity' => '22.22', 'uom_id' => $item->uom_id, 'sort_order' => 0,
            'expected' => MealItemWriter::token($item), 'catalog_expected' => $writer->catalog($this->actor, $this->version, $item->food_id)['token']], $item->id);
        foreach ([1, 2, 4] as $number) {
            $this->assertSame($source, $this->week($number));
        }
        $this->assertNotSame($source, $this->week(3));
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input));
        foreach ($protected as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy($table === 'recipe_batch_quantities' ? 'format_id' : 'id')->get()->toJson());
        }
    }

    public function test_confirmation_bound_to_source_total_actor_version_and_actual_target_content(): void
    {
        $this->repeater->repeat($this->actor, $this->version, $this->input());
        $input = $this->input(2, 4);
        $preview = $this->repeater->preview($this->actor, $this->version, $input);
        $this->assertSame([3 => ['meal_count' => 7, 'item_count' => 7], 4 => ['meal_count' => 7, 'item_count' => 7]], $preview['replacements']);
        foreach ([null, true, 'forged'] as $confirmation) {
            $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input + ['confirmation' => $confirmation]));
        }
        foreach ([['source_week' => 1], ['total_weeks' => 5], ['expected' => str_repeat('0', 64)]] as $patch) {
            $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, array_replace($input, $patch) + ['confirmation' => $preview['confirmation']]));
        }
        $firstIds = $this->version->days()->where('day_number', '<=', 14)->orderBy('day_number')->pluck('id')->all();
        $this->repeater->repeat($this->actor, $this->version, $input + ['confirmation' => $preview['confirmation']]);
        $this->assertSame($firstIds, $this->version->days()->where('day_number', '<=', 14)->orderBy('day_number')->pluck('id')->all());
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input + ['confirmation' => $preview['confirmation']]));
        $other = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Other synthetic admin', 'role' => 'admin']));
        $this->invalid(fn () => $this->repeater->repeat($other, $this->version, $this->input()));
    }

    public function test_empty_targets_partial_source_validation_stale_append_edit_delete_and_bounds(): void
    {
        $input = $this->input();
        $this->generate(8);
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input));
        $input = $this->input();
        DB::table('meals')->where('id', $this->version->days()->orderBy('id')->first()->meals()->sole()->id)->update(['title' => 'Змінено']);
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input));
        foreach ([['source_week' => 2], ['source_week' => 0], ['total_weeks' => 1], ['total_weeks' => 4682], ['total_weeks' => 2.5]] as $patch) {
            $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, array_replace($this->input(), $patch)));
        }
        // Порожні дні джерела дозволені, цілком порожній тиждень — ні.
        $days = $this->version->days()->orderBy('day_number')->get();
        $ids = DB::table('meals')->whereIn('meal_plan_day_id', $days->slice(1, 6)->pluck('id'))->pluck('id');
        DB::table('meal_items')->whereIn('meal_id', $ids)->delete();
        DB::table('meals')->whereIn('id', $ids)->delete();
        $input = $this->input();
        $input['confirmation'] = $this->repeater->preview($this->actor, $this->version, $input)['confirmation'];
        $this->repeater->repeat($this->actor, $this->version, $input);
        $this->assertSame($this->week(1), $this->week(4));
        $input = $this->input();
        $s = app(MealPlanStructure::class);
        $week = $s->weekDeletionSnapshots($this->actor, $this->version)[3];
        $s->deleteWeek($this->actor, $this->version, $week + $s->confirmWeekDeletion($this->actor, $this->version, $week));
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input));
        DB::table('meal_plan_days')->where('id', $days[6]->id)->update(['day_number' => 29]);
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $this->input()));
    }

    public function test_deferred_fault_and_late_role_revocation_roll_back_whole_replacement(): void
    {
        $this->repeater->repeat($this->actor, $this->version, $this->input());
        $input = $this->input();
        $input['confirmation'] = $this->repeater->preview($this->actor, $this->version, $input)['confirmation'];
        DB::unprepared("CREATE FUNCTION repeat_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic repeat fault'; END $$; CREATE CONSTRAINT TRIGGER repeat_fault AFTER INSERT ON meal_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION repeat_fault();");
        $before = $this->state();
        try {
            $this->repeater->repeat($this->actor, $this->version, $input);
            $this->fail('Очікується deferred fault');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic repeat fault', $e->getMessage());
        }
        $this->assertSame($before, $this->state());
        DB::unprepared('DROP TRIGGER repeat_fault ON meal_items; DROP FUNCTION repeat_fault();');
        DB::unprepared("CREATE FUNCTION repeat_revoke() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE users SET role='coach' WHERE id=".$this->actor->id.'; RETURN NEW; END $$; CREATE CONSTRAINT TRIGGER repeat_revoke AFTER INSERT ON meal_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION repeat_revoke();');
        try {
            $this->repeater->repeat($this->actor, $this->version, $input);
            $this->fail('403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $this->state());
        $this->assertSame('admin', $this->actor->fresh()->role);
    }

    public function test_empty_source_stale_confirmation_cross_version_and_published_service_are_rejected(): void
    {
        $this->generate(8);
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $this->input(2)));
        $input = $this->input();
        $input['confirmation'] = $this->repeater->preview($this->actor, $this->version, $input)['confirmation'];
        $this->repeater->repeat($this->actor, $this->version, $input);
        $input = $this->input();
        $input['confirmation'] = $this->repeater->preview($this->actor, $this->version, $input)['confirmation'];
        $target = $this->version->days()->where('day_number', 15)->sole()->meals()->sole();
        $this->add($target->id);
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input));
        $other = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Інша чернетка'])->versions()->sole();
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $other, $this->input()));
        $published = MealPlanVersion::findOrFail($this->history['version']);
        $before = $this->state();
        foreach (['snapshot', 'preview', 'repeat'] as $command) {
            try {
                $this->repeater->$command($this->actor, $published, $input);
                $this->fail('Published має бути readonly');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame($before, $this->state());
    }

    public function test_every_existing_target_requires_confirmation_even_without_meals_or_items(): void
    {
        $this->assertSame([], $this->repeater->preview($this->actor, $this->version, $this->input())['replacements']);
        $this->generate(8);
        $this->generate(15);
        $target = $this->version->days()->where('day_number', 8)->sole()->meals()->sole();
        $structure = app(MealPlanStructure::class);
        $otherType = DB::table('meal_types')->insertGetId(['code' => 'repeat-review-target', 'title' => 'Інший синтетичний тип']);
        $structure->saveMeal($this->actor, $this->version, ['day_id' => $target->meal_plan_day_id,
            'meal_type_id' => $otherType, 'title' => 'Авторська назва без страв', 'sort_order' => -12,
            'expected' => MealPlanStructure::token($target)], $target->id);
        // Навіть тиждень без жодного прийому не можна замінювати тихо.
        DB::table('meals')->whereIn('meal_plan_day_id', $this->version->days()->where('day_number', '>=', 15)->pluck('id'))->delete();
        $input = $this->input();
        $preview = $this->repeater->preview($this->actor, $this->version, $input);
        $this->assertSame([2 => ['meal_count' => 7, 'item_count' => 0], 3 => ['meal_count' => 0, 'item_count' => 0]], $preview['replacements']);
        foreach ([null, true, 'forged'] as $confirmation) {
            $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input + ['confirmation' => $confirmation]));
        }
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $before = $this->state();
        $page->call('mountAction', 'repeatWeek')->set('mountedActions.0.data.total_weeks', 4)->call('callMountedAction')
            ->assertActionMounted('replaceWeeks')
            ->assertSet('repeatSummary', fn ($summary) => str_contains($summary, 'тиждень 2 — прийомів: 7, позицій: 0')
                && str_contains($summary, 'тиждень 3 — прийомів: 0, позицій: 0'));
        $this->assertSame($before, $this->state());
        $page->call('unmountAction');
        $this->assertSame($before, $this->state());
        $page->call('mountAction', 'repeatWeek')->set('mountedActions.0.data.total_weeks', 4)->call('callMountedAction');
        $target = $target->fresh();
        $structure->saveMeal($this->actor, $this->version, ['day_id' => $target->meal_plan_day_id,
            'meal_type_id' => $otherType, 'title' => 'Нова правка під час підтвердження', 'sort_order' => 5,
            'expected' => MealPlanStructure::token($target)], $target->id);
        $before = $this->state();
        $page->call('callMountedAction')->assertHasActionErrors(['expected']);
        $this->assertSame($before, $this->state());
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $input + ['confirmation' => $preview['confirmation']]));
        $fresh = $this->input();
        $fresh['confirmation'] = $this->repeater->preview($this->actor, $this->version, $fresh)['confirmation'];
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $page->call('mountAction', 'repeatWeek')->set('mountedActions.0.data.total_weeks', 4)->call('callMountedAction')
            ->call('callMountedAction')->assertRedirect();
        $this->assertSame(28, $this->version->days()->count());
        $this->assertNull($target->fresh());
        $this->invalid(fn () => $this->repeater->repeat($this->actor, $this->version, $fresh));
    }

    public function test_day_editor_redirects_to_overview_after_replacing_its_own_day(): void
    {
        $this->repeater->repeat($this->actor, $this->version, $this->input());
        $day = $this->version->days()->where('day_number', 15)->sole();
        Livewire::test(EditMealPlanDay::class, ['record' => $this->version->id, 'day' => $day->id])
            ->call('mountAction', 'repeatWeek')->call('callMountedAction')
            ->assertActionMounted('replaceWeeks')->call('callMountedAction')->assertRedirect();
        $this->assertFalse($this->version->days()->whereKey($day->id)->exists());
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id, 'week' => 3])->assertSuccessful();
    }

    public function test_livewire_repeat_replace_cancel_forgery_stale_and_authorization(): void
    {
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $page->call('mountAction', 'repeatWeek')->set('mountedActions.0.data.total_weeks', 4)->call('callMountedAction')->assertRedirect();
        $this->assertSame(28, $this->version->days()->count());
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $page->call('mountAction', 'repeatWeek')->call('callMountedAction')->assertActionMounted('replaceWeeks');
        $before = $this->state();
        $page->call('unmountAction');
        $this->assertSame($before, $this->state());
        $page->call('mountAction', 'repeatWeek')->call('callMountedAction')->set('mountedActions.0.data.confirmation', 'forged')->call('callMountedAction')->assertHasActionErrors(['confirmation']);
        $this->assertSame($before, $this->state());
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $page->call('mountAction', 'repeatWeek')->call('callMountedAction')->call('callMountedAction')->assertRedirect();
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $page->call('mountAction', 'repeatWeek')->call('callMountedAction');
        $this->add($this->version->days()->where('day_number', 15)->sole()->meals()->sole()->id);
        $before = $this->state();
        $page->call('callMountedAction')->assertHasActionErrors(['expected']);
        $this->assertSame($before, $this->state());
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $page->call('mountAction', 'repeatWeek');
        $this->generate(29);
        $page->call('callMountedAction')->assertHasActionErrors(['expected']);
        foreach (['repeatWeek', 'replaceWeeks'] as $action) {
            Livewire::test(ViewMealPlanVersion::class, ['record' => $this->history['version']])->call('mountAction', $action)->assertForbidden();
        }
        foreach (['coach', 'client'] as $role) {
            $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
            DB::table('users')->where('id', $this->actor->id)->update(['role' => $role]);
            $page->call('mountAction', 'repeatWeek')->assertForbidden();
            try {
                $this->repeater->repeat($this->actor, $this->version, []);
                $this->fail('403');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
            DB::table('users')->where('id', $this->actor->id)->update(['role' => 'admin']);
        }
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        $page->call('mountAction', 'repeatWeek')->assertForbidden();
    }
}

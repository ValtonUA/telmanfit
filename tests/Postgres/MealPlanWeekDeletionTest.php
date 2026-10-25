<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\PlanFixture;

class MealPlanWeekDeletionTest extends PostgresTestCase
{
    use PlanFixture;

    private User $actor;

    private MealPlanStructure $writer;

    private MealPlanVersion $version;

    private int $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic week deletion admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->writer = app(MealPlanStructure::class);
        $this->version = $this->draft();
        $this->type = DB::table('meal_types')->insertGetId(['code' => 'synthetic-week-deletion', 'title' => 'Синтетичний прийом тижня']);
    }

    private function draft(): MealPlanVersion
    {
        return app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Видалення тижнів'])->versions()->sole();
    }

    private function generate(int $weeks): void
    {
        for ($n = 0; $n < $weeks; $n++) {
            $this->writer->generate($this->actor, $this->version, ['start_day' => $n * 7 + 1, 'meal_type_ids' => [$this->type]]);
        }
    }

    private function snapshots(): array
    {
        return $this->writer->weekDeletionSnapshots($this->actor, $this->version);
    }

    private function state(): string
    {
        $data = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'foods', 'ingredients', 'recipes', 'recipe_versions', 'client_meal_plan_assignments', 'client_events'] as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($data));
    }

    private function invalid(callable $operation): void
    {
        $before = $this->state();
        try {
            $operation();
            $this->fail('Очікується відмова без запису');
        } catch (ValidationException) {
            $this->assertSame($before, $this->state());
        }
    }

    private function addItems(int $dayId, array $history): void
    {
        $meal = DB::table('meals')->where('meal_plan_day_id', $dayId)->value('id');
        $recipeFood = DB::table('recipe_versions')->where('status', 'published')->value('food_id');
        $serving = DB::table('uoms')->where('code', 'serving')->value('id');
        DB::table('meal_items')->insert([
            ['meal_id' => $meal, 'food_id' => $history['food'], 'quantity' => '1.00', 'uom_id' => $history['g'], 'resolved_quantity_grams' => '1.00'],
            ['meal_id' => $meal, 'food_id' => $recipeFood, 'quantity' => '1.00', 'uom_id' => $serving, 'resolved_quantity_grams' => null],
        ]);
    }

    public function test_empty_first_middle_last_only_week_preserves_remaining_ids_content_and_version(): void
    {
        foreach ([[1, 0], [2, 0], [2, 1], [3, 1], [4, 0], [4, 1], [4, 3]] as [$weeks, $index]) {
            $this->version = $this->draft();
            $this->generate($weeks);
            $snapshot = $this->snapshots()[$index];
            $this->assertSame(0, $snapshot['item_count']);
            $days = $this->version->days()->orderBy('day_number')->get();
            $remaining = $days->whereNotIn('id', $snapshot['day_ids'])->map(function ($day) use ($index) {
                $attributes = $day->getAttributes();
                if ($attributes['day_number'] > ($index + 1) * 7) {
                    $attributes['day_number'] -= 7;
                }

                return $attributes;
            })->values()->all();
            $meals = DB::table('meals')->whereIn('meal_plan_day_id', array_column($remaining, 'id'))->orderBy('id')->get();
            $version = $this->version->getAttributes();
            $this->writer->deleteWeek($this->actor, $this->version, $snapshot);
            $this->assertSame($remaining, $this->version->days()->orderBy('day_number')->get()->map->getAttributes()->all());
            $this->assertEquals($meals, DB::table('meals')->whereIn('meal_plan_day_id', array_column($remaining, 'id'))->orderBy('id')->get());
            $this->assertSame($version, $this->version->fresh()->getAttributes());
            $this->assertSame(0, DB::table('meal_plan_days')->whereIn('id', $snapshot['day_ids'])->count());
            $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $snapshot));
        }
    }

    public function test_filled_middle_week_requires_unforgeable_confirmation_preserves_foods_history_and_following_items(): void
    {
        $history = $this->populatedPlan($this->actor->id);
        $protected = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'foods', 'ingredients', 'recipes', 'recipe_versions', 'client_meal_plan_assignments', 'client_events'] as $table) {
            $protected[$table] = DB::table($table)->orderBy('id')->get();
        }
        $this->generate(3);
        $days = $this->version->days()->orderBy('day_number')->get();
        $this->addItems($days[7]->id, $history);
        $this->addItems($days[14]->id, $history);
        $followingMeal = DB::table('meals')->where('meal_plan_day_id', $days[14]->id)->value('id');
        $followingItems = DB::table('meal_items')->where('meal_id', $followingMeal)->orderBy('id')->get();
        $snapshot = $this->snapshots()[1];
        $this->assertSame(2, $snapshot['item_count']);
        foreach ([null, true, 'true', str_repeat('a', 64)] as $forged) {
            $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $snapshot + ['confirmation' => $forged]));
        }
        $confirmed = $snapshot + $this->writer->confirmWeekDeletion($this->actor, $this->version, $snapshot);
        $this->writer->deleteWeek($this->actor, $this->version, $confirmed);
        $this->assertSame(8, $days[14]->fresh()->day_number);
        $this->assertEquals($followingItems, DB::table('meal_items')->where('meal_id', $followingMeal)->orderBy('id')->get());
        foreach ($protected as $table => $rows) {
            $actual = DB::table($table)->when(in_array($table, ['meal_plan_days', 'meals', 'meal_items'], true), fn ($q) => $q->whereIn('id', $rows->pluck('id')))->orderBy('id')->get();
            $this->assertSame(hash('sha256', $rows->toJson()), hash('sha256', $actual->toJson()));
        }
        $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $confirmed));
    }

    public function test_snapshot_detects_new_items_changed_items_meals_and_renumbered_or_forged_anchors(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $this->generate(3);
        $empty = $this->snapshots()[1];
        $this->addItems($empty['anchor'], $f);
        $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $empty));
        $filled = $this->snapshots()[1];
        $confirmed = $filled + $this->writer->confirmWeekDeletion($this->actor, $this->version, $filled);
        DB::table('meal_items')->where('meal_id', DB::table('meals')->where('meal_plan_day_id', $filled['anchor'])->value('id'))->update(['sort_order' => 4]);
        $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $confirmed));
        $first = $this->snapshots()[0];
        DB::table('meals')->where('meal_plan_day_id', $first['anchor'])->update(['title' => 'Паралельна правка']);
        $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $first));
        $current = $this->snapshots();
        foreach ([['anchor' => $current[1]['anchor']], ['anchor' => $current[0]['day_ids'][1]], ['expected' => str_repeat('0', 64)], ['item_count' => 99], ['anchor' => 2147483647], ['anchor' => '2147483648']] as $patch) {
            $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, array_replace($current[0], $patch)));
        }
        $other = $this->draft();
        $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $other, $current[0]));
        $this->writer->deleteWeek($this->actor, $this->version, $current[0]);
        $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $current[2]));
    }

    public function test_retry_does_not_delete_regenerated_last_week_and_legacy_gaps_are_not_repaired(): void
    {
        $this->generate(2);
        $old = $this->snapshots()[1];
        $this->writer->deleteWeek($this->actor, $this->version, $old);
        $this->writer->generate($this->actor, $this->version, ['start_day' => 8, 'meal_type_ids' => [$this->type]]);
        $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $old));
        $current = $this->snapshots()[1];
        DB::table('meal_plan_days')->where('id', $current['day_ids'][6])->update(['day_number' => 15]);
        $this->assertSame([], $this->snapshots());
        $this->invalid(fn () => $this->writer->deleteWeek($this->actor, $this->version, $current));
    }

    public function test_deferred_delete_or_renumber_fault_rolls_back_every_child(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $this->generate(3);
        $week = $this->snapshots()[1];
        $this->addItems($week['anchor'], $f);
        $week = $this->snapshots()[1];
        $input = $week + $this->writer->confirmWeekDeletion($this->actor, $this->version, $week);
        DB::unprepared("CREATE FUNCTION ux4d_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic renumber fault'; END $$; CREATE CONSTRAINT TRIGGER ux4d_fault AFTER UPDATE ON meal_plan_days DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ux4d_fault();");
        $before = $this->state();
        try {
            $this->writer->deleteWeek($this->actor, $this->version, $input);
            $this->fail('Відкладена відмова перенумерації');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic renumber fault', $e->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    public function test_livewire_empty_week_has_no_confirmation_filled_week_cancel_and_forgery_fail_closed(): void
    {
        $this->generate(2);
        $empty = $this->snapshots()[0];
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->call('mountAction', 'deleteWeek'.$empty['anchor'], $empty)->assertRedirect();
        $this->assertSame(7, $this->version->days()->count());
        $f = $this->populatedPlan($this->actor->id);
        $week = $this->snapshots()[0];
        $this->addItems($week['anchor'], $f);
        $week = $this->snapshots()[0];
        $before = $this->state();
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->call('mountAction', 'deleteWeek'.$week['anchor'], $week)->assertActionMounted('deleteWeek'.$week['anchor']);
        $page->call('unmountAction');
        $this->assertSame($before, $this->state());
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->call('mountAction', 'deleteWeek'.$week['anchor'], $week);
        $page->set('mountedActions.0.data.confirmation', 'forged')->call('callMountedAction')->assertHasActionErrors(['confirmation']);
        $this->assertSame($before, $this->state());
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->call('mountAction', 'deleteWeek'.$week['anchor'], $week)->call('callMountedAction')->assertRedirect();
        $this->assertSame(0, $this->version->days()->count());
    }

    public function test_forged_or_stale_livewire_arguments_do_not_silently_delete_empty_or_newly_filled_weeks(): void
    {
        $this->generate(2);
        $week = $this->snapshots()[0];
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        DB::table('meals')->where('meal_plan_day_id', $week['anchor'])->update(['title' => 'Пізня правка']);
        $before = $this->state();
        $page->call('mountAction', 'deleteWeek'.$week['anchor'], $week)->assertHasErrors(['mountedActions.0.data.week']);
        $this->assertSame($before, $this->state());
        $f = $this->populatedPlan($this->actor->id);
        $week = $this->snapshots()[0];
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $this->addItems($week['anchor'], $f);
        $before = $this->state();
        $page->call('mountAction', 'deleteWeek'.$week['anchor'], $week)->assertHasErrors(['mountedActions.0.data.week']);
        $this->assertSame($before, $this->state());
        $fresh = $this->snapshots()[0];
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])
            ->call('mountAction', 'deleteWeek'.$fresh['anchor'], array_replace($fresh, ['item_count' => 0]))
            ->assertHasErrors(['mountedActions.0.data.week']);
        $this->assertSame($before, $this->state());
    }

    public function test_published_revoked_role_and_recovery_fence_reject_service_and_direct_actions(): void
    {
        $this->generate(1);
        $week = $this->snapshots()[0];
        $history = $this->populatedPlan($this->actor->id);
        $published = MealPlanVersion::findOrFail($history['version']);
        $this->forbidden(fn () => $this->writer->deleteWeek($this->actor, $published, $week));
        $this->forbidden(fn () => $this->writer->confirmWeekDeletion($this->actor, $published, $week));
        Livewire::test(ViewMealPlanVersion::class, ['record' => $published->id])->call('mountAction', 'deleteWeek'.$week['anchor'], $week)->assertForbidden();
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
        $this->forbidden(fn () => $this->writer->deleteWeek($this->actor, $this->version, $week));
        $page->call('mountAction', 'deleteWeek'.$week['anchor'], $week)->assertForbidden();
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'admin']);
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        $this->forbidden(fn () => $this->writer->deleteWeek($this->actor, $this->version, $week));
    }

    public function test_fresh_role_after_deferred_delete_guard_and_cross_actor_snapshot(): void
    {
        $this->generate(1);
        $week = $this->snapshots()[0];
        $other = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic other admin', 'role' => 'admin']));
        $this->invalid(fn () => $this->writer->deleteWeek($other, $this->version, $week));
        DB::unprepared("CREATE FUNCTION ux4d_revoke() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE users SET role='coach' WHERE id=".$this->actor->id.'; RETURN OLD; END $$; CREATE CONSTRAINT TRIGGER ux4d_revoke AFTER DELETE ON meal_plan_days DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ux4d_revoke();');
        $this->forbidden(fn () => $this->writer->deleteWeek($this->actor, $this->version, $week));
        $this->assertSame('admin', $this->actor->fresh()->role);
    }

    private function forbidden(callable $operation): void
    {
        $before = $this->state();
        try {
            $operation();
            $this->fail('403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $this->state());
    }
}

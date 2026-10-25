<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\Meal;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\PlanFixture;

class MealPlanStructureTest extends PostgresTestCase
{
    use PlanFixture;

    private User $actor;

    private MealPlanVersion $version;

    private MealPlanStructure $writer;

    private array $types;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic structure admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->version = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Структура'])->versions()->sole();
        $this->writer = app(MealPlanStructure::class);
        $this->types = [];
        foreach ([20, -10, 0] as $order) {
            $this->types[] = DB::table('meal_types')->insertGetId(['code' => 'synthetic-'.$order, 'title' => 'Тип '.$order, 'sort_order' => $order]);
        }
    }

    private function generate(int $start = 1): void
    {
        $this->writer->generate($this->actor, $this->version, ['start_day' => $start, 'meal_type_ids' => [$this->types[0], $this->types[1]]]);
    }

    private function invalid(callable $operation): void
    {
        $before = $this->hash();
        try {
            $operation();
            $this->fail('Очікується validation failure');
        } catch (ValidationException) {
            $this->assertSame($before, $this->hash());
        }
    }

    private function hash(): string
    {
        $rows = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments', 'foods', 'recipes'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($rows));
    }

    public function test_generation_7_14_21_selected_types_order_and_retry_preserve_edits(): void
    {
        $this->generate();
        $this->assertSame(range(1, 7), $this->version->days()->orderBy('day_number')->pluck('day_number')->all());
        $meal = $this->version->days()->orderBy('day_number')->first()->meals()->orderBy('sort_order')->first();
        $this->assertSame($this->types[1], $meal->meal_type_id);
        $this->writer->saveMeal($this->actor, $this->version, ['day_id' => $meal->meal_plan_day_id, 'meal_type_id' => $meal->meal_type_id, 'title' => '  Власна назва  ', 'sort_order' => -1, 'expected' => MealPlanStructure::token($meal)], $meal->id);
        $this->generate();
        $this->assertSame('Власна назва', $meal->fresh()->title);
        $this->generate(8);
        $this->generate(15);
        $this->generate(8);
        $this->assertSame(range(1, 21), $this->version->days()->orderBy('day_number')->pluck('day_number')->all());
        $this->assertSame(42, Meal::count());
        $this->assertSame(0, DB::table('meals')->where('meal_type_id', $this->types[2])->count());
        $this->assertSame(0, DB::table('meal_items')->count());
        $before = $this->hash();
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->assertSee('Тиждень')->assertSee('Власна назва');
        $this->assertSame($before, $this->hash());
    }

    public function test_invalid_and_empty_lookup_leave_no_partial_days(): void
    {
        foreach ([[], ['start_day' => 2, 'meal_type_ids' => $this->types], ['start_day' => 8, 'meal_type_ids' => $this->types], ['start_day' => 1, 'meal_type_ids' => []], ['start_day' => 1, 'meal_type_ids' => [32767]], ['start_day' => 1, 'meal_type_ids' => [$this->types[0], $this->types[0]]], ['start_day' => 32762, 'meal_type_ids' => $this->types]] as $data) {
            $this->invalid(fn () => $this->writer->generate($this->actor, $this->version, $data));
        }
        DB::table('meal_types')->delete();
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->mountAction('generateWeek')->assertActionMounted('generateWeek');
        $this->invalid(fn () => $this->generate());
    }

    public function test_partial_or_gapped_legacy_draft_is_not_repaired_implicitly(): void
    {
        DB::table('meal_plan_days')->insert(['meal_plan_version_id' => $this->version->id, 'day_number' => 2]);
        $this->invalid(fn () => $this->generate());
    }

    public function test_late_deferred_fault_rolls_back_entire_week(): void
    {
        DB::unprepared("CREATE FUNCTION t42_late_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic structure late failure'; END $$;
            CREATE CONSTRAINT TRIGGER t42_late_fault AFTER INSERT ON meals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION t42_late_fault();");
        $before = $this->hash();
        try {
            $this->generate();
            $this->fail('Відкладена відмова');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic structure late failure', $e->getMessage());
        }
        $this->assertSame($before, $this->hash());
    }

    public function test_edit_add_delete_unique_stale_and_forged_ids(): void
    {
        $this->generate();
        $meal = Meal::first();
        $day = $meal->meal_plan_day_id;
        $data = ['day_id' => $day, 'meal_type_id' => $this->types[2], 'title' => 'Новий', 'sort_order' => 7];
        $this->writer->saveMeal($this->actor, $this->version, $data);
        $this->invalid(fn () => $this->writer->saveMeal($this->actor, $this->version, $data));
        $new = Meal::where('title', 'Новий')->sole();
        $data['expected'] = MealPlanStructure::token($new);
        $data['title'] = 'Правка';
        $this->writer->saveMeal($this->actor, $this->version, $data, $new->id);
        $this->invalid(fn () => $this->writer->saveMeal($this->actor, $this->version, $data, $new->id));
        $this->invalid(fn () => $this->writer->deleteMeal($this->actor, $this->version, $day, $new->id, $data['expected']));
        $this->writer->deleteMeal($this->actor, $this->version, $day, $new->id, MealPlanStructure::token($new->fresh()));
        $this->assertNull($new->fresh());
        foreach ([['day_id' => 2147483647], ['meal_type_id' => 32767], ['sort_order' => 32768], ['meal_type_id' => $this->types[1]], ['title' => []]] as $patch) {
            $this->invalid(fn () => $this->writer->saveMeal($this->actor, $this->version, array_replace($data, $patch)));
        }
        $other = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Інша'])->versions()->sole();
        $this->invalid(fn () => $this->writer->saveMeal($this->actor, $other, $data, $meal->id));
        $this->invalid(fn () => $this->writer->deleteMeal($this->actor, $other, $day, $meal->id, MealPlanStructure::token($meal)));
        $otherDay = $this->version->days()->where('day_number', 2)->sole()->id;
        $this->invalid(fn () => $this->writer->saveMeal($this->actor, $this->version, array_replace($data, ['day_id' => $otherDay]), $meal->id));
    }

    public function test_populated_draft_delete_is_refused_and_published_history_is_unchanged(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $history = $this->hash();
        $published = MealPlanVersion::findOrFail($f['version']);
        $this->assertFalse(Gate::allows('editStructure', $published));
        foreach (['generateWeek', 'addMeal', 'editMeal', 'deleteMeal'] as $name) {
            Livewire::test(ViewMealPlanVersion::class, ['record' => $published->id])->call('mountAction', $name)->assertForbidden();
        }
        Livewire::test(ViewMealPlanVersion::class, ['record' => $published->id])->call('callMountedAction')->assertForbidden();
        try {
            $this->writer->generate($this->actor, $published, ['start_day' => 8, 'meal_type_ids' => $this->types]);
            $this->fail('Published immutable');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($history, $this->hash());
        // Синтетична позиція в draft: delete не каскадує і не змінює item.
        $this->generate();
        $meal = Meal::whereHas('day', fn ($q) => $q->where('meal_plan_version_id', $this->version->id))->first();
        DB::table('meal_items')->insert(['meal_id' => $meal->id, 'food_id' => $f['food'], 'quantity' => '1.00', 'uom_id' => $f['g'], 'resolved_quantity_grams' => '1.00']);
        $this->invalid(fn () => $this->writer->deleteMeal($this->actor, $this->version, $meal->meal_plan_day_id, $meal->id, MealPlanStructure::token($meal)));
    }

    public function test_livewire_exact_version_and_fresh_role_restore_fence(): void
    {
        $component = Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id]);
        $component->callAction('generateWeek', ['start_day' => 1, 'meal_type_ids' => $this->types])->assertHasNoActionErrors();
        $this->assertSame(7, $this->version->days()->count());
        foreach (['coach', 'client'] as $role) {
            DB::table('users')->where('id', $this->actor->id)->update(['role' => $role]);
            $this->assertFalse(Gate::allows('editStructure', $this->version));
            $this->denyGenerate();
        }
        $component->call('mountAction', 'generateWeek')->assertForbidden();
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'admin']);
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        $this->denyGenerate();
    }

    private function denyGenerate(): void
    {
        $before = $this->hash();
        try {
            $this->generate(8);
            $this->fail('Відкликання або fence');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $this->hash());
    }

    public function test_livewire_validation_is_mapped_to_visible_action_fields(): void
    {
        $this->generate();
        $day = $this->version->days()->first();
        $before = $this->hash();
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])
            ->callAction('addMeal', ['day_id' => $day->id, 'meal_type_id' => $this->types[0], 'sort_order' => 0])
            ->assertHasActionErrors(['meal_type_id']);
        $this->assertSame($before, $this->hash());
    }

    public function test_draft_commands_preserve_preexisting_history_foods_recipes_and_assignments(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $before = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments', 'foods', 'ingredients', 'recipes', 'recipe_versions'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get();
        }
        $this->generate();
        $this->generate(8);
        $meal = Meal::whereHas('day', fn ($q) => $q->where('meal_plan_version_id', $this->version->id))->first();
        $this->writer->saveMeal($this->actor, $this->version, ['day_id' => $meal->meal_plan_day_id, 'meal_type_id' => $this->types[2], 'title' => 'Правка', 'sort_order' => 12, 'expected' => MealPlanStructure::token($meal)], $meal->id);
        $this->writer->deleteMeal($this->actor, $this->version, $meal->meal_plan_day_id, $meal->id, MealPlanStructure::token($meal->fresh()));
        foreach ($before as $table => $rows) {
            $this->assertSame(hash('sha256', $rows->toJson()), hash('sha256', DB::table($table)->whereIn('id', $rows->pluck('id'))->orderBy('id')->get()->toJson()));
        }
        $published = MealPlanVersion::findOrFail($f['version']);
        $oldMeal = Meal::findOrFail($f['meal']);
        foreach ([fn () => $this->writer->saveMeal($this->actor, $published, ['day_id' => $f['day'], 'meal_type_id' => $this->types[0], 'sort_order' => 0]), fn () => $this->writer->deleteMeal($this->actor, $published, $f['day'], $f['meal'], MealPlanStructure::token($oldMeal))] as $command) {
            $hash = $this->hash();
            try {
                $command();
                $this->fail('Published immutable');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
            $this->assertSame($hash, $this->hash());
        }
    }

    public function test_fresh_role_is_checked_after_deferred_guards_before_commit(): void
    {
        DB::unprepared("CREATE FUNCTION t42_revoke() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE users SET role='coach' WHERE id=".$this->actor->id.'; RETURN NEW; END $$;
            CREATE CONSTRAINT TRIGGER t42_revoke AFTER INSERT ON meals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION t42_revoke();');
        $before = $this->hash();
        try {
            $this->generate();
            $this->fail('Свіжа роль перед commit');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $this->hash());
        $this->assertSame('admin', $this->actor->fresh()->role);
    }

    public function test_database_unique_guards_remain_enforced(): void
    {
        $this->generate();
        $day = $this->version->days()->first();
        foreach ([fn () => DB::table('meal_plan_days')->insert(['meal_plan_version_id' => $this->version->id, 'day_number' => 1]), fn () => DB::table('meals')->insert(['meal_plan_day_id' => $day->id, 'meal_type_id' => $this->types[0]])] as $attempt) {
            $before = $this->hash();
            try {
                DB::transaction($attempt);
                $this->fail('UNIQUE');
            } catch (QueryException $e) {
                $this->assertSame('23505', $e->errorInfo[0]);
                $this->assertSame($before, $this->hash());
            }
        }
    }
}

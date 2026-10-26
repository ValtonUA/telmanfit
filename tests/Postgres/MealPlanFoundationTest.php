<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\MealPlanResource;
use App\Filament\Resources\MealPlans\Pages\CreateMealPlan;
use App\Filament\Resources\MealPlans\Pages\ListMealPlans;
use App\Filament\Resources\MealPlans\Pages\ViewMealPlan;
use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\ClientMealPlanAssignment;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanVersion;
use App\Models\MealType;
use App\Models\User;
use App\Services\MealPlans\MealPlanFoundation;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\PlanFixture;

class MealPlanFoundationTest extends PostgresTestCase
{
    use PlanFixture;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic plan admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_atomic_empty_draft_and_untrusted_fields(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $untouchedCounts = array_map(fn ($table) => DB::table($table)->count(), ['foods', 'recipes', 'client_meal_plan_assignments']);
        $history = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments', 'foods', 'recipes', 'recipe_versions'] as $table) {
            $history[$table] = DB::table($table)->orderBy('id')->get();
        }
        $p = app(MealPlanFoundation::class)->create($this->actor, ['title' => '  Новий раціон  ', 'owner_client_id' => $f['client'],
            'id' => 123456, 'parent_meal_plan_id' => $f['plan'], 'status' => 'published', 'version_number' => 99, 'published_at' => now()]);
        $this->assertSame('Новий раціон', $p->title);
        $this->assertNull($p->parent_meal_plan_id);
        $v = $p->versions()->sole();
        $this->assertSame('draft', $v->status);
        $this->assertNull($v->version_number);
        $this->assertNull($v->published_at);
        $this->assertSame(0, $v->days()->count());
        $this->assertNotNull($v->created_at);
        $this->assertFalse(array_key_exists('updated_at', $v->getAttributes()));
        $this->assertSame('pending', $p->owner->status);
        $this->assertSame(1, DB::table('client_meal_plan_assignments')->count());
        $global = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Універсальний', 'owner_client_id' => '']);
        $this->assertNull($global->owner);
        $this->assertSame($p->id, $v->plan->id);
        $this->assertFalse(config('recipes.batch_editor_enabled'));
        $this->assertSame($untouchedCounts, array_map(fn ($table) => DB::table($table)->count(), ['foods', 'recipes', 'client_meal_plan_assignments']));
        foreach ($history as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->whereIn('id', $rows->pluck('id'))->orderBy('id')->get());
        }
    }

    public function test_name_and_forged_owner_validation(): void
    {
        foreach ([[], ['title' => '   '], ['title' => []], ['title' => 'Раціон', 'owner_client_id' => 2147483647], ['title' => 'Раціон', 'owner_client_id' => '1 OR 1=1']] as $input) {
            try {
                app(MealPlanFoundation::class)->create($this->actor, $input);
                $this->fail('Невалідний ввід');
            } catch (ValidationException) {
                $this->assertSame(0, MealPlan::count());
                $this->assertSame(0, MealPlanVersion::count());
            }
        }
    }

    public function test_late_deferred_failure_rolls_back_plan_and_draft(): void
    {
        DB::unprepared("CREATE FUNCTION t41_late_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic late failure'; END $$;
            CREATE CONSTRAINT TRIGGER t41_late_fault AFTER INSERT ON meal_plan_versions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION t41_late_fault();");
        try {
            app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Не зберегти']);
            $this->fail('Відкладена відмова');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic late failure', $e->getMessage());
        }
        $this->assertSame(0, MealPlan::count());
        $this->assertSame(0, MealPlanVersion::count());
    }

    public function test_relations_decimal_nullable_dates_and_history_after_ui_reads(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $p = MealPlan::findOrFail($f['plan']);
        $v = $p->versions()->sole();
        $d = $v->days()->where('day_number', 7)->sole();
        $m = $d->meals()->sole();
        $i = $m->items()->where('food_id', $f['food'])->sole();
        $this->assertNull($m->items()->whereNull('resolved_quantity_grams')->sole()->resolved_quantity_grams);
        $a = $v->assignments()->sole();
        $this->assertSame($v->id, $d->version->id);
        $this->assertSame($d->id, $m->day->id);
        $this->assertSame($m->id, $i->meal->id);
        $this->assertSame($f['type'], $m->type->id);
        $this->assertSame($m->id, $m->type->meals()->whereKey($m->id)->sole()->id);
        $this->assertSame('100.10', $i->quantity);
        $this->assertSame('100.10', $i->resolved_quantity_grams);
        $this->assertSame($f['g'], $i->uom->id);
        $this->assertSame($f['ingredient'], $i->food->ingredient->id);
        $this->assertSame($i->id, $i->food->mealItems()->whereKey($i->id)->sole()->id);
        $this->assertSame($f['client'], $a->client->id);
        $this->assertSame($p->id, $p->owner->ownedMealPlans()->sole()->id);
        $this->assertSame($a->id, $a->client->mealPlanAssignments()->sole()->id);
        $this->assertSame($a->id, $a->creator->createdMealPlanAssignments()->sole()->id);
        $this->assertSame($v->id, $a->version->id);
        $this->assertSame($this->actor->id, $a->creator->id);
        $this->assertNull($a->replacement);
        $this->assertSame('2026-01-01', $a->start_date->format('Y-m-d'));
        $next = DB::table('client_meal_plan_assignments')->insertGetId(['client_id' => $f['client'], 'meal_plan_version_id' => $v->id, 'start_date' => '2026-01-08']);
        DB::table('client_meal_plan_assignments')->where('id', $a->id)->update(['replaced_by_assignment_id' => $next]);
        $this->assertSame($a->id, ClientMealPlanAssignment::find($next)->replaces()->sole()->id);
        $this->assertSame($next, $a->fresh()->replacement->id);
        $this->assertNull(ClientMealPlanAssignment::find($next)->end_date);
        $this->assertNull(ClientMealPlanAssignment::find($next)->creator);
        $copy = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Синтетичний lineage']);
        DB::table('meal_plans')->where('id', $copy->id)->update(['parent_meal_plan_id' => $p->id]);
        $this->assertSame($p->id, $copy->fresh()->parent->id);
        $this->assertSame($copy->id, $p->copies()->sole()->id);
        $before = $this->historyHash();
        Livewire::test(ListMealPlans::class)->searchTable($p->title)->assertCanSeeTableRecords([$p]);
        Livewire::test(ViewMealPlan::class, ['record' => $p->id])->assertSuccessful();
        Livewire::test(ViewMealPlanVersion::class, ['record' => $v->id])->assertSuccessful()->assertSee('100.1')->assertSee('Опублікована');
        $this->assertSame($before, $this->historyHash());
    }

    private function historyHash(): string
    {
        $state = [];
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meal_types', 'meals', 'meal_items', 'client_meal_plan_assignments', 'foods', 'ingredients', 'recipes', 'recipe_versions'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($state));
    }

    public function test_direct_livewire_create_owner_validation_and_no_second_draft_on_view(): void
    {
        Livewire::test(CreateMealPlan::class)->fillForm(['title' => 'Спроба', 'owner_client_id' => 2147483647])->call('create')->assertHasFormErrors(['owner_client_id']);
        Livewire::test(CreateMealPlan::class)->fillForm(['title' => 'Чернетка'])->call('create')->assertHasNoFormErrors();
        $p = MealPlan::sole();
        $v = $p->versions()->sole();
        for ($n = 0; $n < 2; $n++) {
            Livewire::test(ViewMealPlanVersion::class, ['record' => $v->id])->assertSee('Чернетка ще не готова');
        }
        $this->assertSame(1, $p->versions()->count());
        $this->get(MealPlanResource::getUrl('view', ['record' => $p]).'/edit')->assertNotFound();
    }

    public function test_fresh_roles_policies_and_direct_livewire_are_denied(): void
    {
        $p = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Захищений']);
        $component = Livewire::test(CreateMealPlan::class)->fillForm(['title' => 'Після відкликання']);
        foreach ([new MealPlan, new MealPlanVersion, new MealPlanDay, new Meal, new MealType, new MealItem, new ClientMealPlanAssignment] as $model) {
            foreach (['update', 'delete', 'replicate'] as $ability) {
                $this->assertFalse(Gate::forUser($this->actor)->allows($ability, $model));
            }
            if (! $model instanceof MealPlan) {
                $this->assertFalse(Gate::forUser($this->actor)->allows('create', $model::class));
            }
        }
        foreach (['coach', 'client'] as $role) {
            DB::table('users')->where('id', $this->actor->id)->update(['role' => $role]);
            $this->assertFalse(Gate::forUser($this->actor)->allows('view', $p));
            try {
                app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Заборонено']);
                $this->fail('Відкликана роль');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
            Livewire::test(CreateMealPlan::class)->assertForbidden();
            Livewire::test(ViewMealPlanVersion::class, ['record' => $p->versions()->sole()->id])->assertForbidden();
        }
        $component->call('create')->assertForbidden();
        $this->assertSame(1, MealPlan::count());
    }

    public function test_restore_fence_denies_service_and_livewire(): void
    {
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        $this->assertFalse(MealPlanFoundation::allowed($this->actor));
        Livewire::test(CreateMealPlan::class)->assertForbidden();
        try {
            app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Заборонено під час restore']);
            $this->fail('Restore fence');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, MealPlan::count());
    }

    public function test_existing_sql_guards_still_reject_unsafe_writes(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        $draft = app(MealPlanFoundation::class)->create($this->actor, ['title' => 'Одна чернетка']);
        $attempts = [
            fn () => DB::table('meal_plan_versions')->insert(['meal_plan_id' => $draft->id]),
            fn () => DB::table('meal_plan_versions')->where('id', $f['version'])->update(['status' => 'draft']),
            fn () => DB::table('meal_plan_days')->where('id', $f['day'])->update(['day_number' => 8]),
            fn () => DB::table('meals')->where('id', $f['meal'])->delete(),
            fn () => DB::table('meal_items')->where('id', $f['item'])->update(['quantity' => '1.00']),
            fn () => DB::table('client_meal_plan_assignments')->insert(['client_id' => $f['client'], 'meal_plan_version_id' => $f['version'], 'start_date' => '2026-01-02']),
        ];
        $before = $this->historyHash();
        foreach ($attempts as $attempt) {
            try {
                DB::transaction(function () use ($attempt) {
                    $attempt();
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('SQL guard');
            } catch (QueryException) {
                $this->assertSame($before, $this->historyHash());
            }
        }
    }
}

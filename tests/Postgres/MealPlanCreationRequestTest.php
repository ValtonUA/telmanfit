<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Filament\Resources\MealPlans\Pages\CreateMealPlan;
use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\MealPlan;
use App\Models\User;
use App\Services\MealPlans\MealPlanFoundation;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\MealPlans\PlanFixture;

class MealPlanCreationRequestTest extends PostgresTestCase
{
    use PlanFixture;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        (require database_path('migrations/2026_10_29_000000_create_meal_plan_creation_requests.php'))->up();
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_creation_replay_mismatch_and_distinct_intents(): void
    {
        $types = [];
        foreach ([30, 10, 20, 40] as $order) {
            $types[] = DB::table('meal_types')->insertGetId(['code' => 'synthetic-'.Str::uuid(), 'title' => 'Тип '.$order, 'sort_order' => $order]);
        }
        $key = (string) Str::uuid();
        $input = ['request_key' => $key, 'title' => '  Тест  ', 'meal_type_ids' => $types];
        $service = app(MealPlanFoundation::class);
        $plan = $service->create($this->actor, $input);
        $version = $plan->versions()->sole();
        $this->assertSame(1, $plan->week_start_weekday);
        $this->assertSame(range(1, 7), $version->days()->orderBy('day_number')->pluck('day_number')->all());
        foreach ($version->days as $day) {
            $this->assertSame([$types[1], $types[2], $types[0], $types[3]], $day->meals()->orderBy('sort_order')->pluck('meal_type_id')->all());
        }
        $this->assertSame($plan->id, $service->create($this->actor, $input)->id);
        try {
            $service->create($this->actor, $input + ['week_start_weekday' => 2]);
            $this->fail('Змінені параметри не можна повторно використовувати');
        } catch (ValidationException) {
            $this->assertSame(1, MealPlan::count());
        }
        $second = $service->create($this->actor, ['request_key' => (string) Str::uuid(), 'title' => 'Тест', 'meal_type_ids' => [$types[0]]]);
        $this->assertNotSame($plan->id, $second->id);
        $third = $service->create($this->actor, ['request_key' => (string) Str::uuid(), 'title' => 'Тест', 'meal_type_ids' => [$types[2], $types[1]]]);
        $this->assertSame(2, $third->versions()->sole()->days()->first()->meals()->count());
        $this->assertSame(3, DB::table('meal_plan_creation_requests')->count());
    }

    public function test_missing_or_forged_types_leave_no_partial_plan(): void
    {
        foreach ([[], [32767]] as $ids) {
            try {
                app(MealPlanFoundation::class)->create($this->actor, ['request_key' => (string) Str::uuid(), 'title' => 'Невалідний', 'meal_type_ids' => $ids]);
                $this->fail('Невалідні типи мають бути відхилені');
            } catch (ValidationException) {
                $this->assertSame(0, MealPlan::count());
                $this->assertSame(0, DB::table('meal_plan_creation_requests')->count());
            }
        }
    }

    public function test_filament_creation_redirects_to_new_week(): void
    {
        DB::table('meal_types')->insertGetId(['code' => 'synthetic-later', 'title' => 'Пізніший прийом', 'sort_order' => 9]);
        $id = DB::table('meal_types')->insertGetId(['code' => 'synthetic-choice', 'title' => 'Обраний прийом', 'sort_order' => 4]);
        $key = (string) Str::uuid();
        $page = Livewire::withQueryParams(['creation' => $key])->test(CreateMealPlan::class)
            ->assertSee('Обраний прийом')
            ->assertSeeInOrder(['Обраний прийом', 'Пізніший прийом'])
            ->fillForm(['title' => 'Сім днів', 'meal_type_ids' => [$id]])
            ->call('create')->assertHasNoFormErrors();
        $plan = MealPlan::sole();
        $version = $plan->versions()->sole();
        $this->assertSame(7, $version->days()->count());
        $page->assertRedirect(MealPlanVersionResource::getUrl('view', ['record' => $version]));
        $this->assertSame($key, DB::table('meal_plan_creation_requests')->sole()->request_key);
    }

    public function test_failure_after_days_and_meals_rolls_back_everything(): void
    {
        $id = DB::table('meal_types')->insertGetId(['code' => 'synthetic-fault', 'title' => 'Тест']);
        DB::unprepared("CREATE FUNCTION ux3_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'ux3 synthetic fault'; END $$; CREATE TRIGGER ux3_failure BEFORE INSERT ON meal_plan_creation_requests FOR EACH ROW EXECUTE FUNCTION ux3_failure();");
        try {
            app(MealPlanFoundation::class)->create($this->actor, ['request_key' => (string) Str::uuid(), 'title' => 'Не зберегти', 'meal_type_ids' => [$id]]);
            $this->fail('Очікувалася відмова БД');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ux3 synthetic fault', $e->getMessage());
        }
        foreach (['meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_plan_creation_requests'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_other_admin_cannot_reuse_another_actors_key(): void
    {
        $id = DB::table('meal_types')->insertGetId(['code' => 'synthetic-admin', 'title' => 'Тест']);
        $other = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Other synthetic admin', 'role' => 'admin']));
        $input = ['request_key' => (string) Str::uuid(), 'title' => 'Захищений', 'meal_type_ids' => [$id]];
        app(MealPlanFoundation::class)->create($this->actor, $input);
        try {
            app(MealPlanFoundation::class)->create($other, $input);
            $this->fail('Чужу квитанцію не можна повернути');
        } catch (ValidationException) {
            $this->assertSame(1, MealPlan::count());
        }
        $otherInput = array_replace($input, ['request_key' => (string) Str::uuid()]);
        $otherPlan = app(MealPlanFoundation::class)->create($other, $otherInput);
        $this->assertNotSame(DB::table('meal_plan_creation_requests')->where('request_key', $input['request_key'])->value('meal_plan_id'), $otherPlan->id);
        $this->assertSame($other->id, DB::table('meal_plan_creation_requests')->where('request_key', $otherInput['request_key'])->value('admin_id'));
    }

    public function test_invalid_catalogue_entry_is_rejected(): void
    {
        DB::table('meal_types')->insertGetId(['code' => '  ', 'title' => 'Некоректний']);
        $id = DB::table('meal_types')->insertGetId(['code' => 'valid-choice', 'title' => 'Коректний']);
        try {
            app(MealPlanFoundation::class)->create($this->actor, ['request_key' => (string) Str::uuid(), 'title' => 'План', 'meal_type_ids' => [$id]]);
            $this->fail('Некоректний довідник має блокувати створення');
        } catch (ValidationException) {
            $this->assertSame(0, MealPlan::count());
            $this->assertSame(0, DB::table('meal_plan_creation_requests')->count());
        }
    }

    public function test_empty_catalogue_blocks_filament_creation(): void
    {
        $key = (string) Str::uuid();
        Livewire::withQueryParams(['creation' => $key])->test(CreateMealPlan::class)
            ->assertSee('Довідник типів прийомів порожній')
            ->fillForm(['title' => 'Неможливий раціон'])
            ->call('create')->assertHasFormErrors(['meal_type_ids']);
        $this->assertSame(0, MealPlan::count());
        $this->assertSame(0, DB::table('meal_plan_creation_requests')->count());
    }

    public function test_legacy_first_week_retry_never_appends_or_overwrites(): void
    {
        $id = DB::table('meal_types')->insertGetId(['code' => 'legacy-first-week', 'title' => 'Тип']);
        $version = $this->legacyEmptyPlan('Стара чернетка')->versions()->sole();
        $page = Livewire::test(ViewMealPlanVersion::class, ['record' => $version->id]);
        $page->assertSee('Створити 7 днів');
        $page->callAction('createFirstWeek', ['meal_type_ids' => [$id]])->assertHasNoActionErrors();
        $meal = $version->days()->orderBy('day_number')->first()->meals()->sole();
        DB::table('meals')->where('id', $meal->id)->update(['title' => 'Не перезаписувати']);
        $page->callAction('createFirstWeek', ['meal_type_ids' => [$id]])->assertHasNoActionErrors();
        $this->assertSame(7, $version->days()->count());
        $this->assertSame('Не перезаписувати', $meal->fresh()->title);
    }

    public function test_replay_redirects_to_original_version_after_another_draft_exists(): void
    {
        $f = $this->populatedPlan($this->actor->id);
        DB::table('meal_plan_versions')->insert(['meal_plan_id' => $f['plan']]);
        $key = (string) Str::uuid();
        $title = 'Синтетичний історичний раціон';
        $hash = hash('sha256', json_encode([$title, 1, $f['client'], [$f['type']]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        DB::table('meal_plan_creation_requests')->insert(['request_key' => $key, 'admin_id' => $this->actor->id, 'parameters_sha256' => $hash, 'meal_plan_id' => $f['plan']]);
        $page = Livewire::withQueryParams(['creation' => $key])->test(CreateMealPlan::class)
            ->fillForm(['title' => $title, 'owner_client_id' => $f['client'], 'meal_type_ids' => [$f['type']]])
            ->call('create')->assertHasNoFormErrors();
        $page->assertRedirect(MealPlanVersionResource::getUrl('view', ['record' => $f['version']]));
        $this->assertSame(2, DB::table('meal_plan_versions')->where('meal_plan_id', $f['plan'])->count());
    }
}

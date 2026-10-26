<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealPlanAssigner;
use App\Services\MealPlans\MealPlanCopier;
use App\Services\MealPlans\MealPlanPublisher;
use App\Services\MealPlans\MealPlanWeekRepeater;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\AssignmentFixture;

class MealPlanCopyTest extends PostgresTestCase
{
    use AssignmentFixture, \Tests\Recipes\BatchFixture;

    public function test_independent_copy_publish_assign_replay_and_rollback(): void
    {
        $this->installAuthSchema();
        foreach (glob(database_path('migrations/2026_10_24_0[123]*.php')) as $file) {
            (require $file)->up();
        }
        (require database_path('migrations/2026_10_25_010000_add_meal_item_recipe_selectors.php'))->up();
        (require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
        $actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Синтетичний CoW admin', 'role' => 'admin']));
        $owner = DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
        $source = $this->assignmentVersion();
        $service = app(MealPlanCopier::class);
        $before = DB::table('meal_items')->orderBy('id')->get()->toJson();
        $preview = $service->preview($actor, $source, $owner);
        $copy = $service->copy($actor, $source, $owner, null, $preview['confirmation']);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertNull($copy->version_number);
        $this->assertSame($owner, $copy->plan->owner_client_id);
        $this->assertSame(DB::table('meal_plan_versions')->where('id', $source)->value('meal_plan_id'), $copy->plan->parent_meal_plan_id);
        $this->assertCount(7, $copy->days);
        $this->assertSame(14, DB::table('meal_items')->count());
        foreach ($copy->days as $day) {
            $this->assertNotContains($day->id, DB::table('meal_plan_days')->where('meal_plan_version_id', $source)->pluck('id')->all());
        }
        try {
            $service->copy($actor, $source, $owner, null, $preview['confirmation']);
            $this->fail('Replay має бути відхилений');
        } catch (ValidationException) {
            $this->assertSame(2, DB::table('meal_plans')->count());
        }
        $publisher = app(MealPlanPublisher::class);
        $publisher->publish($actor, $copy, $publisher->preview($actor, $copy));
        $assigner = app(MealPlanAssigner::class);
        $input = ['meal_plan_version_id' => $copy->id, 'start_date' => today()->toDateString(), 'expected' => $assigner->snapshot($actor, $owner)];
        $assigner->assign($actor, $owner, $input, $assigner->preview($actor, $owner, $input)['confirmation']);
        $this->assertSame(1, DB::table('client_events')->count());
        $this->assertSame($before, DB::table('meal_items')->whereIn('meal_id', DB::table('meals')->whereIn('meal_plan_day_id', DB::table('meal_plan_days')->where('meal_plan_version_id', $source)->select('id'))->select('id'))->orderBy('id')->get()->toJson());
    }

    public function test_sizes_lineage_acl_stale_and_sql_failure(): void
    {
        $this->installAuthSchema();
        foreach (glob(database_path('migrations/2026_10_24_0[123]*.php')) as $file) {
            (require $file)->up();
        }
        (require database_path('migrations/2026_10_25_010000_add_meal_item_recipe_selectors.php'))->up();
        (require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
        $actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Синтетичний CoW admin', 'role' => 'admin']));
        $owner = DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
        $source = $this->assignmentVersion();
        $service = app(MealPlanCopier::class);
        foreach ([7, 14, 21] as $size) {
            $p = $service->preview($actor, $source, $owner);
            $draft = $service->copy($actor, $source, $owner, null, $p['confirmation']);
            $this->assertCount($size === 7 ? 7 : $size - 7, $draft->days);
            if ($size > 7) {
                $repeat = app(MealPlanWeekRepeater::class);
                $input = ['source_week' => 1, 'total_weeks' => $size / 7, 'expected' => $repeat->snapshot($actor, $draft)['expected']];
                $input['confirmation'] = $repeat->preview($actor, $draft, $input)['confirmation'];
                $repeat->repeat($actor, $draft, $input);
            }
            $publisher = app(MealPlanPublisher::class);
            $publisher->publish($actor, $draft, $publisher->preview($actor, $draft));
            $source = $draft->id;
            $p = $service->preview($actor, $source, $owner);
            $result = $service->copy($actor, $source, $owner, null, $p['confirmation']);
            $this->assertCount($size, $result->days);
        }
        foreach ([[$result->id, $owner], [2147483647, $owner], [$source, 2147483647],
            [$source, DB::table('clients')->insertGetId(['coach_user_id' => $actor->id])]] as [$src, $client]) {
            try {
                $service->preview($actor, $src, $client);
                $this->fail('Очікувалась відмова');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        DB::table('uoms')->where('code', 'g')->update(['label' => 'gram']);
        $batch = $this->batch();
        $this->publish($batch['version']);
        $serving = DB::table('uoms')->insertGetId(['code' => 'serving', 'label' => 'порція', 'kind' => 'serving']);
        $meal = $result->days()->first()->meals()->first();
        foreach ([['single', 1], ['week', 7], ['half_week', 3], ['half_week', 4]] as [$format,$divisor]) {
            DB::table('meal_items')->insert(['meal_id' => $meal->id, 'food_id' => DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id'), 'quantity' => '0.50', 'uom_id' => $serving, 'recipe_version_id' => $batch['version'], 'recipe_batch_format_id' => $batch['formats'][$format], 'recipe_portion_divisor' => $divisor, 'sort_order' => -2]);
        }
        $publisher->publish($actor, $result, $publisher->preview($actor, $result));
        $p = $service->preview($actor, $result->id, $owner);
        $exact = $service->copy($actor, $result->id, $owner, null, $p['confirmation']);
        $copiedMeal = $exact->days()->first()->meals()->first();
        $normalize = fn ($id) => DB::table('meal_items')->where('meal_id', $id)->orderBy('sort_order')->orderBy('id')->get()->map(function ($row) {
            $a = (array) $row;
            unset($a['id'],$a['meal_id']);

            return $a;
        })->all();
        $this->assertSame($normalize($meal->id), $normalize($copiedMeal->id));
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewMealPlanVersion::class, ['record' => $source])
            ->assertSuccessful()->mountAction('personalCopy')->assertSuccessful()->setActionData(['owner' => $owner, 'title' => 'Копія Livewire'])->callMountedAction()->assertSuccessful()
            ->assertActionMounted('confirmPersonalCopy')->unmountAction();
        $p = $service->preview($actor, $source, $owner);
        DB::table('clients')->where('id', $owner)->update(['status' => 'paused']);
        try {
            $service->copy($actor, $source, $owner, null, $p['confirmation']);
            $this->fail('Stale');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $count = DB::table('meal_plans')->count();
        $p = $service->preview($actor, $source, $owner);
        DB::unprepared("CREATE FUNCTION cow_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic CoW fault'; END $$; CREATE TRIGGER cow_fault BEFORE INSERT ON meals FOR EACH ROW EXECUTE FUNCTION cow_fault();");
        try {
            $service->copy($actor, $source, $owner, null, $p['confirmation']);
            $this->fail('SQL fault');
        } catch (QueryException) {
            $this->assertSame($count, DB::table('meal_plans')->count());
        }
        DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
        try {
            $service->preview($actor, $source, $owner);
            $this->fail('ACL');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}

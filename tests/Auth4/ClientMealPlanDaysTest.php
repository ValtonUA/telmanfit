<?php

namespace Tests\Auth4;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MealPlans\ClientMenuFixture;

/** Успадковані T5.1 gates також виконуються для нового HTTP endpoint. */
class ClientMealPlanDaysTest extends ClientMealPlanTest
{
    use ClientMenuFixture;

    protected string $endpoint = '/api/meal-plan/days';

    protected function emptyResponse(): array
    {
        return ['assignment' => null, 'weeks' => [], 'week' => null, 'days' => []];
    }

    public static function lengths(): array
    {
        return [[7, false], [14, true], [21, false]];
    }

    #[DataProvider('lengths')]
    public function test_real_published_menu_order_model_i_and_bounded_queries(int $length, bool $personal): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        DB::table('sessions')->where('id', $this->sessionId)->update(['last_activity' => now()->timestamp]);
        $f = $this->menuVersion($length, owner: $personal ? $this->client : null);
        $this->assignment($f['version']);
        $this->read((int) DB::table('client_meal_plan_assignments')->where('client_id', $this->client)->value('id'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = $this->getJson($this->endpoint.'?week=1')->assertOk()
            ->assertJsonPath('plan_start_date', '2026-10-26')->assertJsonPath('days.0.day_number', 3)
            ->assertJsonPath('days.0.date', '2026-10-28')->assertJsonPath('days.0.meals.0.title', 'Сніданок')
            ->assertJsonPath('days.0.meals.1.title', 'Обід')
            ->assertJsonPath('days.0.meals.0.items.0.quantity_label', '125,5 г')
            ->assertJsonPath('days.0.meals.0.items.0.recipe_version_id', null)
            ->assertJsonPath('days.0.meals.0.items.1.quantity_label', '0,5 порції')
            ->assertJsonPath('days.0.meals.0.items.6.quantity_label', '2 шт.')
            ->assertJsonPath('days.0.meals.0.items.1.recipe_version_id', (string) $f['legacy']['version']);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $domainReads = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^select .* from "(?:client_meal_plan_assignments|meal_plans|meal_plan_days|meals|meal_items|foods)"/i', $q['query']));
        $this->assertCount(7, $domainReads, 'Сім batched domain SELECT незалежно від кількості карток.');
        $this->assertLessThan(60, $count, 'Обмежений загальний SQL разом із AUTH gates.');
        $this->assertCount(intdiv($length, 7), $r->json('weeks'));
        foreach (range(2, 5) as $i) {
            $r->assertJsonPath("days.0.meals.0.items.$i.recipe_version_id", (string) $f['batch']['version'])
                ->assertJsonPath("days.0.meals.0.items.$i.quantity_label", '1,25 порції');
        }
        foreach ($r->json('days.0.meals.0.items') as $item) {
            $this->assertNull($item['image_url']);
            $this->assertArrayNotHasKey('nutrition', $item);
        }
        if ($length >= 14) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $next = $this->getJson($this->endpoint.'?week=2')->assertOk()->assertJsonPath('days.0.day_number', 8)
                ->assertJsonPath('days.0.date', '2026-11-02');
            $this->assertSame($count, count(DB::getQueryLog()), '5 і 7 днів мають однакову кількість SQL.');
            DB::disableQueryLog();
            $this->assertCount(7, $next->json('days'));
        }
    }

    public function test_wednesday_start_and_end_date_never_shift_or_repeat_menu(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        DB::table('sessions')->where('id', $this->sessionId)->update(['last_activity' => now()->timestamp]);
        $f = $this->menuVersion(14, 3);
        $this->assignment($f['version'], end: 2);
        $this->getJson($this->endpoint)->assertOk()->assertJsonPath('days.0.day_number', 1)
            ->assertJsonPath('days.0.date', '2026-10-28')->assertJsonCount(3, 'days')->assertJsonCount(1, 'weeks');
        $this->getJson($this->endpoint.'?week=2')->assertOk()->assertJsonCount(0, 'days');
    }

    public function test_plan_exhaustion_and_missing_week_are_honest_empty_states(): void
    {
        $this->assignment($this->assignmentVersion(), -30);
        $this->getJson($this->endpoint)->assertOk()->assertJsonCount(1, 'weeks')->assertJsonCount(0, 'days');
        foreach (['0', '-1', 'a', '1.5', '2147483648', '1&week[]=2'] as $week) {
            $this->read(null, 422, '?week='.$week);
        }
        $this->getJson($this->endpoint.'?week=999')->assertOk()->assertJsonCount(0, 'days');
    }

    public function test_no_unassigned_versions_can_be_selected_and_newer_recipe_does_not_leak(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        DB::table('sessions')->where('id', $this->sessionId)->update(['last_activity' => now()->timestamp]);
        $f = $this->menuVersion(7);
        $id = $this->assignment($f['version']);
        $foreign = $this->assignmentVersion(status: 'draft');
        $next = DB::table('recipe_versions')->insertGetId(['recipe_id' => $f['legacy']['recipe'], 'title' => 'Не показувати нову чернетку']);
        foreach (DB::table('recipe_ingredients')->where('recipe_version_id', $f['legacy']['version'])->get() as $row) {
            $copy = (array) $row;
            unset($copy['id']);
            DB::table('recipe_ingredients')->insert(array_replace($copy, ['recipe_version_id' => $next, 'component_id' => null]));
        }
        $nextFood = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
        DB::table('recipe_versions')->where('id', $next)->update(['status' => 'published', 'food_id' => $nextFood,
            'version_number' => 2, 'published_at' => now(), 'kcal_per_serving' => '150.00',
            'protein_per_serving' => '15.00', 'fat_per_serving' => '3.00', 'carbs_per_serving' => '7.50']);
        DB::table('recipe_versions')->insert(['recipe_id' => $f['legacy']['recipe'], 'title' => 'Не показувати третю версію']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $r = $this->getJson($this->endpoint.'?client_id=999&meal_plan_version_id='.$foreign.'&recipe_version_id='.$next)
            ->assertOk()->assertJsonPath('assignment.id', (string) $id)
            ->assertJsonPath('days.0.meals.0.items.1.recipe_version_id', (string) $f['legacy']['version']);
        $this->assertStringNotContainsString('Не показувати', $r->getContent());
        // Логічна обкладинка nullable; step image не стає cover, небезпечні URL відсікаються.
        config(['presentation.image_hosts' => ['images.example.com']]);
        DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['image_url' => 'https://images.example.com/cover.jpg']);
        $this->getJson($this->endpoint)->assertJsonPath('days.0.meals.0.items.1.image_url', 'https://images.example.com/cover.jpg');
        DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['image_url' => 'http://127.0.0.1/private']);
        $this->getJson($this->endpoint)->assertJsonPath('days.0.meals.0.items.1.image_url', null);
    }

    public function test_foreign_recipe_owner_is_not_exposed_even_if_logical_ownership_changes(): void
    {
        $f = $this->menuVersion(7);
        $this->assignment($f['version']);
        $other = DB::table('clients')->insertGetId(['coach_user_id' => $this->coach]);
        DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['owner_client_id' => $other]);
        $item = $this->getJson($this->endpoint)->assertOk()->json('days.0.meals.0.items.1');
        $this->assertSame(['id', 'kind'], array_keys($item));
        $this->assertSame('unavailable', $item['kind']);
    }
}

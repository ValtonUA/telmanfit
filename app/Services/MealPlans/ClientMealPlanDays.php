<?php

namespace App\Services\MealPlans;

use App\Domain\MealPlans\MealItemKind;
use App\Support\Presentation\FoodCards;
use App\Support\Presentation\MealPlanDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Календар exact призначення; кількості — Model I, без циклів або latest. */
final class ClientMealPlanDays
{
    public function __construct(private CurrentClientMealPlan $plans, private MealItemResolver $resolver, private FoodCards $cards) {}

    public function forClient(int $client, ?int $requestedWeek): array
    {
        $assignment = $this->plans->forClient($client);
        $empty = ['assignment' => $assignment, 'weeks' => [], 'week' => $requestedWeek, 'days' => []];
        if ($assignment === null) {
            return $empty;
        }
        $version = $assignment['meal_plan_version']['id'];
        $weekday = (int) DB::table('meal_plans')->where('id', $assignment['meal_plan_version']['meal_plan_id'])->value('week_start_weekday');
        $start = CarbonImmutable::parse($assignment['start_date'], config('app.timezone'));
        $origin = $start->subDays(($start->isoWeekday() - $weekday + 7) % 7);
        $today = now(config('app.timezone'))->toDateString();
        $calendar = DB::table('meal_plan_days')->where('meal_plan_version_id', $version)->orderBy('day_number')->get()
            ->map(fn ($day) => ['id' => $day->id, 'day_number' => (int) $day->day_number,
                'date' => $origin->addDays($day->day_number - 1)->toDateString(), 'week' => intdiv($day->day_number - 1, 7) + 1])
            ->filter(fn ($day) => $day['date'] >= $assignment['start_date'] && ($assignment['end_date'] === null || $day['date'] <= $assignment['end_date']))->values();
        $weeks = $calendar->groupBy('week')->map(fn ($days, $number) => ['number' => (int) $number,
            'days' => $days->map(fn ($day) => ['day_number' => $day['day_number'], 'date' => $day['date']])->values()->all()])->values()->all();
        $week = $requestedWeek ?? (intdiv((int) $origin->diffInDays(CarbonImmutable::parse($today, config('app.timezone'))), 7) + 1);
        $selected = $calendar->where('week', $week)->values();
        $meals = DB::table('meals as m')->join('meal_types as t', 't.id', '=', 'm.meal_type_id')
            ->whereIn('m.meal_plan_day_id', $selected->pluck('id'))->orderBy('m.sort_order')->orderBy('m.id')
            ->get(['m.id', 'm.meal_plan_day_id', 't.title']);
        $items = DB::table('meal_items as i')->join('uoms as u', 'u.id', '=', 'i.uom_id')
            ->leftJoin('recipe_versions as v', 'v.food_id', '=', 'i.food_id')->leftJoin('recipes as r', 'r.id', '=', 'v.recipe_id')
            ->whereIn('i.meal_id', $meals->pluck('id'))->orderBy('i.sort_order')->orderBy('i.id')
            ->get(['i.*', 'u.label as unit_label', 'u.code as unit_code', 'r.owner_client_id']);
        $portions = $this->resolver->resolveMany($items->pluck('id')->all());
        $cards = $this->cards->lookup($items->pluck('food_id')->all());
        $presented = $items->map(function ($item) use ($client, $portions, $cards): array {
            $portion = $portions->get($item->id);
            // Неконсистентний selector або чужий personal recipe не розкриває навіть назву.
            if ($portion === null || ($item->owner_client_id !== null && (int) $item->owner_client_id !== $client)) {
                return ['meal_id' => $item->meal_id, 'value' => ['id' => (string) $item->id, 'kind' => 'unavailable']];
            }
            $ingredient = $portion->kind === MealItemKind::Ingredient;
            $quantity = MealPlanDisplay::quantity($portion->quantity);
            $unit = $ingredient ? ($item->unit_code === 'g' ? 'г' : $item->unit_label) : $this->servingLabel($quantity);

            return ['meal_id' => $item->meal_id, 'value' => ['id' => (string) $item->id,
                'kind' => $ingredient ? 'ingredient' : 'recipe', 'title' => $cards->get($item->food_id)['label'],
                'image_url' => $cards->get($item->food_id)['image'], 'quantity' => $portion->quantity,
                'quantity_label' => str_replace('.', ',', $quantity).' '.$unit,
                'recipe_version_id' => $portion->recipeVersionId === null ? null : (string) $portion->recipeVersionId]];
        })->groupBy('meal_id');

        return ['assignment' => $assignment, 'today' => $today, 'plan_start_date' => $origin->toDateString(),
            'weeks' => $weeks, 'week' => $week, 'days' => $selected->map(fn ($day) => [
                'day_number' => $day['day_number'], 'date' => $day['date'],
                'meals' => $meals->where('meal_plan_day_id', $day['id'])->map(fn ($meal) => [
                    'id' => (string) $meal->id, 'title' => $meal->title,
                    'items' => ($presented->get($meal->id) ?? collect())->pluck('value')->values()->all(),
                ])->values()->all(),
            ])->all()];
    }

    private function servingLabel(string $quantity): string
    {
        if (str_contains($quantity, '.')) {
            return 'порції';
        }
        $n = (int) $quantity;

        return $n % 10 === 1 && $n % 100 !== 11 ? 'порція' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) ? 'порції' : 'порцій');
    }
}

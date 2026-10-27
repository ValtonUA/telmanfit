<?php

namespace App\Services\MealPlans;

use App\Domain\MealPlans\MealItemKind;
use App\Services\Recipes\RecipeTokens;
use App\Support\Presentation\PublicImageUrl;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Точний published склад для приготування; спожиті порції не масштабують фізичну партію. */
final class ClientMealItemRecipe
{
    public function __construct(private CurrentClientMealPlan $plans, private ClientMealPlanDays $calendar, private MealItemResolver $resolver) {}

    public function forClient(int $client, int $itemId): array
    {
        $assignment = $this->plans->forClient($client);
        if ($assignment === null) {
            throw new DomainException;
        }
        $item = DB::table('meal_items as i')->join('meals as m', 'm.id', '=', 'i.meal_id')
            ->join('meal_plan_days as d', 'd.id', '=', 'm.meal_plan_day_id')
            ->where('i.id', $itemId)->where('d.meal_plan_version_id', $assignment['meal_plan_version']['id'])
            ->first(['i.*', 'd.day_number']);
        if ($item === null) {
            throw new DomainException;
        }
        $week = intdiv($item->day_number - 1, 7) + 1;
        $menu = $this->calendar->forClient($client, $week);
        $card = null;
        foreach ($menu['days'] as $day) {
            foreach ($day['meals'] as $meal) {
                foreach ($meal['items'] as $candidate) {
                    if ($candidate['id'] === (string) $itemId && $candidate['kind'] === 'recipe') {
                        $card = $candidate;
                    }
                }
            }
        }
        if ($card === null || $menu['assignment']['id'] !== $assignment['id']) {
            throw new DomainException;
        }
        $portion = $this->resolver->resolve($itemId);
        $version = DB::table('recipe_versions as v')->join('recipes as r', 'r.id', '=', 'v.recipe_id')
            ->where('v.id', $portion->recipeVersionId)->where('v.status', 'published')
            ->where(fn ($q) => $q->whereNull('r.owner_client_id')->orWhere('r.owner_client_id', $client))
            ->first(['v.*', 'r.image_url']);
        if ($version === null) {
            throw new DomainException;
        }
        $legacy = $portion->kind === MealItemKind::LegacyRecipe;
        $code = $legacy ? 'single' : DB::table('recipe_batch_formats')->where('id', $portion->formatId)->value('code');
        $uoms = DB::table('uoms')->get()->keyBy('id');
        $rows = DB::table('recipe_ingredients as ri')->join('ingredients as i', 'i.id', '=', 'ri.ingredient_id')
            ->leftJoin('recipe_components as c', 'c.id', '=', 'ri.component_id')
            ->where('ri.recipe_version_id', $version->id)->orderByRaw('c.sort_order NULLS LAST')->orderBy('c.id')
            ->orderBy('ri.sort_order')->orderBy('ri.id')->get(['ri.*', 'i.name', 'c.title as component']);
        if ($rows->isEmpty()) {
            throw new DomainException;
        }
        $frozen = DB::table('recipe_batch_quantities')->where('format_id', $portion->formatId)->get()->keyBy('recipe_ingredient_id');
        $ingredients = $tokens = [];
        foreach ($rows as $row) {
            $q = $row;
            $snapshot = null;
            if (! $legacy && $code !== 'single') {
                $q = $frozen->get($row->id);
                if ($q === null || (int) $q->recipe_version_id !== $version->id) {
                    throw new DomainException;
                }
                $snapshot = json_decode($q->input_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if (($snapshot['schema_version'] ?? null) != 1 || ! is_string($snapshot['uom_label'] ?? null)) {
                    throw new DomainException;
                }
                $this->positive($snapshot['factor'] ?? null);
            }
            $this->positive($q->quantity);
            $this->positive($q->quantity_grams);
            $unit = $snapshot['uom_label'] ?? ($uoms->get($q->uom_id)?->label);
            if ($unit === null) {
                throw new DomainException;
            }
            $name = $legacy ? $row->name : (json_decode($row->ingredient_snapshot ?? 'null', true, flags: JSON_THROW_ON_ERROR)['name'] ?? null);
            if (! is_string($name) || $name === '') {
                throw new DomainException;
            }
            $ingredients[] = ['id' => (string) $row->id, 'name' => $name, 'quantity' => $q->quantity,
                'unit_label' => $unit, 'quantity_grams' => $q->quantity_grams, 'component' => $row->component];
            $tokens[$row->id][$code] = ['quantity' => $q->quantity, 'grams' => $q->quantity_grams, 'uom' => $unit];
        }
        $equipment = $equipmentTokens = [];
        $resolvedEquipment = DB::table('recipe_batch_equipment')->where('format_id', $portion->formatId)->get()->keyBy('recipe_kitchen_equipment_id');
        foreach (DB::table('recipe_kitchen_equipment as e')->join('kitchen_equipment as k', 'k.id', '=', 'e.kitchen_equipment_id')
            ->where('e.recipe_version_id', $version->id)->orderBy('e.id')->get(['e.*', 'k.name']) as $row) {
            $q = $legacy ? $row : $resolvedEquipment->get($row->id);
            if ($q === null || (int) $q->recipe_version_id !== $version->id || ($q->uom_id !== null && ! $uoms->has($q->uom_id))) {
                throw new DomainException;
            }
            if ($q->min_value !== null) {
                $this->positive($q->min_value);
            }
            $value = ['name' => $row->name, 'min_value' => $q->min_value,
                'uom' => $uoms->get($q->uom_id)?->label ?? '', 'tip' => $q->tip ?? ''];
            $equipmentTokens[$row->id][$code] = $value;
            $equipment[] = ['id' => (string) $row->id, 'name' => $value['name'], 'min_value' => $value['min_value'],
                'unit_label' => $value['uom'], 'tip' => $value['tip']];
        }
        $directions = DB::table('recipe_directions')->where('recipe_version_id', $version->id)->orderBy('direction_number')->get()
            ->map(fn ($step) => ['number' => $step->direction_number,
                'text' => RecipeTokens::render($step->direction_text, $tokens, $code, $equipmentTokens),
                'image_url' => PublicImageUrl::safe($step->image_url)])->all();
        $this->positive($version->servings_count);

        return ['meal_item_id' => (string) $itemId, 'assignment_id' => $assignment['id'], 'week' => $week, 'day_number' => $item->day_number,
            'recipe_version_id' => (string) $version->id, 'title' => $version->title, 'image_url' => PublicImageUrl::safe($version->image_url),
            'assigned_servings' => $portion->quantity, 'assigned_servings_label' => $card['quantity_label'],
            'format' => $legacy ? null : match ($code) {
                'single' => 'A/1', 'week' => 'B/7', 'half_week' => 'C/'.$portion->divisor
            },
            'preparation_servings' => $legacy ? $version->servings_count : (string) $portion->divisor,
            'ingredients' => $ingredients, 'equipment' => $equipment, 'directions' => $directions];
    }

    private function positive(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^\d+(\.\d+)?$/D', $value) || BigDecimal::of($value)->isLessThanOrEqualTo(0)) {
            throw new DomainException;
        }
    }
}

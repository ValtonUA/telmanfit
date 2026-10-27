<?php

namespace App\Services\MealPlans;

use App\Domain\MealPlans\MealItemKind;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Пропорційна потреба exact призначення; без закупівельного округлення або записів. */
final class ClientShoppingList
{
    public function __construct(private ClientMealPlanDays $calendar, private MealItemResolver $resolver) {}

    public function forClient(int $client, ?int $week): array
    {
        $menu = $this->calendar->forClient($client, $week);
        $response = ['assignment' => $menu['assignment'], 'weeks' => $menu['weeks'], 'week' => $menu['week'],
            'days' => array_map(fn ($day) => array_intersect_key($day, array_flip(['day_number', 'date'])), $menu['days']),
            'state' => $menu['assignment'] === null ? 'no_assignment' : ($menu['days'] === [] ? 'week_unavailable' : 'ready'), 'items' => []];
        $ids = [];
        foreach ($menu['days'] as $day) {
            foreach ($day['meals'] as $meal) {
                foreach ($meal['items'] as $item) {
                    if ($item['kind'] === 'unavailable') {
                        throw new DomainException('Список покупок недоступний.');
                    }
                    $ids[] = (int) $item['id'];
                }
            }
        }
        if ($ids === []) {
            return $response;
        }
        $items = DB::table('meal_items as m')->whereIn('m.id', $ids)
            ->leftJoin('ingredients as i', 'i.food_id', '=', 'm.food_id')
            ->leftJoin('recipe_versions as v', 'v.food_id', '=', 'm.food_id')
            ->leftJoin('recipes as r', 'r.id', '=', 'v.recipe_id')
            ->get(['m.*', 'i.id as ingredient_id', 'v.servings_count', 'r.owner_client_id']);
        $portions = $this->resolver->resolveMany($ids);
        $versions = $portions->filter()->pluck('recipeVersionId')->filter()->unique()->values()->all();
        $rows = DB::table('recipe_ingredients')->whereIn('recipe_version_id', $versions)->orderBy('id')->get()->groupBy('recipe_version_id');
        $quantities = DB::table('recipe_batch_quantities')->whereIn('recipe_version_id', $versions)->get()
            ->keyBy(fn ($row) => $row->format_id.':'.$row->recipe_ingredient_id);
        $ingredients = DB::table('ingredients')->whereIn('id', $rows->flatten()->pluck('ingredient_id')->merge($items->pluck('ingredient_id'))->filter()->unique())
            ->get(['id', 'name'])->keyBy('id');
        $uoms = DB::table('uoms')->get(['id', 'code', 'label'])->keyBy('id');
        $totals = [];
        if ($items->count() !== count($ids)) {
            throw new DomainException('Список покупок недоступний.');
        }
        foreach ($items as $item) {
            $portion = $portions->get($item->id);
            if ($portion === null || ($item->owner_client_id !== null && (int) $item->owner_client_id !== $client)) {
                throw new DomainException('Список покупок недоступний.');
            }
            if ($portion->kind === MealItemKind::Ingredient) {
                $this->add($totals, $ingredients, $uoms, $item->ingredient_id, $item->uom_id,
                    $item->quantity, $item->resolved_quantity_grams, BigRational::of(1));

                continue;
            }
            $composition = $rows->get($portion->recipeVersionId);
            if ($composition === null || $composition->isEmpty()) {
                throw new DomainException('Відсутній склад рецепта.');
            }
            $scale = $this->positive($portion->quantity)->dividedBy(
                $portion->kind === MealItemKind::LegacyRecipe ? $this->positive($item->servings_count) : $portion->divisor);
            foreach ($composition as $row) {
                if (in_array($portion->kind, [MealItemKind::LegacyRecipe, MealItemKind::BatchA], true)) {
                    $quantity = $row->quantity;
                    $grams = $row->quantity_grams;
                    $uom = $row->uom_id;
                    $snapshot = null;
                } else {
                    $frozen = $quantities->get($portion->formatId.':'.$row->id);
                    if ($frozen === null) {
                        throw new DomainException('Відсутній frozen склад формату.');
                    }
                    $quantity = $frozen->quantity;
                    $grams = $frozen->quantity_grams;
                    $uom = $frozen->uom_id;
                    $snapshot = json_decode($frozen->input_snapshot, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_string($snapshot['uom_label'] ?? null) || ! isset($snapshot['factor'])) {
                        throw new DomainException('Відсутня frozen конверсія.');
                    }
                    $this->positive($snapshot['factor']);
                }
                $this->add($totals, $ingredients, $uoms, $row->ingredient_id, $uom, $quantity, $grams, $scale, $snapshot);
            }
        }
        ksort($totals, SORT_NUMERIC);
        foreach ($totals as $id => $total) {
            ksort($total['sources'], SORT_STRING);
            $response['items'][] = ['ingredient_id' => (string) $id, 'name' => $ingredients->get($id)->name,
                ...$this->amount($total['grams']), 'source_quantities' => array_values(array_map(fn ($source) => [
                    'uom_id' => $source['uom_id'], 'unit_label' => $source['unit_label'],
                    'quantity' => (string) $source['quantity']->toScale(6, RoundingMode::HalfUp),
                    'quantity_exact' => (string) $source['quantity'],
                    'grams_per_unit_exact' => $source['ratio'], 'conversion_basis' => $source['basis'],
                ], $total['sources']))];
        }

        return $response;
    }

    private function positive(mixed $value): BigRational
    {
        if (! is_string($value) || ! preg_match('/^\d+(\.\d+)?$/D', $value) || BigRational::of($value)->isLessThanOrEqualTo(0)) {
            throw new DomainException('Некоректна кількість інгредієнта.');
        }

        return BigRational::of($value);
    }

    private function amount(BigRational $grams): array
    {
        return ['quantity_grams' => (string) $grams->toScale(6, RoundingMode::HalfUp),
            'quantity_grams_exact' => (string) $grams];
    }

    private function add(array &$totals, Collection $ingredients, Collection $uoms, ?int $id, int $uomId, mixed $quantity, mixed $grams, BigRational $scale, ?array $snapshot = null): void
    {
        $uom = $uoms->get($uomId);
        if ($id === null || ! $ingredients->has($id) || $uom === null) {
            throw new DomainException('Відсутній інгредієнт або одиниця.');
        }
        $q = $this->positive($quantity);
        $g = $this->positive($grams);
        $ratio = (string) ($snapshot === null ? $g->dividedBy($q) : $this->positive($snapshot['factor']));
        $basis = $snapshot === null ? 'stored_ratio' : 'frozen_conversion';
        $label = $snapshot['uom_label'] ?? $uom->label;
        // Непідтверджені historical ratios не ототожнюються з frozen packaging conversions.
        $key = json_encode([$uomId, $ratio, $basis, $label], JSON_THROW_ON_ERROR);
        $totals[$id] ??= ['grams' => BigRational::of(0), 'sources' => []];
        $totals[$id]['grams'] = $totals[$id]['grams']->plus($g->multipliedBy($scale));
        $totals[$id]['sources'][$key] ??= ['uom_id' => (string) $uomId, 'unit_label' => $label,
            'ratio' => $ratio, 'basis' => $basis, 'quantity' => BigRational::of(0)];
        $totals[$id]['sources'][$key]['quantity'] = $totals[$id]['sources'][$key]['quantity']->plus($q->multipliedBy($scale));
    }
}

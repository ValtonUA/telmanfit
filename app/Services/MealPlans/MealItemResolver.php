<?php

namespace App\Services\MealPlans;

use App\Domain\MealPlans\MealItemKind;
use App\Domain\MealPlans\MealItemPortion;
use App\Models\MealItem;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Readonly: один SQL snapshot, exact food/version/format, без latest або recalculation. */
final class MealItemResolver
{
    public function resolve(int|MealItem $item): MealItemPortion
    {
        $id = $item instanceof MealItem ? $item->getKey() : $item;

        return $this->read(DB::table('meal_items as m')->where('m.id', $id));
    }

    /** Readonly preview використовує ті самі joins та Model I, без тимчасового INSERT. */
    public function preview(array $selection): MealItemPortion
    {
        $row = DB::query();
        foreach (['food_id' => 'integer', 'quantity' => 'numeric', 'uom_id' => 'smallint',
            'resolved_quantity_grams' => 'numeric', 'recipe_version_id' => 'integer',
            'recipe_batch_format_id' => 'integer', 'recipe_portion_divisor' => 'smallint'] as $field => $type) {
            $row->selectRaw("CAST(? AS {$type}) AS {$field}", [$selection[$field] ?? null]);
        }

        return $this->read(DB::query()->fromSub($row, 'm'));
    }

    public function resolveMany(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $this->joined(DB::table('meal_items as m')->whereIn('m.id', $ids))->get()->mapWithKeys(function ($row): array {
            try {
                return [$row->id => $this->portion($row)];
            } catch (DomainException) {
                return [$row->id => null];
            }
        });
    }

    private function read(Builder $query): MealItemPortion
    {
        return $this->portion($this->joined($query)->first());
    }

    private function joined(Builder $query): Builder
    {
        return $query->join('foods as f', 'f.id', '=', 'm.food_id')
            ->join('uoms as u', 'u.id', '=', 'm.uom_id')
            ->leftJoin('ingredients as i', 'i.food_id', '=', 'f.id')
            ->leftJoin('ingredient_uoms as iu', fn ($join) => $join->on('iu.ingredient_id', '=', 'i.id')->on('iu.uom_id', '=', 'm.uom_id'))
            ->leftJoin('recipe_versions as v', 'v.food_id', '=', 'f.id')
            ->leftJoin('recipe_batch_formats as b', 'b.id', '=', 'm.recipe_batch_format_id')
            ->select(['m.*', 'f.food_type', 'u.code as uom_code', 'i.id as ingredient_id',
                'v.id as version_id', 'v.status', 'v.editor_contract', 'b.recipe_version_id as format_version_id', 'b.code as format_code',
                'iu.ingredient_id as permitted_ingredient_id', 'i.kcal_per_100g', 'i.protein_per_100g', 'i.fat_per_100g', 'i.carbs_per_100g',
                'v.kcal_per_serving', 'v.protein_per_serving', 'v.fat_per_serving', 'v.carbs_per_serving',
                'b.kcal_total', 'b.protein_total', 'b.fat_total', 'b.carbs_total']);
    }

    private function portion(?object $r): MealItemPortion
    {
        if ($r === null) {
            throw new DomainException('Позицію не знайдено.');
        }
        $selected = count(array_filter([$r->recipe_version_id, $r->recipe_batch_format_id, $r->recipe_portion_divisor], fn ($v) => $v !== null));
        $kind = null;
        $version = null;
        $format = null;
        $divisor = null;
        $quantity = $r->quantity;
        $nutritionQuantity = $quantity;
        $nutritionDivisor = 1;
        if ($r->food_type === 'ingredient' && $r->ingredient_id !== null && $selected === 0 && $r->resolved_quantity_grams !== null) {
            // Чинний ingredient contract: historical resolved grams, без refresh conversions.
            $allowed = $r->uom_code === 'g' || $r->permitted_ingredient_id !== null;
            if (! $allowed) {
                throw new DomainException('Недозволена одиниця інгредієнта.');
            }
            $kind = MealItemKind::Ingredient;
            $suffix = '_per_100g';
            $nutritionQuantity = $r->resolved_quantity_grams;
            $nutritionDivisor = 100;
        } elseif ($r->food_type === 'recipe_version' && $r->status === 'published' && $r->uom_code === 'serving' && $r->resolved_quantity_grams === null) {
            $version = (int) $r->version_id;
            if ($r->editor_contract === 'legacy_v1' && $selected === 0) {
                $kind = MealItemKind::LegacyRecipe;
                $suffix = '_per_serving';
            } elseif ($r->editor_contract === 'batch_v1' && $selected === 3 && (int) $r->recipe_version_id === $version && (int) $r->format_version_id === $version) {
                $format = (int) $r->recipe_batch_format_id;
                $divisor = (int) $r->recipe_portion_divisor;
                $kind = match ([$r->format_code, $divisor]) {
                    ['single', 1] => MealItemKind::BatchA,
                    ['week', 7] => MealItemKind::BatchB,
                    ['half_week', 3] => MealItemKind::BatchC3,
                    ['half_week', 4] => MealItemKind::BatchC4,
                    default => null,
                };
                $suffix = '_total';
                $nutritionDivisor = $divisor;
            }
        }
        if ($kind === null) {
            throw new DomainException('Неконсистентний meal item selector; fallback заборонений.');
        }
        // Валідуємо quantity також для ingredient, де nutrition використовує grams.
        $totals = [];
        foreach (['kcal', 'protein', 'fat', 'carbs'] as $key) {
            $totals[$key] = $r->{$key.$suffix};
        }
        MealItemPortion::nutrition($totals, $quantity, 1);

        return new MealItemPortion((int) $r->food_id, $kind, $version, $format, $divisor, $quantity,
            MealItemPortion::nutrition($totals, $nutritionQuantity, $nutritionDivisor));
    }
}

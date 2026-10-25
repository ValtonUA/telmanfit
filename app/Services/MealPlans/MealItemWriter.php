<?php

namespace App\Services\MealPlans;

use App\Domain\MealPlans\MealItemPortion;
use App\Models\MealItem;
use App\Models\MealPlanVersion;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** T4.3: спільний version fence T4.2 → days → meals → items → recipe/ingredient → UOM. */
final class MealItemWriter
{
    public static function token(MealItem $item): string
    {
        return hash('sha256', json_encode($item->getAttributes(), JSON_THROW_ON_ERROR));
    }

    /** Стан прийому для add: повторний submit не створює другу позицію. */
    public function mealToken(User $actor, MealPlanVersion $version, int $mealId): string
    {
        Gate::forUser($actor)->authorize('editItems', $version);
        $meal = DB::table('meals')->where('id', $mealId)->whereIn('meal_plan_day_id', $version->days()->select('id'))->first();
        if (! $meal) {
            $this->invalid('meal_id', 'Прийом не належить цій точній чернетці.');
        }

        return hash_hmac('sha256', json_encode([$actor->id, $version->id, $meal,
            DB::table('meal_items')->where('meal_id', $mealId)->orderBy('id')->get()->all()], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** Readonly catalog snapshot: зміна conversion/default/UOM після вибору потребує reload. */
    public function catalog(User $actor, MealPlanVersion $version, int $foodId, bool $lock = false): array
    {
        Gate::forUser($actor)->authorize('editItems', $version);
        $food = DB::table('foods')->find($foodId);
        if (! $food) {
            $this->invalid('food_id', 'Оберіть наявний продукт або опублікований рецепт.');
        }
        $query = $food->food_type === 'ingredient'
            ? DB::table('ingredients')->where('food_id', $foodId)
            : DB::table('recipe_versions')->where('food_id', $foodId);
        $root = ($lock ? $query->sharedLock() : $query)->first();
        if (! $root || ($food->food_type !== 'ingredient' && $root->status !== 'published')) {
            $this->invalid('food_id', 'Доступні лише інгредієнти та точні опубліковані версії рецептів.');
        }
        // Catalog writer завжди блокує ingredient перед conversions та UOM.
        $conversions = $food->food_type === 'ingredient' ? DB::table('ingredient_uoms')->where('ingredient_id', $root->id)->orderBy('uom_id')->get() : collect();
        $units = DB::table('uoms')->where(function ($q) use ($food, $conversions): void {
            $q->where('code', $food->food_type === 'ingredient' ? 'g' : 'serving');
            if ($food->food_type === 'ingredient') {
                $q->orWhereIn('id', $conversions->pluck('uom_id'));
            }
        })->orderBy('id');
        $units = ($lock ? $units->sharedLock() : $units)->get();
        // Published recipe root захищає frozen formats: child locks не інвертуємо.
        $formats = $food->food_type === 'recipe_version' ? DB::table('recipe_batch_formats')->where('recipe_version_id', $root->id)->orderBy('id')->get() : collect();
        $token = hash_hmac('sha256', json_encode([$actor->id, $version->id, $food, $root, $conversions->all(), $units->all(), $formats->all()], JSON_THROW_ON_ERROR), config('app.key'));
        $default = $conversions->firstWhere('is_default', true)?->uom_id ?? $units->firstWhere('code', $food->food_type === 'ingredient' ? 'g' : 'serving')?->id;

        return compact('food', 'root', 'conversions', 'units', 'formats', 'token', 'default');
    }

    public function preview(User $actor, MealPlanVersion $version, array $input): MealItemPortion
    {
        return app(MealItemResolver::class)->preview($this->selection($actor, $version, $input));
    }

    public function save(User $actor, MealPlanVersion $version, array $input, ?int $itemId = null): MealItem
    {
        Gate::forUser($actor)->authorize('editItems', $version);

        return app(MealPlanStructure::class)->withinDraft($actor, $version, function ($locked) use ($actor, $input, $itemId): MealItem {
            $data = Validator::make($input, [
                'meal_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
                'expected' => ['required', 'string', 'size:64'],
                'catalog_expected' => ['required', 'string', 'size:64'],
                'sort_order' => ['required', 'integer', 'between:-32768,32767'],
            ])->validate();
            $meals = $this->lockHierarchy($locked);
            if (! $meals->contains('id', (int) $data['meal_id'])) {
                $this->invalid('meal_id', 'Прийом не належить цій точній чернетці.');
            }
            $item = $itemId === null ? new MealItem : MealItem::whereIn('meal_id', $meals->pluck('id'))->whereKey($itemId)->first();
            $expected = $itemId === null ? $this->mealToken($actor, $locked, (int) $data['meal_id']) : ($item ? self::token($item) : '');
            if (! $item || ! hash_equals($expected, $data['expected'])) {
                $this->invalid('expected', 'Позицію або прийом змінено. Закрийте діалог і перечитайте актуальні дані.');
            }
            // Редагування не підміняє food; заміна продукту — окреме явне add/delete.
            if ($itemId !== null && (string) $item->food_id !== (string) ($input['food_id'] ?? '')) {
                $this->invalid('food_id', 'Продукт наявної позиції незмінний.');
            }
            $selection = $this->selection($actor, $locked, $input, true);
            foreach ($selection + ['meal_id' => (int) $data['meal_id'], 'sort_order' => (int) $data['sort_order']] as $key => $value) {
                $item->{$key} = $value;
            }
            $item->save();
            // Канонічний Model I reader також перевіряє записаний exact selector до commit.
            app(MealItemResolver::class)->resolve($item);

            return $item->refresh();
        }, function ($locked) use ($actor, $input): void {
            $catalog = $this->catalog($actor, $locked, (int) $input['food_id'], true);
            if (! hash_equals($catalog['token'], $input['catalog_expected'])) {
                $this->invalid('catalog_expected', 'Залежності змінилися перед commit. Перечитайте продукт.');
            }
        });
    }

    public function move(User $actor, MealPlanVersion $version, int $itemId, int $mealId, int $sortOrder, string $expected): void
    {
        Gate::forUser($actor)->authorize('editItems', $version);
        app(MealPlanStructure::class)->withinDraft($actor, $version, function ($locked) use ($itemId, $mealId, $sortOrder, $expected): void {
            Validator::make(['sort_order' => $sortOrder], ['sort_order' => ['integer', 'between:-32768,32767']])->validate();
            $meals = $this->lockHierarchy($locked);
            $item = $this->existing($meals->pluck('id')->all(), $itemId, $expected);
            if (! $meals->contains('id', $mealId)) {
                $this->invalid('meal_id', 'Перенесення дозволене лише всередині тієї самої точної чернетки.');
            }
            $item->meal_id = $mealId;
            $item->sort_order = $sortOrder;
            $item->save();
        });
    }

    public function delete(User $actor, MealPlanVersion $version, int $itemId, string $expected): void
    {
        Gate::forUser($actor)->authorize('editItems', $version);
        app(MealPlanStructure::class)->withinDraft($actor, $version, function ($locked) use ($itemId, $expected): void {
            $meals = $this->lockHierarchy($locked);
            $this->existing($meals->pluck('id')->all(), $itemId, $expected)->delete();
        });
    }

    private function existing(array $meals, int $id, string $expected): MealItem
    {
        $item = MealItem::whereIn('meal_id', $meals)->whereKey($id)->first();
        if (! $item || ! hash_equals(self::token($item), $expected)) {
            $this->invalid('expected', 'Позицію змінено, видалено або вона належить іншій версії. Оновіть сторінку.');
        }

        return $item;
    }

    private function lockHierarchy(MealPlanVersion $version): Collection
    {
        $days = $version->days()->orderBy('id')->lockForUpdate()->pluck('id');
        $meals = DB::table('meals')->whereIn('meal_plan_day_id', $days)->orderBy('id')->lockForUpdate()->get();
        DB::table('meal_items')->whereIn('meal_id', $meals->pluck('id'))->orderBy('id')->lockForUpdate()->get();

        return $meals;
    }

    private function selection(User $actor, MealPlanVersion $version, array $input, bool $lock = false): array
    {
        $data = Validator::make($input, [
            'food_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'uom_id' => ['required', 'integer', 'between:1,32767'],
            'quantity' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?\z/', 'not_in:0,0.0,0.00'],
            'recipe_version_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'recipe_batch_format_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'recipe_portion_divisor' => ['nullable', 'integer', 'in:1,3,4,7'],
        ])->validate();
        $catalog = $this->catalog($actor, $version, (int) $data['food_id'], $lock);
        if ($lock && ! hash_equals($catalog['token'], $input['catalog_expected'])) {
            $this->invalid('catalog_expected', 'Каталог або конверсії змінилися. Перечитайте продукт і перевірте розрахунок.');
        }
        $unit = $catalog['units']->firstWhere('id', (int) $data['uom_id']);
        if (! $unit) {
            $this->invalid('uom_id', 'Оберіть дозволену одиницю цього продукту.');
        }
        $row = ['food_id' => (int) $data['food_id'], 'quantity' => (string) BigDecimal::of($data['quantity'])->toScale(2),
            'uom_id' => (int) $data['uom_id'], 'resolved_quantity_grams' => null];
        foreach (['recipe_version_id', 'recipe_batch_format_id', 'recipe_portion_divisor'] as $field) {
            $row[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }
        $hasSelectors = count(array_filter([$row['recipe_version_id'], $row['recipe_batch_format_id'], $row['recipe_portion_divisor']], fn ($v) => $v !== null));
        if ($catalog['food']->food_type === 'ingredient') {
            if ($hasSelectors !== 0) {
                $this->invalid('recipe_batch_format_id', 'Інгредієнт не має формату рецепта.');
            }
            $factor = $unit->code === 'g' ? '1.00' : $catalog['conversions']->firstWhere('uom_id', $unit->id)?->grams_per_uom;
            $grams = BigDecimal::of($row['quantity'])->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp);
            if ($grams->isLessThanOrEqualTo(0) || $grams->isGreaterThan('99999.99')) {
                $this->invalid('quantity', 'Грамування має бути від 0.01 до 99999.99 г.');
            }
            $row['resolved_quantity_grams'] = (string) $grams;
        } elseif ($catalog['root']->editor_contract === 'legacy_v1' && $hasSelectors !== 0) {
            $this->invalid('recipe_batch_format_id', 'Legacy рецепт використовує лише порції без формату.');
        }
        // Єдина матриця exact version/format/divisor та nutrition — наявний Model I resolver.
        try {
            app(MealItemResolver::class)->preview($row);
        } catch (\DomainException $e) {
            $this->invalid('recipe_batch_format_id', $e->getMessage());
        }

        return $row;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

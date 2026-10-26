<?php

namespace App\Services\MealPlans;

use App\Models\MealPlanVersion;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Exact draft → published; без перепризначення, refresh або зміни Model I. */
final class MealPlanPublisher
{
    public function preview(User $actor, MealPlanVersion $version): string
    {
        return app(MealPlanStructure::class)->withinDraft($actor, $version, function ($locked) use ($actor): string {
            $state = app(MealPlanWeekRepeater::class)->snapshot($actor, $locked);
            $this->validate($state);

            return $this->token($state['expected']);
        });
    }

    public function publish(User $actor, MealPlanVersion $version, string $expected): MealPlanVersion
    {
        abort_unless(MealPlanStructure::allowed($actor, $version), 403);

        return DB::transaction(function () use ($actor, $version, $expected): MealPlanVersion {
            DB::table('auth_security_runtime')->where('id', 1)->sharedLock()->first();
            User::whereKey($actor->id)->sharedLock()->firstOrFail();
            $locked = MealPlanVersion::whereKey($version->id)->where('meal_plan_id', $version->meal_plan_id)->lockForUpdate()->firstOrFail();
            abort_unless(MealPlanStructure::allowed($actor, $locked), 403);
            $state = app(MealPlanWeekRepeater::class)->snapshot($actor, $locked);
            if (! hash_equals($this->token($state['expected']), $expected)) {
                throw ValidationException::withMessages(['publication' => 'Раціон змінився. Перевірте й підтвердьте публікацію заново.']);
            }
            $this->validate($state);
            // Серіалізація нумерації в межах логічного plan, без нових полів або foods.
            DB::table('meal_plans')->where('id', $locked->meal_plan_id)->lockForUpdate()->sole();
            $number = (int) MealPlanVersion::where('meal_plan_id', $locked->meal_plan_id)->max('version_number') + 1;
            if ($number > 32767) {
                throw ValidationException::withMessages(['publication' => 'Вичерпано діапазон номерів версій раціону.']);
            }
            $locked->status = 'published';
            $locked->version_number = $number;
            $locked->published_at = now();
            $locked->save();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            abort_unless(MealPlanFoundation::allowed($actor), 403);
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $locked->refresh();
        });
    }

    private function token(string $snapshot): string
    {
        return hash_hmac('sha256', 'publish-plan:'.$snapshot, config('app.key'));
    }

    private function validate(array $state): void
    {
        $days = collect($state['days']);
        $meals = collect($state['meals']);
        $items = collect($state['items']);
        $errors = [];
        $numbers = $days->pluck('day_number')->all();
        if (count($numbers) < 7 || count($numbers) % 7 !== 0 || $numbers !== range(1, count($numbers))) {
            $errors['weeks'][] = 'Раціон має містити повні послідовні тижні по 7 днів із нумерацією 1…N.';
            for ($n = 1; $n <= (int) (ceil(((int) $days->max('day_number')) / 7) * 7); $n++) {
                if (! in_array($n, $numbers, true)) {
                    $errors['day_'.$n][] = 'День '.$n.': відсутній у структурі раціону.';
                }
            }
        }
        // Root SHARE захищає conversion phantoms; той самий порядок, що й writer/catalog.
        $ingredients = DB::table('ingredients')->whereIn('food_id', $items->pluck('food_id'))->orderBy('id')->sharedLock()->get()->keyBy('food_id');
        DB::table('recipe_versions')->whereIn('food_id', $items->pluck('food_id'))->orderBy('id')->sharedLock()->get();
        $conversions = DB::table('ingredient_uoms')->whereIn('ingredient_id', $ingredients->pluck('id'))->orderBy('ingredient_id')->orderBy('uom_id')->get();
        $units = DB::table('uoms')->whereIn('id', $items->pluck('uom_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $types = DB::table('meal_types')->whereIn('id', $meals->pluck('meal_type_id'))->pluck('title', 'id');
        $portions = app(MealItemResolver::class)->resolveMany($items->pluck('id')->all());
        foreach ($days as $day) {
            $dayMeals = $meals->where('meal_plan_day_id', $day->id);
            if ($dayMeals->isEmpty()) {
                $errors['day_'.$day->day_number][] = 'День '.$day->day_number.': немає жодного прийому їжі.';
            }
            foreach ($dayMeals as $meal) {
                $label = 'День '.$day->day_number.' · '.($meal->title ?: $types[$meal->meal_type_id]).' (#'.$meal->id.')';
                $mealItems = $items->where('meal_id', $meal->id);
                if ($mealItems->isEmpty()) {
                    $errors['meal_'.$meal->id][] = $label.': немає жодної позиції.';
                }
                foreach ($mealItems as $item) {
                    $problem = $portions->get($item->id) === null ? 'Некоректна позиція, одиниця або точна версія/формат рецепта.' : null;
                    if ($ingredient = $ingredients->get($item->food_id)) {
                        $factor = ($units->get($item->uom_id)?->code === 'g') ? '1'
                            : $conversions->where('ingredient_id', $ingredient->id)->firstWhere('uom_id', $item->uom_id)?->grams_per_uom;
                        if ($factor === null || $item->resolved_quantity_grams === null
                            || ! BigDecimal::of($item->quantity)->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp)->isEqualTo($item->resolved_quantity_grams)) {
                            $problem = 'Грамування не відповідає quantity × чинний grams_per_uom. Відредагуйте позицію.';
                        }
                    }
                    if ($problem !== null) {
                        $errors['item_'.$item->id][] = $label.' · позиція #'.$item->id.': '.$problem;
                    }
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

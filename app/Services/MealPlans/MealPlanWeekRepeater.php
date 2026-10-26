<?php

namespace App\Services\MealPlans;

use App\Models\MealPlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Фізичний повтор у draft без повторного resolution або зв'язку між копіями. */
final class MealPlanWeekRepeater
{
    public function __construct(private MealPlanStructure $structure) {}

    /** Readonly snapshot усієї exact version; xmin також виявляє ABA-правки. */
    public function snapshot(User $actor, MealPlanVersion $version): array
    {
        return $this->structure->withinDraft($actor, $version, fn ($locked) => $this->state($actor, $locked));
    }

    public function preview(User $actor, MealPlanVersion $version, array $input): array
    {
        return $this->structure->withinDraft($actor, $version, function ($locked) use ($actor, $input): array {
            [$state, $source, $total] = $this->validated($actor, $locked, $input);
            $targets = range($source + 1, $total);
            $replacements = [];
            foreach ($targets as $number) {
                $ids = collect($state['days'])->whereBetween('day_number', [($number - 1) * 7 + 1, $number * 7])->pluck('id');
                $meals = collect($state['meals'])->whereIn('meal_plan_day_id', $ids)->pluck('id');
                if ($ids->isNotEmpty()) {
                    $replacements[$number] = ['meal_count' => $meals->count(),
                        'item_count' => collect($state['items'])->whereIn('meal_id', $meals)->count()];
                }
            }

            return ['source_week' => $source, 'total_weeks' => $total, 'expected' => $state['expected'],
                'replacements' => $replacements, 'confirmation' => $replacements === [] ? null : $this->grant($actor, $locked, $source, $total, $state['expected'])];
        });
    }

    public function repeat(User $actor, MealPlanVersion $version, array $input): void
    {
        $this->structure->withinDraft($actor, $version, function ($locked) use ($actor, $input): void {
            [$state, $source, $total] = $this->validated($actor, $locked, $input);
            $preview = $this->preview($actor, $locked, $input);
            if ($preview['replacements'] !== [] && (! is_string($input['confirmation'] ?? null)
                || ! hash_equals($preview['confirmation'], $input['confirmation']))) {
                $this->invalid('confirmation', 'Наявні цільові тижні потребують окремого явного підтвердження.');
            }
            $days = collect($state['days']);
            $meals = collect($state['meals']);
            $items = collect($state['items']);
            $sourceDays = $days->whereBetween('day_number', [($source - 1) * 7 + 1, $source * 7]);
            $targetDays = $days->where('day_number', '>', $source * 7);
            $targetMeals = $meals->whereIn('meal_plan_day_id', $targetDays->pluck('id'));
            // FK RESTRICT: видаляємо лише явно вибрані цілі цієї чернетки.
            DB::table('meal_items')->whereIn('meal_id', $targetMeals->pluck('id'))->delete();
            DB::table('meals')->whereIn('id', $targetMeals->pluck('id'))->delete();
            DB::table('meal_plan_days')->whereIn('id', $targetDays->pluck('id'))->delete();
            foreach (range($source + 1, $total) as $number) {
                foreach ($sourceDays as $day) {
                    $dayId = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $locked->id,
                        'day_number' => $day->day_number + ($number - $source) * 7]);
                    foreach ($meals->where('meal_plan_day_id', $day->id)->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $meal) {
                        $copy = (array) $meal;
                        unset($copy['id'], $copy['revision']);
                        $copy['meal_plan_day_id'] = $dayId;
                        $mealId = DB::table('meals')->insertGetId($copy);
                        foreach ($items->where('meal_id', $meal->id)->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $item) {
                            $copy = (array) $item;
                            unset($copy['id'], $copy['revision']);
                            $copy['meal_id'] = $mealId;
                            DB::table('meal_items')->insert($copy);
                        }
                    }
                }
            }
            // Інвалідує старі editor tokens, включно з повторним submit та ABA.
            DB::table('meal_plan_versions')->where('id', $locked->id)->update(['status' => 'draft']);
        });
    }

    private function validated(User $actor, MealPlanVersion $version, array $input): array
    {
        $data = Validator::make($input, [
            'source_week' => ['required', 'integer', 'min:1', 'max:4680'],
            'total_weeks' => ['required', 'integer', 'min:2', 'max:4681'],
            'expected' => ['required', 'string', 'size:64'],
        ])->validate();
        $state = $this->state($actor, $version);
        if (! hash_equals($state['expected'], $data['expected'])) {
            $this->invalid('expected', 'Раціон змінився. Оновіть сторінку й повторно виберіть тижні.');
        }
        $count = count($state['days']);
        if ($count === 0 || $count % 7 !== 0 || array_column($state['days'], 'day_number') !== range(1, $count)) {
            $this->invalid('source_week', 'Потрібні повні послідовні семиденні тижні. Структура не ремонтується автоматично.');
        }
        $source = (int) $data['source_week'];
        $total = (int) $data['total_weeks'];
        if ($source > intdiv($count, 7) || $total <= $source || $total < intdiv($count, 7)) {
            $this->invalid('total_weeks', 'Загальна кількість має перевищувати номер джерела і не зменшувати наявну тривалість.');
        }
        $sourceDays = collect($state['days'])->whereBetween('day_number', [($source - 1) * 7 + 1, $source * 7])->pluck('id');
        $sourceMeals = collect($state['meals'])->whereIn('meal_plan_day_id', $sourceDays)->pluck('id');
        if (collect($state['items'])->whereIn('meal_id', $sourceMeals)->isEmpty()) {
            $this->invalid('source_week', 'Вихідний тиждень порожній. Додайте хоча б одну позицію. Незаповнені дні та прийоми копіюються як є.');
        }

        return [$state, $source, $total];
    }

    private function state(User $actor, MealPlanVersion $version): array
    {
        $days = DB::table('meal_plan_days')->where('meal_plan_version_id', $version->id)->selectRaw('*, xmin::text AS revision')->orderBy('day_number')->lockForUpdate()->get();
        $meals = DB::table('meals')->whereIn('meal_plan_day_id', $days->pluck('id'))->selectRaw('*, xmin::text AS revision')->orderBy('id')->lockForUpdate()->get();
        $items = DB::table('meal_items')->whereIn('meal_id', $meals->pluck('id'))->selectRaw('*, xmin::text AS revision')->orderBy('id')->lockForUpdate()->get();
        $revision = DB::table('meal_plan_versions')->where('id', $version->id)->selectRaw('xmin::text AS revision')->sole()->revision;
        $state = ['days' => $days->all(), 'meals' => $meals->all(), 'items' => $items->all()];
        $state['expected'] = hash_hmac('sha256', 'repeat-week:'.json_encode([$actor->id, $version->id, $revision, $state], JSON_THROW_ON_ERROR), config('app.key'));

        return $state;
    }

    private function grant(User $actor, MealPlanVersion $version, int $source, int $total, string $expected): string
    {
        return hash_hmac('sha256', 'replace-weeks:'.json_encode([$actor->id, $version->id, $source, $total, $expected], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

<?php

namespace App\Services\MealPlans;

use App\Models\Meal;
use App\Models\MealPlanDay;
use App\Models\MealPlanVersion;
use App\Models\MealType;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Команди T4.2: recovery fence → actor → exact version → days → meals → types. */
final class MealPlanStructure
{
    public static function allowed(User $actor, MealPlanVersion $version): bool
    {
        return MealPlanFoundation::allowed($actor)
            && MealPlanVersion::whereKey($version->getKey())->where('meal_plan_id', $version->meal_plan_id)->where('status', 'draft')->exists();
    }

    public static function token(Meal $meal): string
    {
        return hash('sha256', json_encode([$meal->id, $meal->meal_plan_day_id, $meal->meal_type_id, $meal->title, $meal->sort_order]));
    }

    /** Явний номер першого дня робить retry того самого блоку ідемпотентним. */
    public function generate(User $actor, MealPlanVersion $version, array $input): void
    {
        $this->write($actor, $version, function (MealPlanVersion $locked) use ($input): void {
            $data = Validator::make($input, [
                'start_day' => ['required', 'integer', 'min:1', 'max:32761'],
                'meal_type_ids' => ['required', 'array', 'min:1'],
                'meal_type_ids.*' => ['required', 'integer', 'distinct'],
            ])->validate();
            $start = (int) $data['start_day'];
            $days = $locked->days()->orderBy('day_number')->lockForUpdate()->get();
            $count = $days->count();
            if (($count > 0 && $days->pluck('day_number')->all() !== range(1, $count)) || $count % 7 !== 0 || ($start - 1) % 7 !== 0) {
                $this->invalid('meal_type_ids', 'Наявні дні мають утворювати повні послідовні тижні. Потрібен окремий розгляд структури.');
            }
            $types = MealType::whereIn('id', $data['meal_type_ids'])->orderBy('id')->sharedLock()->get();
            if ($types->count() !== count($data['meal_type_ids'])) {
                $this->invalid('meal_type_ids', 'Оберіть наявні типи прийомів їжі. Якщо довідник порожній, генерація недоступна.');
            }
            if ($count >= $start + 6) {
                return; // Повторний клік не відновлює видалені прийоми і не стирає правки.
            }
            if ($start !== $count + 1) {
                $this->invalid('meal_type_ids', 'Структура змінилася. Оновіть сторінку перед додаванням наступного тижня.');
            }
            $types = $types->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
            for ($n = $start; $n < $start + 7; $n++) {
                $day = new MealPlanDay;
                $day->meal_plan_version_id = $locked->id;
                $day->day_number = $n;
                $day->save();
                foreach ($types as $order => $type) {
                    $meal = new Meal;
                    $meal->meal_plan_day_id = $day->id;
                    $meal->meal_type_id = $type->id;
                    $meal->sort_order = $order;
                    $meal->save();
                }
            }
        });
    }

    public function saveMeal(User $actor, MealPlanVersion $version, array $input, ?int $mealId = null): void
    {
        $this->write($actor, $version, function (MealPlanVersion $locked) use ($input, $mealId): void {
            $data = Validator::make($input, [
                'day_id' => ['required', 'integer'],
                'meal_type_id' => ['required', 'integer'],
                'title' => ['nullable', 'string'],
                'sort_order' => ['required', 'integer', 'between:-32768,32767'],
                'expected' => [$mealId === null ? 'nullable' : 'required', 'string'],
            ])->validate();
            $day = $locked->days()->whereKey($data['day_id'])->lockForUpdate()->first();
            if (! $day) {
                $this->invalid('day_id', 'День не належить цій точній версії. Оновіть сторінку.');
            }
            $meal = $mealId === null ? new Meal : $day->meals()->whereKey($mealId)->lockForUpdate()->first();
            if (! $meal) {
                $this->invalid('meal_id', 'Прийом видалений або не належить цьому дню й версії.');
            }
            if ($mealId !== null && ! hash_equals(self::token($meal), $data['expected'])) {
                $this->invalid('meal_id', 'Прийом уже змінено. Закрийте діалог і перечитайте актуальні дані.');
            }
            if (! MealType::whereKey($data['meal_type_id'])->sharedLock()->first()) {
                $this->invalid('meal_type_id', 'Оберіть наявний тип прийому їжі.');
            }
            if ($day->meals()->where('meal_type_id', $data['meal_type_id'])->when($mealId !== null, fn ($q) => $q->where('id', '<>', $mealId))->exists()) {
                $this->invalid('meal_type_id', 'Цей тип уже є в обраному дні.');
            }
            $meal->meal_plan_day_id = $day->id;
            $meal->meal_type_id = $data['meal_type_id'];
            $meal->title = isset($data['title']) && trim($data['title']) !== '' ? trim($data['title']) : null;
            $meal->sort_order = $data['sort_order'];
            $meal->save();
        });
    }

    public function deleteMeal(User $actor, MealPlanVersion $version, int $dayId, int $mealId, string $expected): void
    {
        $this->write($actor, $version, function (MealPlanVersion $locked) use ($dayId, $mealId, $expected): void {
            $day = $locked->days()->whereKey($dayId)->lockForUpdate()->first();
            $meal = $day?->meals()->whereKey($mealId)->lockForUpdate()->first();
            if (! $meal || ! hash_equals(self::token($meal), $expected)) {
                $this->invalid('meal_id', 'Прийом змінений, видалений або належить іншій версії. Оновіть сторінку.');
            }
            if ($meal->items()->exists()) {
                $this->invalid('meal_id', 'Прийом із позиціями видаляти заборонено. Історія зберігається.');
            }
            $meal->delete();
        });
    }

    /** Лише читання: підписаний стан кожного повного тижня точної чернетки. */
    public function weekDeletionSnapshots(User $actor, MealPlanVersion $version): array
    {
        abort_unless(self::allowed($actor, $version), 403);
        $days = DB::table('meal_plan_days')->where('meal_plan_version_id', $version->id)->orderBy('day_number')->get();
        if (! $this->completeDays($days->pluck('day_number')->all())) {
            return [];
        }
        $meals = DB::table('meals')->whereIn('meal_plan_day_id', $days->pluck('id'))->orderBy('id')->get();
        $items = DB::table('meal_items')->whereIn('meal_id', $meals->pluck('id'))->orderBy('id')->get();

        return $days->chunk(7)->map(function ($week) use ($actor, $version, $meals, $items): array {
            $selectedMeals = $meals->whereIn('meal_plan_day_id', $week->pluck('id'))->values();

            return $this->weekSnapshot($actor, $version, $week->values(), $selectedMeals, $items->whereIn('meal_id', $selectedMeals->pluck('id'))->values());
        })->values()->all();
    }

    /** Окремий серверний grant видається тільки для актуального confirmation snapshot. */
    public function confirmWeekDeletion(User $actor, MealPlanVersion $version, array $input): array
    {
        return $this->write($actor, $version, function (MealPlanVersion $locked) use ($actor, $input): array {
            $snapshot = $this->lockedWeek($actor, $locked, $input);
            if ($snapshot['item_count'] === 0) {
                $this->invalid('week', 'Тиждень порожній. Оновіть сторінку перед видаленням.');
            }

            return ['confirmation' => $this->confirmationToken($actor, $locked, $snapshot['expected'])];
        });
    }

    public function deleteWeek(User $actor, MealPlanVersion $version, array $input): void
    {
        $this->write($actor, $version, function (MealPlanVersion $locked) use ($actor, $input): void {
            $snapshot = $this->lockedWeek($actor, $locked, $input);
            if ($snapshot['item_count'] > 0 && (! is_string($input['confirmation'] ?? null)
                || ! hash_equals($this->confirmationToken($actor, $locked, $snapshot['expected']), $input['confirmation']))) {
                $this->invalid('confirmation', 'Заповнений тиждень потребує підтвердження актуального стану. Оновіть сторінку.');
            }
            $mealIds = DB::table('meals')->whereIn('meal_plan_day_id', $snapshot['day_ids'])->pluck('id');
            // FK RESTRICT і immutable guards збережено: явний порядок лише для вибраної draft version.
            DB::table('meal_items')->whereIn('meal_id', $mealIds)->delete();
            DB::table('meals')->whereIn('id', $mealIds)->delete();
            DB::table('meal_plan_days')->where('meal_plan_version_id', $locked->id)->whereIn('id', $snapshot['day_ids'])->delete();
            $end = $snapshot['week_number'] * 7;
            $following = DB::table('meal_plan_days')->where('meal_plan_version_id', $locked->id)->where('day_number', '>', $end)->orderBy('day_number')->get();
            // Послідовні UPDATE у зростаючому порядку не конфліктують з негайним UNIQUE.
            foreach ($following as $day) {
                DB::table('meal_plan_days')->where('meal_plan_version_id', $locked->id)->where('id', $day->id)->update(['day_number' => $day->day_number - 7]);
            }
        });
    }

    private function lockedWeek(User $actor, MealPlanVersion $version, array $input): array
    {
        $data = Validator::make($input, [
            'anchor' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'expected' => ['required', 'string', 'size:64'],
            'item_count' => ['required', 'integer', 'min:0'],
        ])->validate();
        $days = DB::table('meal_plan_days')->where('meal_plan_version_id', $version->id)->orderBy('day_number')->lockForUpdate()->get();
        $offset = $days->search(fn ($day) => $day->id === (int) $data['anchor']);
        if (! $this->completeDays($days->pluck('day_number')->all()) || $offset === false || $offset % 7 !== 0) {
            $this->invalid('week', 'Тиждень видалений, зміщений або не належить цій повній структурі. Оновіть сторінку.');
        }
        $week = $days->slice($offset, 7)->values();
        $meals = DB::table('meals')->whereIn('meal_plan_day_id', $week->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $items = DB::table('meal_items')->whereIn('meal_id', $meals->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $snapshot = $this->weekSnapshot($actor, $version, $week, $meals, $items);
        if (! hash_equals($snapshot['expected'], $data['expected']) || $snapshot['item_count'] !== (int) $data['item_count']) {
            $this->invalid('week', 'Вміст тижня змінився. Оновіть сторінку й підтвердьте актуальний стан перед видаленням.');
        }

        return $snapshot;
    }

    private function completeDays(array $numbers): bool
    {
        return count($numbers) > 0 && count($numbers) % 7 === 0 && $numbers === range(1, count($numbers));
    }

    private function weekSnapshot(User $actor, MealPlanVersion $version, Collection $days, Collection $meals, Collection $items): array
    {
        $payload = [$actor->id, $version->id, $days->all(), $meals->all(), $items->all()];

        return [
            'anchor' => $days->first()->id,
            'day_ids' => $days->pluck('id')->all(),
            'week_number' => intdiv($days->first()->day_number - 1, 7) + 1,
            'item_count' => $items->count(),
            'expected' => hash_hmac('sha256', 'delete-week-snapshot:'.json_encode($payload, JSON_THROW_ON_ERROR), config('app.key')),
        ];
    }

    private function confirmationToken(User $actor, MealPlanVersion $version, string $expected): string
    {
        return hash_hmac('sha256', 'delete-week-confirmation:'.json_encode([$actor->id, $version->id, $expected], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function write(User $actor, MealPlanVersion $version, Closure $command): mixed
    {
        abort_unless(self::allowed($actor, $version), 403);

        return DB::transaction(function () use ($actor, $version, $command): mixed {
            DB::table('auth_security_runtime')->where('id', 1)->sharedLock()->first();
            User::whereKey($actor->getKey())->sharedLock()->firstOrFail();
            $locked = MealPlanVersion::whereKey($version->getKey())->where('meal_plan_id', $version->meal_plan_id)->lockForUpdate()->firstOrFail();
            abort_unless(self::allowed($actor, $locked), 403);
            $result = $command($locked);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            abort_unless(self::allowed($actor, $locked), 403);
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $result;
        });
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

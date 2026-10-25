<?php

namespace App\Services\MealPlans;

use App\Models\Meal;
use App\Models\MealPlanDay;
use App\Models\MealPlanVersion;
use App\Models\MealType;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Команди T4.2: actor → recovery fence → exact version → days → meals → types. */
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

    private function write(User $actor, MealPlanVersion $version, Closure $command): void
    {
        abort_unless(self::allowed($actor, $version), 403);
        DB::transaction(function () use ($actor, $version, $command): void {
            User::whereKey($actor->getKey())->sharedLock()->firstOrFail();
            DB::table('auth_security_runtime')->where('id', 1)->sharedLock()->first();
            $locked = MealPlanVersion::whereKey($version->getKey())->where('meal_plan_id', $version->meal_plan_id)->lockForUpdate()->firstOrFail();
            abort_unless(self::allowed($actor, $locked), 403);
            $command($locked);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            abort_unless(self::allowed($actor, $locked), 403);
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

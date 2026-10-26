<?php

namespace App\Services\MealPlans;

use App\Models\MealPlanVersion;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Персональна незалежна чернетка exact published version, без materialization foods. */
final class MealPlanCopier
{
    public function preview(User $actor, int $source, int $owner, ?string $title = null): array
    {
        return $this->within($actor, $source, $owner, $title, fn ($state) => [
            'source' => $state['plan']->title, 'version' => $state['version']->version_number,
            'days' => count($state['days']), 'owner' => $owner, 'title' => $state['title'],
            'confirmation' => $this->token($state),
        ]);
    }

    public function copy(User $actor, int $source, int $owner, ?string $title, string $confirmation): MealPlanVersion
    {
        return $this->within($actor, $source, $owner, $title, function ($state) use ($confirmation): MealPlanVersion {
            if (! hash_equals($this->token($state), $confirmation)) {
                $this->invalid('Дані змінилися або запит уже виконано. Перевірте копіювання заново.');
            }
            $plan = DB::table('meal_plans')->insertGetId(['title' => $state['title'], 'owner_client_id' => $state['client']->id,
                'parent_meal_plan_id' => $state['plan']->id, 'week_start_weekday' => $state['plan']->week_start_weekday]);
            $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan, 'status' => 'draft']);
            foreach ($state['days'] as $day) {
                $newDay = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $day->day_number]);
                foreach ($state['meals']->where('meal_plan_day_id', $day->id) as $meal) {
                    $fields = (array) $meal;
                    unset($fields['id']);
                    $fields['meal_plan_day_id'] = $newDay;
                    $newMeal = DB::table('meals')->insertGetId($fields);
                    foreach ($state['items']->where('meal_id', $meal->id) as $item) {
                        $fields = (array) $item;
                        unset($fields['id']);
                        $fields['meal_id'] = $newMeal;
                        DB::table('meal_items')->insert($fields);
                    }
                }
            }
            // Лише MVCC revision: не змінюємо бізнес-поля клієнта чи історію source.
            DB::table('clients')->where('id', $state['client']->id)->update(['updated_at' => DB::raw('updated_at')]);

            return MealPlanVersion::findOrFail($version);
        });
    }

    private function within(User $actor, int $source, int $owner, ?string $title, Closure $command): mixed
    {
        abort_unless(MealPlanFoundation::allowed($actor), 403);

        return DB::transaction(function () use ($actor, $source, $owner, $title, $command) {
            DB::table('auth_security_runtime')->where('id', 1)->sharedLock()->first();
            User::whereKey($actor->id)->sharedLock()->firstOrFail();
            abort_unless(MealPlanFoundation::allowed($actor), 403);
            $version = DB::table('meal_plan_versions')->selectRaw('*, xmin::text AS revision')->where('id', $source)->sharedLock()->first();
            if ($version?->status !== 'published') {
                $this->invalid('Джерело має бути наявною опублікованою версією.');
            }
            // SHARE сумісні між копіюваннями; version → plan узгоджено з publisher/assigner.
            $lineage = [];
            $id = $version->meal_plan_id;
            while ($id !== null) {
                if (isset($lineage[$id])) {
                    $this->invalid('Циклічне походження раціону.');
                }
                $row = DB::table('meal_plans')->selectRaw('*, xmin::text AS revision')->where('id', $id)->sharedLock()->first();
                if (! $row || ($row->owner_client_id !== null && $row->owner_client_id !== $owner)
                    || ($row->parent_meal_plan_id !== null && $row->owner_client_id === null)) {
                    $this->invalid('Некоректне походження або персональний раціон іншого клієнта.');
                }
                $lineage[$id] = $row;
                $id = $row->parent_meal_plan_id;
            }
            $plan = $lineage[$version->meal_plan_id];
            $client = DB::table('clients')->selectRaw('*, xmin::text AS revision')->where('id', $owner)->lockForUpdate()->first();
            if (! $client) {
                $this->invalid('Оберіть наявного клієнта.');
            }
            $title = trim($title ?? $plan->title);
            if ($title === '') {
                $this->invalid('Назва копії не може бути порожньою.');
            }
            $days = DB::table('meal_plan_days')->where('meal_plan_version_id', $source)->orderBy('day_number')->get();
            $meals = DB::table('meals')->whereIn('meal_plan_day_id', $days->pluck('id'))->orderBy('sort_order')->orderBy('id')->get();
            $items = DB::table('meal_items')->whereIn('meal_id', $meals->pluck('id'))->orderBy('sort_order')->orderBy('id')->get();
            $state = compact('version', 'plan', 'lineage', 'client', 'title', 'days', 'meals', 'items') + ['actor' => $actor->id];
            $result = $command($state);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            abort_unless(MealPlanFoundation::allowed($actor), 403);
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $result;
        });
    }

    private function token(array $state): string
    {
        return hash_hmac('sha256', 'personal-plan:'.json_encode($state, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['copy' => $message]);
    }
}

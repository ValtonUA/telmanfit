<?php

namespace App\Services\MealPlans;

use App\Models\ClientMealPlanAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** DEC-4A: exact published → client; історія та журнал в одній транзакції. */
final class MealPlanAssigner
{
    public function snapshot(User $actor, int $client): string
    {
        return $this->within($actor, $client, null, fn ($owner, $rows): string => $this->state($actor, $owner, $rows));
    }

    public function preview(User $actor, int $client, array $input): array
    {
        abort_unless(MealPlanFoundation::allowed($actor), 403);
        $data = $this->input($input);

        return $this->within($actor, $client, $data['meal_plan_version_id'], function ($owner, $rows) use ($actor, $data): array {
            $effect = $this->effect($actor, $owner, $rows, $data);

            return $effect + ['confirmation' => $this->sign([$data, $effect])];
        });
    }

    public function assign(User $actor, int $client, array $input, string $confirmation): ClientMealPlanAssignment
    {
        abort_unless(MealPlanFoundation::allowed($actor), 403);
        $data = $this->input($input);

        return $this->within($actor, $client, $data['meal_plan_version_id'], function ($owner, $rows) use ($actor, $data, $confirmation): ClientMealPlanAssignment {
            $effect = $this->effect($actor, $owner, $rows, $data);
            if (! hash_equals($this->sign([$data, $effect]), $confirmation)) {
                $this->invalid('Перевірте наслідки й підтвердьте призначення заново.');
            }
            if ($effect['old_assignment_id'] !== null) {
                DB::table('client_meal_plan_assignments')->where('id', $effect['old_assignment_id'])->update(['end_date' => $effect['old_end_date_after']]);
            }
            $assignment = new ClientMealPlanAssignment;
            $assignment->client_id = $owner->id;
            $assignment->meal_plan_version_id = $data['meal_plan_version_id'];
            $assignment->start_date = $data['start_date'];
            $assignment->end_date = $data['end_date'];
            $assignment->created_by = $actor->id;
            $assignment->save();
            if ($effect['old_assignment_id'] !== null) {
                DB::table('client_meal_plan_assignments')->where('id', $effect['old_assignment_id'])->update(['replaced_by_assignment_id' => $assignment->id]);
            }
            // Локальний тип цього workflow, без глобального enum/довідника подій.
            DB::table('client_events')->insert([
                'client_id' => $owner->id, 'actor_user_id' => $actor->id,
                'event_type' => 'meal_plan_assigned',
                'event_data' => json_encode($effect + ['client_id' => $owner->id, 'actor_user_id' => $actor->id, 'new_assignment_id' => $assignment->id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            // MVCC revision клієнта також захищає від ABA та повторного старого snapshot.
            DB::table('clients')->where('id', $owner->id)->update(['updated_at' => DB::raw('updated_at')]);

            return $assignment;
        });
    }

    private function input(array $input): array
    {
        foreach (['end_date', 'replace_assignment_id'] as $field) {
            if (($input[$field] ?? null) === '') {
                $input[$field] = null;
            }
        }
        $data = Validator::make($input, [
            'meal_plan_version_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'replace_assignment_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'expected' => ['required', 'string'],
        ], [
            'required' => 'Заповніть обов’язкове поле.', 'integer' => 'Оберіть наявний запис.',
            'max' => 'Оберіть наявний запис.', 'min' => 'Оберіть наявний запис.', 'date_format' => 'Вкажіть дату у форматі РРРР-ММ-ДД.',
            'after_or_equal' => 'Завершення не може бути раніше початку.', 'string' => 'Оновіть форму призначення.',
        ])->validate();

        return ['meal_plan_version_id' => (int) $data['meal_plan_version_id'], 'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null, 'replace_assignment_id' => isset($data['replace_assignment_id']) ? (int) $data['replace_assignment_id'] : null,
            'expected' => $data['expected']];
    }

    private function within(User $actor, int $client, ?int $version, Closure $command): mixed
    {
        abort_unless(MealPlanFoundation::allowed($actor), 403);

        return DB::transaction(function () use ($actor, $client, $version, $command): mixed {
            // Узгоджено з publisher: fence → actor → version → plan → client → assignments.
            DB::table('auth_security_runtime')->where('id', 1)->sharedLock()->first();
            User::whereKey($actor->id)->sharedLock()->firstOrFail();
            abort_unless(MealPlanFoundation::allowed($actor), 403);
            $plan = null;
            if ($version !== null) {
                $published = DB::table('meal_plan_versions')->where('id', $version)->sharedLock()->first();
                if ($published?->status !== 'published') {
                    $this->invalid('Оберіть конкретну опубліковану версію раціону.');
                }
                $plan = DB::table('meal_plans')->where('id', $published->meal_plan_id)->sharedLock()->first();
            }
            $owner = DB::table('clients')->selectRaw('*, xmin::text AS revision')->where('id', $client)->lockForUpdate()->first();
            if (! $owner) {
                $this->invalid('Клієнта не знайдено. Оновіть сторінку.');
            }
            if ($plan?->owner_client_id !== null && $plan->owner_client_id !== $client) {
                $this->invalid('Персональний раціон можна призначити лише його власнику.');
            }
            $rows = DB::table('client_meal_plan_assignments')->selectRaw('*, xmin::text AS revision')->where('client_id', $client)->orderBy('id')->lockForUpdate()->get();
            $result = $command($owner, $rows);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            abort_unless(MealPlanFoundation::allowed($actor), 403);
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $result;
        });
    }

    private function effect(User $actor, object $client, Collection $rows, array $data): array
    {
        if (! hash_equals($this->state($actor, $client, $rows), $data['expected'])) {
            $this->invalid('Розклад або картка клієнта змінилися. Закрийте форму й перевірте призначення заново.');
        }
        $today = today()->toDateString();
        if ($data['start_date'] < $today) {
            $this->invalid('Призначення може починатися сьогодні або в майбутньому.');
        }
        $overlaps = $rows->filter(fn ($row): bool => ($row->end_date === null || $row->end_date >= $data['start_date'])
            && ($data['end_date'] === null || $row->start_date <= $data['end_date']));
        $old = null;
        if ($data['replace_assignment_id'] !== null) {
            $target = $rows->firstWhere('id', $data['replace_assignment_id']);
            if (! $target || $target->replaced_by_assignment_id !== null) {
                $this->invalid('Вибране призначення відсутнє або вже замінене.');
            }
            if ($overlaps->contains('id', $target->id)) {
                if ($overlaps->count() !== 1 || $data['start_date'] <= $today || $data['start_date'] <= $target->start_date) {
                    $this->invalid('Заміна потребує майбутньої дати після початку попереднього призначення та не може зачіпати інші заплановані раціони.');
                }
                $old = $target;
            } elseif ($overlaps->isNotEmpty()) {
                $this->invalid('Новий період перетинає інше призначення. Перевірте розклад.');
            }
        } elseif ($overlaps->isNotEmpty()) {
            $this->invalid('Період перетинає наявне призначення. Скористайтеся явною дією «Замінити раціон» або оберіть інші дати.');
        }

        return ['old_assignment_id' => $old?->id, 'old_meal_plan_version_id' => $old?->meal_plan_version_id,
            'old_start_date' => $old?->start_date, 'old_end_date_before' => $old?->end_date,
            'old_end_date_after' => $old ? CarbonImmutable::parse($data['start_date'])->subDay()->toDateString() : null,
            'new_meal_plan_version_id' => $data['meal_plan_version_id'], 'new_start_date' => $data['start_date'], 'new_end_date' => $data['end_date']];
    }

    private function state(User $actor, object $client, Collection $rows): string
    {
        return $this->sign([$actor->id, $client, $rows->all()]);
    }

    private function sign(array $state): string
    {
        return hash_hmac('sha256', 'meal-plan-assignment:'.json_encode($state, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['assignment' => $message]);
    }
}

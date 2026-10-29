<?php

namespace App\Services\MealPlans;

use App\Models\Client;
use App\Models\MealPlan;
use App\Models\MealPlanVersion;
use App\Models\MealType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** UX-3: атомарне створення з довговічною квитанцією повторного запиту. */
final class MealPlanFoundation
{
    public static function allowed(?User $actor): bool
    {
        $fresh = $actor?->exists ? User::find($actor->getKey()) : null;

        return $fresh?->role === User::ROLE_ADMIN && $fresh->canAccessPanel(Filament::getPanel('admin'));
    }

    public function create(User $actor, array $input): MealPlan
    {
        abort_unless(self::allowed($actor), 403);

        $input['title'] = is_string($input['title'] ?? null) ? trim($input['title']) : ($input['title'] ?? null);
        if (($input['owner_client_id'] ?? null) === '') {
            $input['owner_client_id'] = null;
        }
        if (! array_key_exists('week_start_weekday', $input)) {
            $input['week_start_weekday'] = 1;
        }
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'title' => ['required', 'string'],
            'week_start_weekday' => ['required', 'integer', 'between:1,7'],
            'owner_client_id' => ['nullable', 'integer'],
            'meal_type_ids' => ['required', 'array', 'min:1'],
            'meal_type_ids.*' => ['required', 'integer', 'distinct'],
        ])->validate();
        $ids = array_map('intval', $data['meal_type_ids']);
        sort($ids, SORT_NUMERIC);
        $hash = hash('sha256', json_encode([
            $data['title'], (int) $data['week_start_weekday'], isset($data['owner_client_id']) ? (int) $data['owner_client_id'] : null, $ids,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            return DB::transaction(function () use ($actor, $data, $ids, $hash): MealPlan {
                DB::table('auth_security_runtime')->where('id', 1)->sharedLock()->first();
                User::whereKey($actor->getKey())->sharedLock()->firstOrFail();
                abort_unless(self::allowed($actor), 403);
                $existing = DB::table('meal_plan_creation_requests')->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    return $this->receipt($actor, $existing, $hash);
                }
                Validator::make($data, ['owner_client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')]])->validate();
                if (isset($data['owner_client_id'])) {
                    Client::whereKey($data['owner_client_id'])->sharedLock()->firstOrFail();
                }
                $plan = new MealPlan(['title' => $data['title'], 'week_start_weekday' => $data['week_start_weekday']]);
                $plan->owner_client_id = $data['owner_client_id'] ?? null;
                $plan->save();
                $version = new MealPlanVersion;
                $version->meal_plan_id = $plan->getKey();
                $version->status = 'draft';
                $version->save();
                $catalogue = MealType::orderBy('id')->sharedLock()->get();
                $types = $catalogue->whereIn('id', $ids);
                if ($types->count() !== count($ids) || $catalogue->contains(fn (MealType $type) => trim($type->code) === '' || trim($type->title) === '')) {
                    throw ValidationException::withMessages(['meal_type_ids' => 'Оберіть наявні коректні типи прийомів їжі. Перевірте довідник.']);
                }
                app(MealPlanStructure::class)->generate($actor, $version, ['start_day' => 1, 'meal_type_ids' => $ids]);
                $inserted = DB::table('meal_plan_creation_requests')->insertOrIgnore([
                    'request_key' => $data['request_key'], 'admin_id' => $actor->getKey(),
                    'parameters_sha256' => $hash, 'meal_plan_id' => $plan->getKey(),
                ]);
                if (! $inserted) {
                    throw new CreationRequestCollision;
                }
                // Відкладені guards мають відмовити до повідомлення про успіх у UI.
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');

                return $plan->refresh();
            });
        } catch (CreationRequestCollision) {
            return DB::transaction(function () use ($actor, $data, $hash): MealPlan {
                DB::table('auth_security_runtime')->where('id', 1)->sharedLock()->first();
                User::whereKey($actor->getKey())->sharedLock()->firstOrFail();
                abort_unless(self::allowed($actor), 403);
                $existing = DB::table('meal_plan_creation_requests')->where('request_key', $data['request_key'])->first();
                if (! $existing) {
                    throw ValidationException::withMessages(['request_key' => 'Запит конфліктує. Оновіть сторінку.']);
                }

                return $this->receipt($actor, $existing, $hash);
            });
        }
    }

    private function receipt(User $actor, object $receipt, string $hash): MealPlan
    {
        if ((int) $receipt->admin_id !== (int) $actor->getKey() || ! hash_equals($receipt->parameters_sha256, $hash)) {
            throw ValidationException::withMessages(['request_key' => 'Цей ключ уже використано для іншого запиту. Почніть нове створення.']);
        }

        return MealPlan::findOrFail($receipt->meal_plan_id);
    }
}

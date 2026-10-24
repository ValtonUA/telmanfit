<?php

namespace App\Services\MealPlans;

use App\Models\Client;
use App\Models\MealPlan;
use App\Models\MealPlanVersion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** T4.1: лише новий логічний раціон з однією порожньою чернеткою. */
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

        return DB::transaction(function () use ($actor, $input): MealPlan {
            User::whereKey($actor->getKey())->sharedLock()->firstOrFail();
            abort_unless(self::allowed($actor), 403);
            $input['title'] = is_string($input['title'] ?? null) ? trim($input['title']) : ($input['title'] ?? null);
            if (($input['owner_client_id'] ?? null) === '') {
                $input['owner_client_id'] = null;
            }
            $data = Validator::make($input, [
                'title' => ['required', 'string'],
                'owner_client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            ])->validate();
            if (isset($data['owner_client_id'])) {
                Client::whereKey($data['owner_client_id'])->sharedLock()->firstOrFail();
            }
            $plan = new MealPlan(['title' => $data['title']]);
            $plan->owner_client_id = $data['owner_client_id'] ?? null;
            $plan->save();
            $version = new MealPlanVersion;
            $version->meal_plan_id = $plan->getKey();
            $version->status = 'draft';
            $version->save();
            // Відкладені guards мають відмовити до повідомлення про успіх у UI.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $plan->refresh();
        });
    }
}

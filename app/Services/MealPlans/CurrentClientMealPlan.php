<?php

namespace App\Services\MealPlans;

use Illuminate\Support\Facades\DB;

/** Читання exact published version; жодного latest чи автоматичного перепризначення. */
final class CurrentClientMealPlan
{
    public function forClient(int $client): ?array
    {
        $today = now(config('app.timezone'))->toDateString();
        $row = DB::table('client_meal_plan_assignments as a')
            ->join('meal_plan_versions as v', 'v.id', '=', 'a.meal_plan_version_id')
            ->join('meal_plans as p', 'p.id', '=', 'v.meal_plan_id')
            ->where('a.client_id', $client)
            ->where('a.start_date', '<=', $today)
            ->where(fn ($dates) => $dates->whereNull('a.end_date')->orWhere('a.end_date', '>=', $today))
            ->where('v.status', 'published')
            ->where(fn ($owner) => $owner->whereNull('p.owner_client_id')->orWhere('p.owner_client_id', $client))
            ->select(['a.id', 'a.start_date', 'a.end_date', 'v.id as version_id', 'v.meal_plan_id',
                'v.version_number', 'v.published_at', 'p.title'])
            ->first();

        if (! $row) {
            return null;
        }

        // replaced_by_assignment_id не виключає чинний до майбутньої заміни період.
        return [
            'id' => (string) $row->id,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'meal_plan_version' => [
                'id' => (string) $row->version_id,
                'meal_plan_id' => (string) $row->meal_plan_id,
                'version_number' => $row->version_number,
                'status' => 'published',
                'published_at' => $row->published_at,
                'title' => $row->title,
            ],
        ];
    }
}

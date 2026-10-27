<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientSession;
use App\Services\MealPlans\ClientMealPlanDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClientMealPlanDaysController
{
    public function __invoke(Request $request, ClientSession $sessions, ClientMealPlanDays $plans): JsonResponse
    {
        return DB::transaction(function () use ($request, $sessions, $plans): JsonResponse {
            $user = $sessions->user($request);
            $client = DB::table('clients')->where('user_id', $user->id)->value('id');
            if ($client === null) {
                throw new AuthDenied('unauthenticated', 401);
            }
            $query = $request->validate(['week' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);

            return response()->json($plans->forClient($client, isset($query['week']) ? (int) $query['week'] : null))
                ->header('Cache-Control', 'no-store, private');
        });
    }
}

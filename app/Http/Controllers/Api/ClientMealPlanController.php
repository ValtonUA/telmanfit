<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientSession;
use App\Services\MealPlans\CurrentClientMealPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClientMealPlanController
{
    public function __invoke(Request $request, ClientSession $sessions, CurrentClientMealPlan $plans): JsonResponse
    {
        return DB::transaction(function () use ($request, $sessions, $plans): JsonResponse {
            $user = $sessions->user($request);
            $client = DB::table('clients')->where('user_id', $user->id)->value('id');
            if ($client === null) {
                throw new AuthDenied('unauthenticated', 401);
            }

            return response()->json(['assignment' => $plans->forClient($client)])
                ->header('Cache-Control', 'no-store, private');
        });
    }
}

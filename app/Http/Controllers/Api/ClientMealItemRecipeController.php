<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientSession;
use App\Services\MealPlans\ClientMealItemRecipe;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class ClientMealItemRecipeController
{
    public function __invoke(Request $request, string $item, ClientSession $sessions, ClientMealItemRecipe $recipes): JsonResponse
    {
        return DB::transaction(function () use ($request, $item, $sessions, $recipes): JsonResponse {
            // Єдиний MVCC snapshot для призначення, календаря й published деталей.
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $user = $sessions->user($request);
            $client = DB::table('clients')->where('user_id', $user->id)->value('id');
            if ($client === null) {
                throw new AuthDenied('unauthenticated', 401);
            }
            try {
                if (! ctype_digit($item) || strlen($item) > 10 || (int) $item < 1 || (int) $item > 2147483647) {
                    throw new DomainException;
                }

                return response()->json($recipes->forClient($client, (int) $item));
            } catch (DomainException|ValidationException|JsonException) {
                return response()->json(['code' => 'recipe_unavailable', 'message' => 'Рецепт недоступний. Поверніться до раціону або зверніться до тренера.'], 404);
            }
        })->header('Cache-Control', 'no-store, private');
    }
}

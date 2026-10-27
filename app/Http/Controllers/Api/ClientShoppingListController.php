<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientSession;
use App\Services\MealPlans\ClientShoppingList;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClientShoppingListController
{
    public function __invoke(Request $request, ClientSession $sessions, ClientShoppingList $lists): JsonResponse
    {
        return DB::transaction(function () use ($request, $sessions, $lists): JsonResponse {
            $user = $sessions->user($request);
            $client = DB::table('clients')->where('user_id', $user->id)->value('id');
            if ($client === null) {
                throw new AuthDenied('unauthenticated', 401);
            }
            $query = $request->validate(['week' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);
            try {
                $response = response()->json($lists->forClient($client, isset($query['week']) ? (int) $query['week'] : null));
            } catch (DomainException) {
                // Жодних часткових totals або назв чужих рецептів у відповіді.
                $response = response()->json(['code' => 'shopping_list_unavailable',
                    'message' => 'Не вдалося сформувати повний список покупок. Зверніться до тренера.'], 409);
            }

            return $response->header('Cache-Control', 'no-store, private');
        });
    }
}

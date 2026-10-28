<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientSession;
use App\Services\Cooking\ClientCooking;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class ClientCookingController
{
    public function choices(Request $request, ClientSession $sessions, ClientCooking $cooking): JsonResponse
    {
        $data = $request->validate(['week' => ['required', 'integer', 'min:1', 'max:4681']]);
        try {
            return $this->readSnapshot(function () use ($request, $sessions, $cooking, $data): JsonResponse {
                $client = $this->clientId($request, $sessions);
                return response()->json($cooking->choices($client, (int) $data['week'], now(config('app.timezone'))->toDateString()));
            });
        } catch (DomainException|JsonException) {
            return response()->json(['code' => 'cooking_unavailable', 'message' => 'Опубліковані кухонні сесії зараз недоступні.'], 404)
                ->header('Cache-Control', 'no-store, private');
        }
    }

    public function session(Request $request, string $version, string $session, ClientSession $sessions, ClientCooking $cooking): JsonResponse
    {
        $data = $request->validate(['week' => ['required', 'integer', 'min:1', 'max:4681'], 'mode' => ['required', 'integer', 'in:1,2,7']]);

        try {
            return $this->readSnapshot(function () use ($request, $sessions, $cooking, $data, $version, $session): JsonResponse {
                $client = $this->clientId($request, $sessions);
                return response()->json($cooking->session($client, (int) $data['week'], (int) $data['mode'], (int) $version, (int) $session,
                    now(config('app.timezone'))->toDateString()));
            });
        } catch (DomainException|JsonException) {
            return response()->json(['code' => 'cooking_unavailable', 'message' => 'Кухонна сесія недоступна. Поверніться до раціону або зверніться до тренера.'], 404);
        }
    }

    private function readSnapshot(callable $callback): JsonResponse
    {
        return DB::transaction(function () use ($callback): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            return $callback();
        })->header('Cache-Control', 'no-store, private');
    }

    private function clientId(Request $request, ClientSession $sessions): int
    {
        $user = $sessions->readOnlyUser($request);
        $client = DB::table('clients')->where('user_id', $user->id)->value('id');
        if ($client === null) {
            throw new AuthDenied('unauthenticated', 401);
        }
        return (int) $client;
    }
}

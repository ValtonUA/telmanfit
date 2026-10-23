<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Перший робочий vertical slice: відповіді зберігаються для поточного клієнта,
 * не активують супровід і не завершують повну анкету.
 */
final class ClientOnboardingController
{
    public function show(Request $request, ClientSession $sessions): JsonResponse
    {
        return DB::transaction(function () use ($request, $sessions): JsonResponse {
            $user = $sessions->user($request);
            $clientId = DB::table('clients')->where('user_id', $user->id)->value('id');

            if ($clientId === null) {
                throw new AuthDenied('unauthenticated', 401);
            }

            $row = DB::table('client_onboardings')->where('client_id', $clientId)->first();

            return $this->noStore([
                'answers' => $this->decode($row?->answers),
                'completed' => $row?->completed_at !== null,
            ]);
        });
    }

    public function store(Request $request, ClientSession $sessions): JsonResponse
    {
        // Питання №11, №12 і №16 з наявної анкети власника.
        // Це частина анкети, а не схема всіх майбутніх запитань.
        $validated = $request->validate([
            'workplace' => ['required', Rule::in(['home', 'hybrid', 'office'])],
            'meals_per_day' => ['required', 'integer', 'between:1,5'],
            'top_reasons' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $sessions, $validated): JsonResponse {
            $user = $sessions->user($request);
            // Синхронізує конкурентне перше збереження через UNIQUE(client_id).
            $client = DB::table('clients')->where('user_id', $user->id)->lockForUpdate()->first();

            if (! $client) {
                throw new AuthDenied('unauthenticated', 401);
            }

            $row = DB::table('client_onboardings')->where('client_id', $client->id)->first();
            if ($row?->completed_at !== null) {
                throw ValidationException::withMessages(['answers' => 'Завершена анкета є незмінним архівом.']);
            }
            $answers = array_replace($this->decode($row?->answers), [
                'workplace' => $validated['workplace'],
                'meals_per_day' => (int) $validated['meals_per_day'],
                'top_reasons' => trim($validated['top_reasons']),
            ]);
            $now = now();

            if ($row) {
                DB::table('client_onboardings')->where('client_id', $client->id)->update([
                    'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('client_onboardings')->insert([
                    'client_id' => $client->id,
                    'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);
            }

            // Немає завершення анкети чи активації платного супроводу.
            return $this->noStore(['answers' => $answers, 'saved' => true]);
        });
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode(is_string($value) ? $value : '{}', true);

        return is_array($decoded) && array_is_list($decoded) === false ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function noStore(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}

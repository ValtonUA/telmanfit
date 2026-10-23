<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\Auth\Client\AuthBudget;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSession;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ClientAuthController
{
    public function __invoke(Request $request, ClientAuth $auth, ClientSession $sessions, ControlledAuth $policy, MfaService $mfa): mixed
    {
        $session = $request->session();
        if (! $session->has('client_binding')) {
            $session->put('client_binding', bin2hex(random_bytes(32)));
        }
        $binding = $session->get('client_binding');
        $ip = $request->ip() ?? 'unknown';
        switch ($request->route()->getName()) {
            case 'auth4.csrf':
                return response()->noContent();
            case 'auth4.exchange':
                $data = $request->validate(['token' => ['required', 'string', 'max:256']]);

                return response()->json($auth->exchange($data['token'], $binding, $ip));
            case 'auth4.request':
                $data = $request->validate(['purpose' => ['required', Rule::in(['register', 'login'])], 'email' => ['required', 'string', 'max:254'],
                    'flow_id' => ['nullable', 'uuid', 'required_if:purpose,register']]);
                $response = $auth->request($data['purpose'], $data['email'], $data['flow_id'] ?? null, $binding, $ip);
                if ($data['purpose'] === 'register') {
                    $session->put('auth4_delivery.'.$data['flow_id'], trim($data['email']));
                }

                return response()->json($response, 202);
            case 'auth4.verify':
                $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:128']]);
                $response = $auth->verify($data['challenge_id'], $data['code'], $binding, $ip);
                if (isset($response['proof'])) {
                    $sessions->issue($request, $response['proof']);
                    unset($response['proof']);
                }

                return response()->json($response);
            case 'auth4.complete':
                $data = $request->validate(['flow_id' => ['required', 'uuid'], 'first_name' => ['required', 'string', 'max:255'], 'last_name' => ['required', 'string', 'max:255']]);
                $proof = $auth->complete($data['flow_id'], $data['first_name'], (string) $session->get('auth4_delivery.'.$data['flow_id']), $binding, $ip, $data['last_name']);
                $sessions->issue($request, $proof);

                return response()->json(['next' => 'authenticated'], 201);
            case 'auth4.mfa':
                $data = $request->validate(['flow_id' => ['required', 'uuid'], 'method' => ['required', Rule::in(['totp', 'recovery_code'])], 'code' => ['required', 'string', 'max:128']]);
                $allowed = $policy->transaction(fn () => app(AuthBudget::class)->take('mfa:ip:'.$ip, 20, 900));
                if (! $allowed) {
                    throw new AuthDenied('rate_limited', 429);
                }
                $proof = $mfa->finishLogin($data['flow_id'], $binding, $data['code'], $data['method'] === 'recovery_code');
                $sessions->issue($request, $proof);

                return response()->json(['next' => 'authenticated']);
            case 'auth4.me':
                return DB::transaction(function () use ($request, $sessions) {
                    $user = $sessions->user($request);
                    $client = DB::table('clients')->where('user_id', $user->id)->first();
                    if (! $client) {
                        throw new AuthDenied('unauthenticated', 401);
                    }
                    $mfa = DB::table('user_totp_factors')->where('user_id', $user->id)->whereNotNull('confirmed_at')->whereNull('disabled_at')->exists();
                    $remaining = $mfa ? DB::table('mfa_recovery_codes as c')->join('user_totp_factors as f', 'f.user_id', '=', 'c.user_id')
                        ->where('c.user_id', $user->id)->whereColumn('c.batch_id', 'f.recovery_batch_id')->whereNull('c.used_at')->whereNull('c.revoked_at')->count() : 0;
                    $recovered = $mfa && DB::table('auth_session_states')->where('session_id', $request->session()->getId())->value('mfa_method') === 'recovery_code';

                    return response()->json(['user' => ['id' => (string) $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'full_name' => User::displayName($user)],
                        'client' => ['id' => (string) $client->id, 'status' => $client->status],
                        'security' => ['mfa_enabled' => $mfa, 'methods' => $mfa ? ['email_otp', 'totp', 'recovery_code'] : ['email_otp'],
                            'recovery_codes_remaining' => $remaining, 'recovery_used' => $recovered]]);
                });
            case 'auth4.logout':
                $sessions->logout($request);

                return response()->noContent();
        }
        abort(404);
    }
}

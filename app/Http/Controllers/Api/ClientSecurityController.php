<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\Client\ClientSecurity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PragmaRX\Google2FAQRCode\Google2FA;

final class ClientSecurityController
{
    public function __invoke(Request $request, ClientSecurity $security): mixed
    {
        $name = $request->route()->getName();
        $proofRules = ['proof_id' => ['required', 'uuid']];
        $codeRules = ['code' => ['required', 'string', 'max:128']];
        switch ($name) {
            case 'auth5.reauth.request':
                $data = $request->validate(['action' => ['required', Rule::in(ClientSecurity::ACTIONS)], 'flow_id' => ['nullable', 'uuid']]);

                return response()->json($security->requestReauth($request, $data['action'], $data['flow_id'] ?? null), 202);
            case 'auth5.reauth.verify':
                $data = $request->validate(['challenge_id' => ['required', 'uuid']] + $codeRules);

                return response()->json($security->verifyReauth($request, $data['challenge_id'], $data['code']));
            case 'auth5.reauth.mfa':
                $data = $request->validate(['flow_id' => ['required', 'uuid'], 'method' => ['required', Rule::in(['totp', 'recovery_code'])]] + $codeRules);

                return response()->json($security->authorize($request, $data['flow_id'], $data['code'], $data['method'] === 'recovery_code'));
            case 'auth5.mfa.enroll':
                $data = $request->validate($proofRules + ['replace' => ['required', 'boolean']]);
                $result = $security->enrollment($request, $data['proof_id'], $data['replace']);
                $secret = $result['secret']->reveal();
                $qr = (new Google2FA)->getQRCodeInline('TelmanFit', $result['account'], $secret);

                // Лише явна одноразова POST-видача; жодних секретів у GET /me чи URL.
                return response()->json(['flow_id' => $result['flow_id'], 'secret' => $secret,
                    'qr_image' => $qr]);
            case 'auth5.mfa.confirm':
                $data = $request->validate(['flow_id' => ['required', 'uuid']] + $codeRules);

                return response()->json(['recovery_codes' => $security->confirmEnrollment($request, $data['flow_id'], $data['code'])->reveal()]);
            case 'auth5.mfa.recovery':
                $data = $request->validate($proofRules);

                return response()->json(['recovery_codes' => $security->rotateRecovery($request, $data['proof_id'])->reveal()]);
            case 'auth5.mfa.disable':
                $data = $request->validate($proofRules);
                $security->disable($request, $data['proof_id']);
                break;
            case 'auth5.sessions':
                return response()->json(['sessions' => $security->sessions($request)]);
            case 'auth5.sessions.revoke':
                $data = $request->validate($proofRules + ['handle' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D']]);
                $security->revokeSession($request, $data['proof_id'], $data['handle']);
                break;
            case 'auth5.logout_all':
                $data = $request->validate($proofRules);
                $security->logoutAll($request, $data['proof_id']);
                break;
            case 'auth5.email.request':
                $data = $request->validate(['proof_id' => ['nullable', 'uuid'], 'flow_id' => ['nullable', 'uuid'], 'email' => ['required', 'string', 'max:254']]);

                return response()->json($security->requestEmail($request, $data['proof_id'] ?? null, $data['email'], $data['flow_id'] ?? null), 202);
            case 'auth5.email.confirm':
                $data = $request->validate(['challenge_id' => ['required', 'uuid']] + $codeRules);
                $security->confirmEmail($request, $data['challenge_id'], $data['code']);
                break;
            default:
                abort(404);
        }

        return response()->noContent();
    }
}

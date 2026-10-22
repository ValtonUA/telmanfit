<?php

namespace App\Http\Controllers\Auth;

use App\Services\Auth\Client\InvitationService;
use Illuminate\Http\Request;

final class AdminInvitationController
{
    public function __invoke(Request $request, InvitationService $invitations): mixed
    {
        $headers = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'"];
        if ($request->expectsJson()) {
            return response('', 406, $headers);
        }
        try {
            if ($request->route()->getName() === 'auth4.invitation.create') {
                $data = $request->validate(['coach_user_id' => ['required', 'integer', 'min:1'], 'password' => ['required', 'string', 'max:1024'], 'code' => ['required', 'string', 'max:128']]);
                $url = $invitations->create($request, (int) $data['coach_user_id'], $data['password'], $data['code'])->reveal();

                return response()->view('auth.invitation-result', ['url' => $url], 201, $headers);
            }
            $data = $request->validate(['invitation_id' => ['required', 'integer', 'min:1'], 'password' => ['required', 'string', 'max:1024'], 'code' => ['required', 'string', 'max:128']]);
            $invitations->revoke($request, (int) $data['invitation_id'], $data['password'], $data['code']);

            return response()->view('auth.invitation-result', ['revoked' => true], 200, $headers);
        } catch (\Throwable) {
            return response()->view('auth.invitation-result', ['denied' => true], 403, $headers);
        }
    }
}

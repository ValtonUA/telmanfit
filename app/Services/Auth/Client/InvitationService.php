<?php

namespace App\Services\Auth\Client;

use App\Services\Auth\AdminAssurance;
use App\Services\Auth\MfaService;
use App\Services\Auth\SecretValue;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class InvitationService
{
    public function __construct(private ControlledAuth $auth, private AdminAssurance $admin, private MfaService $mfa) {}

    /** Fresh password+TOTP виконується для конкретної дії; proof не видається браузеру. */
    private function stepUp(Request $request, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): array
    {
        $this->auth->assert();
        $id = Auth::guard('admin')->id();
        $email = $id ? DB::table('users')->where('id', $id)->value('email') : null;
        if (! $email) {
            throw new AuthDenied('reauth_required', 403);
        }
        DB::transaction(fn () => $this->admin->assert($request));
        $binding = (string) $request->session()->get('mfa_binding');
        $passwordProof = $request->attributes->get('auth4_password_proof');
        $flow = $passwordProof instanceof SecretValue
            ? $this->mfa->beginVerifiedPasswordLogin($passwordProof, $binding)
            : $this->mfa->beginPasswordLogin($email, $password, $binding);
        $proof = $this->mfa->finishLogin($flow, $binding, $code);
        $this->mfa->claimLoginProof($proof);
        if ($proof->user_id !== $id || $proof->audience !== 'admin' || $proof->mfa_method !== 'totp') {
            throw new AuthDenied('reauth_required', 403);
        }

        return [$proof->user_id, $proof->auth_version, $proof->primary_verified_at, $proof->mfa_verified_at];
    }

    public function create(Request $request, int $coach, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): SecretValue
    {
        $proof = $this->stepUp($request, $password, $code);

        return DB::transaction(function () use ($request, $coach, $proof) {
            $this->fresh($request, $proof);
            if (! DB::table('users')->where('id', $coach)->whereIn('role', ['coach', 'admin'])->exists()) {
                throw new AuthDenied('invalid_coach');
            }
            $token = bin2hex(random_bytes(32));
            DB::table('client_invitations')->insert(['token_hash' => hash('sha256', $token),
                'created_by_user_id' => $proof[0], 'coach_user_id' => $coach, 'expires_at' => null]);
            $this->auth->audit('invitation.created', $proof[0]);

            return new SecretValue(url('/app/register').'#invite='.$token);
        });
    }

    public function revoke(Request $request, int $id, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): void
    {
        $proof = $this->stepUp($request, $password, $code);
        DB::transaction(function () use ($request, $id, $proof) {
            $this->fresh($request, $proof);
            $invite = DB::table('client_invitations')->where('id', $id)->lockForUpdate()->first();
            if (! $invite || $invite->consumed_at || $invite->revoked_at) {
                throw new AuthDenied('invitation_unavailable');
            }
            DB::table('client_invitations')->where('id', $id)->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => $this->auth->stamp(), 'revoked_by_user_id' => $proof[0]]);
            $this->auth->audit('invitation.revoked', $proof[0]);
        });
    }

    private function fresh(Request $request, array $proof): void
    {
        $this->auth->assert();
        $this->admin->assert($request);
        $version = DB::table('users')->where('id', $proof[0])->value('auth_version');
        if (Auth::guard('admin')->id() !== $proof[0] || $version !== $proof[1]
            || CarbonImmutable::parse($proof[2])->addSeconds(300)->lessThanOrEqualTo($this->auth->now())
            || CarbonImmutable::parse($proof[3])->addSeconds(300)->lessThanOrEqualTo($this->auth->now())) {
            throw new AuthDenied('reauth_required', 403);
        }
    }
}

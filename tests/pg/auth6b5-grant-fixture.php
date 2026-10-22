<?php

use App\Services\Auth\Restore\SyntheticEpoch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Owner-only synthetic fixture. Не D3 verifier і не production trustee provisioning.
$grantUser = DB::table('users')->insertGetId(['name' => 'Synthetic restore grant', 'role' => 'admin']);
$principals = [];
foreach (['target', 'initiator', 'one', 'two'] as $slot) {
    $principals[$slot] = (string) Str::uuid();
    DB::table('auth_recovery_principals')->insert(['id' => $principals[$slot], 'linked_user_id' => $slot === 'target' ? $grantUser : null,
        'authority' => in_array($slot, ['one', 'two']) ? 'trustee' : 'operator', 'identity_record_digest' => hash('sha256', $slot),
        'created_at' => DB::raw("clock_timestamp()-interval '2 days'")]);
}
$grantCase = (string) Str::uuid();
$intent = hash('sha256', $grantCase);
DB::table('auth_recovery_cases')->insert(['id' => $grantCase, 'target_user_id' => $grantUser, 'target_principal_id' => $principals['target'],
    'initiator_principal_id' => $principals['initiator'], 'target_role' => 'admin', 'action' => 'replace_mfa', 'expected_auth_version' => 0,
    'registry_version' => 1, 'policy_version' => 1, 'deployment_id' => 'synthetic', 'restore_epoch' => SyntheticEpoch::INITIAL,
    'intent_nonce' => (string) Str::uuid(), 'intent_digest' => $intent, 'created_at' => DB::raw("clock_timestamp()-interval '25 hours'"),
    'expires_at' => DB::raw("clock_timestamp()+interval '23 hours'"), 'reason_code' => 'lost_mfa']);
foreach (['one', 'two'] as $slot) {
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $key = (string) Str::uuid();
    DB::table('auth_recovery_keys')->insert(['id' => $key, 'principal_id' => $principals[$slot], 'public_key' => DB::raw("decode('".bin2hex($public)."','hex')"),
        'fingerprint' => hash('sha256', $public), 'registry_version' => 1, 'valid_from' => DB::raw("clock_timestamp()-interval '2 days'"), 'valid_until' => DB::raw("clock_timestamp()+interval '2 days'")]);
    $signature = sodium_crypto_sign_detached($intent, sodium_crypto_sign_secretkey($pair));
    DB::table('auth_recovery_approvals')->insert(['case_id' => $grantCase, 'principal_id' => $principals[$slot], 'key_id' => $key, 'intent_digest' => $intent,
        'signature' => DB::raw("decode('".bin2hex($signature)."','hex')"), 'evidence_digest' => hash('sha256', $slot), 'verification_receipt_id' => (string) Str::uuid(),
        'verified_at' => DB::raw("clock_timestamp()-interval '24 hours'"), 'expires_at' => DB::raw("clock_timestamp()+interval '22 hours'")]);
}
DB::table('auth_recovery_cases')->where('id', $grantCase)->update(['state' => 'hold', 'hold_until' => DB::raw("clock_timestamp()-interval '30 minutes'")]);
$grantId = (string) Str::uuid();
$grantHash = hash('sha256', random_bytes(32));
DB::transaction(fn () => DB::select('SELECT auth6b_quarantine(?::uuid,?::uuid,?)', [$grantCase, $grantId, $grantHash]));

<?php

namespace App\Services\Auth\Recovery;

use Carbon\CarbonImmutable;

final class RecoverySignatures
{
    public function intent(object $case): string
    {
        return json_encode([
            'domain' => 'TelmanFit/recovery/v1', 'deployment_id' => $case->deployment_id,
            'restore_epoch' => $case->restore_epoch, 'case_id' => $case->id,
            'target_user_id' => (string) $case->target_user_id, 'target_principal_id' => $case->target_principal_id,
            'initiator_principal_id' => $case->initiator_principal_id, 'operator_principal_id' => $case->operator_principal_id,
            'action' => $case->action, 'replace_admin_password' => (bool) $case->replace_admin_password,
            'target_role' => $case->target_role, 'expected_auth_version' => (string) $case->expected_auth_version,
            'replacement_email_mac' => $case->replacement_email_mac, 'intent_nonce' => $case->intent_nonce,
            'policy_version' => (int) $case->policy_version, 'registry_version' => (string) $case->registry_version,
            'created_at' => $this->stamp($case->created_at), 'expires_at' => $this->stamp($case->expires_at),
            'hold_duration_seconds' => 86400,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function envelope(object $case, array $approval): string
    {
        return json_encode(['domain' => 'TelmanFit/recovery/approval/v1',
            'intent_digest' => hash('sha256', $this->intent($case)), 'principal_id' => $approval['principal_id'],
            'key_id' => $approval['key_id'], 'evidence_digest' => $approval['evidence_digest'],
            'verification_receipt_id' => $approval['verification_receipt_id'],
            'expires_at' => $this->stamp($approval['expires_at']),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function verify(object $case, array $approval, string $publicKey): void
    {
        $signature = base64_decode($approval['signature'], true);
        if (! function_exists('sodium_crypto_sign_verify_detached') || strlen($publicKey) !== 32
            || ! is_string($signature) || strlen($signature) !== 64
            || ! hash_equals($case->intent_digest, hash('sha256', $this->intent($case)))
            || ! sodium_crypto_sign_verify_detached($signature, $this->envelope($case, $approval), $publicKey)) {
            throw new RecoveryDenied;
        }
    }

    private function stamp(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}

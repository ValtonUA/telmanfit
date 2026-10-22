<?php

namespace App\Services\Auth\Restore;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Maintenance-only synthetic reconciliation. Немає API відкриття доступу: D3/D8. */
final class SyntheticRestore
{
    public function reconcile(SyntheticEpoch $external, array $proofs, ?callable $fault = null): void
    {
        if (! app()->environment('testing') || ! app()->isDownForMaintenance() || DB::transactionLevel() !== 0) {
            throw new \RuntimeException('restore_maintenance_required');
        }
        require base_path('tests/pg/auth2-guard.php');
        // Спершу durable fence; crash на наступних етапах лишає closed.
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'maintenance']);
        foreach (['backup', 'wal', 'keys', 'registry', 'holds', 'revocations', 'audit'] as $proof) {
            if (($proofs[$proof] ?? null) !== true) {
                throw new \RuntimeException('restore_proof_unavailable');
            }
        }
        foreach (['hold_rows', 'principal_revocations', 'key_revocations'] as $manifest) {
            if (! isset($proofs[$manifest]) || ! is_array($proofs[$manifest])) {
                throw new \RuntimeException('restore_current_manifest_unavailable');
            }
        }
        $fault?->__invoke('preflight');
        $epoch = $external->current();
        $previous = DB::table('auth_security_runtime')->where('id', 1)->first();
        if (! $previous || ! Str::isUuid($epoch) || ($previous->restore_epoch === $epoch
            && ! DB::table('auth_audit_events')->where('event_type', 'restore.reconciled')->where('request_id', $epoch)->exists())) {
            throw new \RuntimeException('external_epoch_rotation_required');
        }
        DB::transaction(function () use ($epoch, $fault, $proofs, $external) {
            DB::statement("SET LOCAL lock_timeout='2s'");
            DB::statement("SET LOCAL statement_timeout='30s'");
            DB::select('SELECT pg_advisory_xact_lock(6060505)');
            DB::select('SELECT pg_advisory_xact_lock(6060303)');
            DB::select('SELECT pg_advisory_xact_lock(4040404)');
            $runtime = DB::table('auth_security_runtime')->where('id', 1)->lockForUpdate()->first();
            // Поточні synthetic authority manifests лишаються поза pg_dump.
            foreach (['principal_revocations' => 'auth_recovery_principals', 'key_revocations' => 'auth_recovery_keys'] as $source => $table) {
                foreach ($proofs[$source] as $id => $at) {
                    $row = DB::table($table)->where('id', $id)->first();
                    if (! $row) {
                        throw new \RuntimeException('restore_registry_incomplete');
                    }
                    if ($row->revoked_at === null) {
                        DB::table($table)->where('id', $id)->update(['revoked_at' => $at]);
                    }
                }
            }
            foreach ($proofs['hold_rows'] as $hold) {
                $existing = DB::table('auth_retention_holds')->where('id', $hold['id'])->first();
                if ($existing === null) {
                    DB::table('auth_retention_holds')->insert($hold);
                } else {
                    foreach ($hold as $key => $value) {
                        if ((string) ($existing->{$key} ?? '') !== (string) $value) {
                            throw new \RuntimeException('restore_hold_reconciliation_required');
                        }
                    }
                }
            }
            $fault?->__invoke('manifests');
            if (! hash_equals($epoch, $external->current())) {
                throw new \RuntimeException('external_epoch_changed');
            }
            if ($runtime->restore_epoch === $epoch && DB::table('auth_audit_events')->where('event_type', 'restore.reconciled')->where('request_id', $epoch)->exists()) {
                DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);

                return;
            }
            DB::statement('UPDATE users SET auth_version=auth_version+1');
            $fault?->__invoke('versions');
            DB::statement('UPDATE auth_challenges SET invalidated_at=clock_timestamp() WHERE consumed_at IS NULL AND invalidated_at IS NULL');
            DB::statement("UPDATE auth_flows SET state='invalidated',invalidated_at=clock_timestamp() WHERE state IN ('pending','primary_verified')");
            DB::table('sessions')->delete();
            DB::statement('UPDATE mfa_recovery_codes SET revoked_at=clock_timestamp() WHERE used_at IS NULL AND revoked_at IS NULL');
            DB::statement('UPDATE auth_recovery_grants SET revoked_at=clock_timestamp() WHERE exchanged_at IS NULL AND revoked_at IS NULL');
            DB::statement("UPDATE auth_recovery_cases SET state='cancelled',closed_at=clock_timestamp() WHERE closed_at IS NULL");
            $fault?->__invoke('capabilities');
            // Pending-only row може видалятися лише без replay history та children.
            DB::statement('DELETE FROM user_totp_factors f WHERE confirmed_at IS NULL AND last_used_step IS NULL AND NOT EXISTS(SELECT FROM mfa_recovery_codes c WHERE c.user_id=f.user_id)');
            DB::statement('UPDATE user_totp_factors SET pending_secret_ciphertext=NULL,pending_key_version=NULL,pending_expires_at=NULL,secret_ciphertext=NULL,key_version=NULL,recovery_batch_id=NULL,disabled_at=coalesce(disabled_at,statement_timestamp()),updated_at=statement_timestamp() WHERE confirmed_at IS NOT NULL');
            $fault?->__invoke('factors');
            // Усі snapshot jobs quarantined видаленням; жодної десеріалізації чи replay.
            DB::table('jobs')->delete();
            DB::table('failed_jobs')->delete();
            DB::statement("UPDATE auth_security_notice_outbox SET state='dead',finished_at=clock_timestamp(),lease_token=NULL,lease_until=NULL,recipient_ciphertext=NULL,recipient_key_version=NULL,last_error_code='policy' WHERE state IN ('pending','leased')");
            $fault?->__invoke('delivery');
            if (! hash_equals($epoch, $external->current())) {
                throw new \RuntimeException('external_epoch_changed');
            }
            DB::table('auth_security_runtime')->where('id', 1)->update(['restore_epoch' => $epoch, 'last_reconciled_at' => DB::raw('clock_timestamp()'),
                'state' => 'recovery_required', 'issuance_not_before' => DB::raw("clock_timestamp()+interval '24 hours'")]);
            DB::table('auth_audit_events')->insert(['event_type' => 'restore.reconciled', 'outcome' => 'success', 'request_id' => $epoch]);
            $fault?->__invoke('reconciled');
        });
        $fault?->__invoke('committed');
    }
}

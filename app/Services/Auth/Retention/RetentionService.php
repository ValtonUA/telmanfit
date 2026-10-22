<?php

namespace App\Services\Auth\Retention;

use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RetentionService
{
    /** Read-only; не створює run/batch, не експортує archive. */
    public function dryRun(string $entity = 'audit'): array
    {
        app(RestoreFence::class)->assert();

        if ($entity !== 'audit') {
            return DB::select('SELECT id FROM auth_retention_candidates(?,clock_timestamp()) ORDER BY id LIMIT 500', [$entity]);
        }

        return DB::select("SELECT id FROM auth_audit_events a WHERE occurred_at<=clock_timestamp()-interval '180 days'
            AND NOT EXISTS(SELECT FROM auth_retention_holds h WHERE h.released_at IS NULL AND
                (h.subject_user_id IN (a.actor_user_id,a.subject_user_id) OR h.case_id=a.case_reference OR
                 (a.occurred_at>=h.audit_from AND a.occurred_at<h.audit_to) OR
                 EXISTS(SELECT FROM auth_recovery_cases c WHERE c.id=h.case_id AND c.target_user_id IN (a.actor_user_id,a.subject_user_id))))
            ORDER BY occurred_at,id LIMIT 500");
    }

    public function start(): string
    {
        app(RestoreFence::class)->assert();
        $id = (string) Str::uuid();
        DB::table('auth_retention_runs')->insert(['id' => $id, 'mode' => 'apply', 'policy_version' => 1, 'cutoff' => DB::raw('clock_timestamp()'), 'state' => 'running', 'request_id' => $id]);

        return $id;
    }

    public function finish(string $run, bool $failed = false): void
    {
        app(RestoreFence::class)->assert();
        $this->transaction(fn () => DB::select('SELECT auth_retention_finish(?::uuid,?)', [$run, $failed]));
    }

    public function cleanup(string $run, int $batch, string $entity): int
    {
        app(RestoreFence::class)->assert();
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('retention_unavailable');
        }

        return $this->transaction(fn () => (int) DB::selectOne('SELECT auth_retention_cleanup(?::uuid,?,?) n', [$run, $batch, $entity])->n, $run);
    }

    public function apply(string $run, int $batch, string $manifest, array $ids): int
    {
        app(RestoreFence::class)->assert();
        if (! app()->environment(['local', 'testing']) || ! app()->bound(ArchiveVerification::class)
            || count($ids) < 1 || count($ids) > 500 || count(array_unique($ids)) !== count($ids)
            || count(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) !== count($ids)) {
            throw new \RuntimeException('retention_unavailable');
        }
        $receipt = DB::table('auth_archive_manifests')->where('id', $manifest)->first();
        if (! $receipt || ! app(ArchiveVerification::class)->verify($receipt)) {
            throw new \RuntimeException('archive_unverified');
        }

        // Немає caller predicates/SQL/verified flag. SQL повторно перевіряє holds/age/exact content.
        return $this->transaction(fn () => (int) DB::selectOne('SELECT auth_retention_purge_audit(?::uuid,?,?::uuid,?::bigint[]) n',
            [$run, $batch, $manifest, '{'.implode(',', $ids).'}'])->n, $run);
    }

    private function transaction(callable $operation, ?string $run = null): mixed
    {
        try {
            return DB::transaction(function () use ($operation) {
                DB::statement("SET LOCAL lock_timeout='2s'");
                DB::statement("SET LOCAL statement_timeout='10s'");

                return $operation();
            });
        } catch (\Throwable) {
            // Після rollback; crash не робить failed, отже durable running можна поновити.
            if ($run !== null && DB::transactionLevel() === 0) {
                try {
                    DB::transaction(fn () => DB::select('SELECT auth_retention_finish(?::uuid,true)', [$run]));
                } catch (\Throwable) {
                    Log::error('retention.failed', ['request_id' => $run, 'audit' => 'unavailable']);
                }
            }
            throw new \RuntimeException('retention_unavailable');
        }
    }
}

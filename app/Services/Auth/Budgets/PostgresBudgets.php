<?php

namespace App\Services\Auth\Budgets;

use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/** Admission connection ніколи не володіє B1 session lock або domain rows. */
final class PostgresBudgets
{
    /** @param list<BudgetKey> $keys */
    public function admit(array $keys): BudgetDecision
    {
        return $this->separate($keys, 'consume');
    }

    public function inspectFailures(array $keys): BudgetDecision
    {
        return $this->separate($keys, 'check');
    }

    private function separate(array $keys, string $mode): BudgetDecision
    {
        $connection = null;
        try {
            if (! SyntheticBudgets::enabled()) {
                throw new BudgetUnavailable;
            }
            $config = DB::connection()->getConfig();
            if (($config['options'][\PDO::ATTR_PERSISTENT] ?? false) || isset($config['read']) || isset($config['write'])) {
                throw new BudgetUnavailable;
            }
            $connection = app('db.factory')->make($config, 'auth6b6_admission');
            if ($connection->getPdo() === DB::connection()->getPdo() || $connection->transactionLevel() !== 0
                || $connection->getPdo()->inTransaction()
                || $connection->selectOne('SELECT pg_backend_pid() pid')->pid === DB::selectOne('SELECT pg_backend_pid() pid')->pid) {
                throw new BudgetUnavailable;
            }
            $result = $connection->transaction(function () use ($connection, $keys, $mode) {
                $this->timeouts($connection);
                app(RestoreFence::class)->assertOn($connection, issuance: true);
                $decision = $this->group($connection, $keys, $mode);
                app(RestoreFence::class)->assertOn($connection, issuance: true);

                return $decision;
            }, 1);
            // Втрачена/неоднозначна відповідь commit не повторюється й не відкриває domain.
            app(RestoreFence::class)->assertOn($connection, issuance: true);

            return $result;
        } catch (\Throwable) {
            throw new BudgetUnavailable;
        } finally {
            $connection?->disconnect();
        }
    }

    /** Після user → flow → challenge/factor. Та сама transaction, що state/audit. */
    public function failures(array $keys, bool $record = false): BudgetDecision
    {
        try {
            if (! SyntheticBudgets::enabled() || DB::transactionLevel() < 1) {
                throw new BudgetUnavailable;
            }
            $this->timeouts(DB::connection());
            app(RestoreFence::class)->assert();

            return $this->group(DB::connection(), $keys, $record ? 'record' : 'check');
        } catch (\Throwable) {
            throw new BudgetUnavailable;
        }
    }

    public function cleanup(int $limit = 500): int
    {
        try {
            if (! SyntheticBudgets::enabled() || DB::transactionLevel() !== 0) {
                throw new BudgetUnavailable;
            }

            return DB::transaction(function () use ($limit) {
                $this->timeouts(DB::connection());
                app(RestoreFence::class)->assert();

                return (int) DB::selectOne('SELECT public.auth6b6_cleanup(?) n', [$limit])->n;
            }, 1);
        } catch (\Throwable) {
            throw new BudgetUnavailable;
        }
    }

    private function timeouts(Connection $connection): void
    {
        if ($connection->selectOne('SHOW transaction_isolation')->transaction_isolation !== 'read committed') {
            throw new BudgetUnavailable;
        }
        $connection->statement("SET LOCAL synchronous_commit='on'");
        $connection->statement("SET LOCAL lock_timeout='2s'");
        $connection->statement("SET LOCAL statement_timeout='3s'");
    }

    private function group(Connection $connection, array $keys, string $mode): BudgetDecision
    {
        if ($keys === [] || count($keys) > 32) {
            throw new BudgetUnavailable;
        }
        $scopes = $hashes = [];
        foreach ($keys as $key) {
            if (! $key instanceof BudgetKey) {
                throw new BudgetUnavailable;
            }
            $scopes[] = $key->scope->value;
            $hashes[] = $key->hash;
        }
        $row = $connection->selectOne('SELECT * FROM public.auth6b6_group(?::text[],?::text[],?)',
            ['{'.implode(',', $scopes).'}', '{'.implode(',', $hashes).'}', $mode]);

        return new BudgetDecision($row->allowed, (int) $row->retry_after, (float) $row->lock_acquisition_ms);
    }
}

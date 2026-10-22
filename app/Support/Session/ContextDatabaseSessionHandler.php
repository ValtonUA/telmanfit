<?php

namespace App\Support\Session;

use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;

final class ContextDatabaseSessionHandler extends DatabaseSessionHandler
{
    public function gc($lifetime): int
    {
        if (config('database.default') !== 'pgsql' && app()->environment(['local', 'testing'])) {
            return parent::gc(max(43200, $lifetime));
        }
        $count = 0;
        try {
            // По одній транзакції/ID: session lock → user → payload. Немає lock chain між IDs.
            $ids = $this->connection->transaction(function () use ($lifetime) {
                $this->connection->statement("SET LOCAL lock_timeout='2s'");
                $this->connection->statement("SET LOCAL statement_timeout='5s'");
                if ($this->connection->selectOne("SELECT current_setting('transaction_isolation') level")->level !== 'read committed') {
                    throw new \RuntimeException('retention_snapshot_unavailable');
                }
                app(RestoreFence::class)->assert();

                return $this->eligible($lifetime)->orderBy('id')->limit(500)->pluck('id');
            });
            foreach ($ids as $id) {
                $count += $this->connection->transaction(function () use ($id, $lifetime) {
                    $this->connection->statement("SET LOCAL lock_timeout='2s'");
                    $this->connection->statement("SET LOCAL statement_timeout='5s'");
                    if (! $this->connection->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?,606)) ok', [hash('sha256', $id)])->ok) {
                        return 0;
                    }
                    $this->connection->select('SELECT pg_advisory_xact_lock_shared(6060505)');
                    $session = $this->getQuery()->where('id', $id)->first();
                    if (! $session) {
                        return 0;
                    }
                    if ($session->user_id !== null) {
                        $this->connection->table('users')->where('id', $session->user_id)->lockForUpdate()->first();
                    }
                    // Недоступний registry дає exception/rollback; TTL не змінюється.
                    $held = $session->user_id === null
                        ? $this->connection->table('auth_retention_holds')->whereNull('released_at')->exists()
                        : $this->connection->table('auth_retention_holds as h')->whereNull('released_at')
                            ->where(function ($query) use ($session) {
                                $query->where('subject_user_id', $session->user_id)
                                    ->orWhereRaw('?::timestamptz>=audit_from AND ?::timestamptz<audit_to', [date('c', $session->last_activity), date('c', $session->last_activity)])
                                    ->orWhereExists(fn ($case) => $case->selectRaw('1')->from('auth_recovery_cases as c')
                                        ->whereColumn('c.id', 'h.case_id')->where('c.target_user_id', $session->user_id));
                            })->exists();
                    if ($held) {
                        return 0;
                    }

                    return $this->eligible($lifetime)->where('id', $id)->delete();
                });
            }
        } catch (\Throwable) {
            // GC не є auth authority. Failed query/savepoint rollback зберігає
            // payload і працездатність outer transaction; business gate дає 503.
            return $count;
        }

        return $count;
    }

    private function eligible($lifetime)
    {
        $now = $this->currentTime();

        return $this->getQuery()->where(function ($query) use ($now, $lifetime) {
            $query->where('last_activity', '<=', $now - max(43200, $lifetime))
                ->orWhere(function ($admin) use ($now) {
                    $admin->where('last_activity', '<=', $now - 900)
                        ->whereExists(fn ($state) => $state->selectRaw('1')->from('auth_session_states')
                            ->whereColumn('session_id', $this->table.'.id')->where('audience', 'admin'));
                })
                ->orWhereExists(fn ($state) => $state->selectRaw('1')->from('auth_session_states')
                    ->whereColumn('session_id', $this->table.'.id')->where('absolute_expires_at', '<=', DB::raw('clock_timestamp()')));
        });
    }
}

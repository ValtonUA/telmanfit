<?php

namespace App\Support\Session;

use Illuminate\Session\DatabaseSessionHandler;

final class ContextDatabaseSessionHandler extends DatabaseSessionHandler
{
    public function gc($lifetime): int
    {
        // Без assurance audience невідома (guest/restricted/legacy): зберігаємо
        // щонайменше client idle TTL. Read усе одно застосовує request-specific TTL.
        if (! config('mfa.enabled') && ! config('auth4.enabled')) {
            return parent::gc(max(43200, $lifetime));
        }
        $now = $this->currentTime();

        return $this->getQuery()->where(function ($query) use ($now, $lifetime) {
            $query->where('last_activity', '<=', $now - max(43200, $lifetime))
                ->orWhere(function ($admin) use ($now) {
                    $admin->where('last_activity', '<=', $now - 900)
                        ->whereExists(fn ($state) => $state->selectRaw('1')->from('auth_session_states')
                            ->whereColumn('session_id', $this->table.'.id')->where('audience', 'admin'));
                });
        })->delete();
    }
}

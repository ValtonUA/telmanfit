<?php

namespace App\Services\Auth\Recovery;

use Illuminate\Support\Facades\DB;

final class RecoveryGate
{
    public static function hasHistory(int $id): bool
    {
        return DB::table('auth_recovery_gates')->where('user_id', $id)->exists();
    }

    /** Виклик після user lock; feature flags не вимикають quarantine. */
    public static function blocked(int $id): bool
    {
        return DB::table('auth_recovery_gates')->where('user_id', $id)->whereNull('released_at')->exists();
    }
}

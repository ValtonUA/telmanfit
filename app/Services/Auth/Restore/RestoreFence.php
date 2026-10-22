<?php

namespace App\Services\Auth\Restore;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RestoreFence
{
    public function assert(bool $issuance = false): void
    {
        try {
            if (! app()->bound(ExternalEpoch::class)) {
                throw new \RuntimeException;
            }
            $epoch = app(ExternalEpoch::class)->current();
            $row = DB::table('auth_security_runtime')->where('id', 1)->first();
            if (! Str::isUuid($epoch) || ! $row || $row->state !== 'ready' || ! hash_equals($row->restore_epoch, $epoch)
                || ($issuance && DB::selectOne('SELECT ?::timestamptz>clock_timestamp() blocked', [$row->issuance_not_before])->blocked)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new \RuntimeException('security_runtime_unavailable');
        }
    }
}

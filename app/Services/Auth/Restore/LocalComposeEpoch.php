<?php

namespace App\Services\Auth\Restore;

use Illuminate\Support\Str;

/** Незалежний постійний epoch лише ізольованого Docker Compose. */
final class LocalComposeEpoch implements ExternalEpoch
{
    public function current(): string
    {
        $db = config('database.connections.pgsql');
        $path = config('local_compose.epoch_file');
        if (! app()->environment('local') || config('local_compose.enabled') !== true
            || config('database.default') !== 'pgsql' || ! empty($db['url'])
            || $db['host'] !== 'database' || $db['username'] !== 'telmanfit_local_runner'
            || ! preg_match('/^telmanfit_local_[a-f0-9]{16}$/D', $db['database'])
            || $path !== '/run/telmanfit-epoch/epoch'
            || is_link($path) || ! is_file($path) || filesize($path) > 64) {
            throw new \RuntimeException('local_compose_unavailable');
        }
        $epoch = trim((string) file_get_contents($path));
        if (! Str::isUuid($epoch)) {
            throw new \RuntimeException('external_epoch_unavailable');
        }

        return $epoch;
    }
}

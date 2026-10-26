<?php

namespace App\Services\Auth\Restore;

use Illuminate\Support\Str;

/** Незалежний файл лише одноразового локального стенда; не restore adapter production. */
final class LocalDemoEpoch implements ExternalEpoch
{
    public function current(): string
    {
        $db = config('database.connections.pgsql');
        $path = config('local_demo.epoch_file');
        if (! app()->environment('local') || config('local_demo.enabled') !== true
            || config('database.default') !== 'pgsql' || ! empty($db['url'])
            || $db['host'] !== '127.0.0.1' || $db['username'] !== 'telmanfit_demo_runner'
            || ! preg_match('/^telmanfit_demo_[a-f0-9]{16}$/D', $db['database'])
            || ! is_string($path) || ! preg_match('~^/tmp/telmanfit-auth4-[a-zA-Z0-9]+/epoch$~D', $path)
            || is_link($path) || ! is_file($path) || filesize($path) > 64) {
            throw new \RuntimeException('local_demo_unavailable');
        }
        $epoch = trim((string) file_get_contents($path));
        if (! Str::isUuid($epoch)) {
            throw new \RuntimeException('external_epoch_unavailable');
        }

        return $epoch;
    }
}

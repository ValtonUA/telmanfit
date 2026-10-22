<?php

namespace App\Services\Auth\Restore;

use Illuminate\Foundation\FileBasedMaintenanceMode;

/** Marker лише приватного synthetic cluster; жодних storage/framework maintenance files. */
final class SyntheticMaintenanceMode extends FileBasedMaintenanceMode
{
    protected function path(): string
    {
        $socket = (string) getenv('DB_HOST');
        if (! app()->environment('testing') || getenv('TELMANFIT_PG_TEST') !== '1'
            || ! preg_match('~^/tmp/telmanfit-stage1\.[a-zA-Z0-9]+/socket$~D', $socket)
            || getenv('DB_DATABASE') !== 'telmanfit_stage1_test' || getenv('DB_USERNAME') !== 'telmanfit_test_runner') {
            throw new \RuntimeException('synthetic_maintenance_unavailable');
        }

        return dirname($socket).'/maintenance.json';
    }
}

<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Services\Auth\Restore\ExternalEpoch;
use App\Services\Auth\Restore\LocalComposeEpoch;
use Tests\TestCase;

class LocalComposeEpochTest extends TestCase
{
    public function test_provider_is_opt_in_and_never_registers_in_production(): void
    {
        $this->app->offsetUnset(ExternalEpoch::class);
        config(['local_compose.enabled' => true]);
        foreach (['production', 'staging', 'testing'] as $environment) {
            $this->app->instance('env', $environment);
            (new AppServiceProvider($this->app))->register();
            $this->assertFalse($this->app->bound(ExternalEpoch::class));
        }
        $this->app->instance('env', 'local');
        config(['local_compose.enabled' => false]);
        (new AppServiceProvider($this->app))->register();
        $this->assertFalse($this->app->bound(ExternalEpoch::class));
        config(['local_compose.enabled' => true]);
        (new AppServiceProvider($this->app))->register();
        $this->assertInstanceOf(LocalComposeEpoch::class, app(ExternalEpoch::class));
    }

    public function test_rejects_non_compose_database_and_epoch_sources(): void
    {
        $this->app->instance('env', 'local');
        config(['local_compose.enabled' => true, 'database.default' => 'pgsql',
            'database.connections.pgsql.url' => null, 'database.connections.pgsql.host' => 'database',
            'database.connections.pgsql.username' => 'telmanfit_local_runner',
            'database.connections.pgsql.database' => 'telmanfit_local_0123456789abcdef']);
        foreach (['local_compose.enabled' => false, 'database.default' => 'sqlite',
            'database.connections.pgsql.url' => 'postgres://existing',
            'database.connections.pgsql.host' => 'remote.example',
            'database.connections.pgsql.username' => 'postgres',
            'database.connections.pgsql.database' => 'Telmanfit_dev',
            'local_compose.epoch_file' => '/tmp/epoch'] as $key => $value) {
            $original = config($key);
            config([$key => $value]);
            try {
                (new LocalComposeEpoch)->current();
                $this->fail('Unsafe configuration accepted: '.$key);
            } catch (\RuntimeException $error) {
                $this->assertSame('local_compose_unavailable', $error->getMessage());
            } finally {
                config([$key => $original]);
            }
        }
    }
}

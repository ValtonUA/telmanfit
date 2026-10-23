<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminMfaBoundary;
use App\Providers\AppServiceProvider;
use App\Services\Auth\Restore\ExternalEpoch;
use App\Services\Auth\Restore\LocalDemoEpoch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocalDemoEpochTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = '/tmp/telmanfit-auth4-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir.'/epoch', (string) Str::uuid());
        $this->app->instance('env', 'local');
        config(['local_demo.enabled' => true, 'local_demo.epoch_file' => $this->dir.'/epoch',
            'database.default' => 'pgsql', 'database.connections.pgsql.url' => null,
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.username' => 'telmanfit_demo_runner',
            'database.connections.pgsql.database' => 'telmanfit_demo_0123456789abcdef']);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dir.'/epoch')) {
            unlink($this->dir.'/epoch');
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_reads_independent_file_again_on_every_call(): void
    {
        $reader = new LocalDemoEpoch;
        $first = $reader->current();
        $next = (string) Str::uuid();
        file_put_contents($this->dir.'/epoch', $next);
        $this->assertNotSame($first, $reader->current());
        $this->assertSame($next, $reader->current());
    }

    public function test_missing_or_invalid_epoch_has_no_fallback(): void
    {
        file_put_contents($this->dir.'/epoch', 'invalid');
        try {
            (new LocalDemoEpoch)->current();
            $this->fail('Invalid epoch accepted');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        unlink($this->dir.'/epoch');
        $this->expectException(\RuntimeException::class);
        (new LocalDemoEpoch)->current();
    }

    public function test_rejects_every_non_demo_connection_and_environment(): void
    {
        foreach (['local_demo.enabled' => false, 'database.default' => 'sqlite',
            'database.connections.pgsql.host' => 'remote.example',
            'database.connections.pgsql.username' => 'postgres',
            'database.connections.pgsql.database' => 'Telmanfit_dev',
            'database.connections.pgsql.url' => 'postgres://other',
            'local_demo.epoch_file' => base_path('epoch')] as $key => $value) {
            $original = config($key);
            config([$key => $value]);
            try {
                (new LocalDemoEpoch)->current();
                $this->fail('Unsafe config accepted: '.$key);
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            } finally {
                config([$key => $original]);
            }
        }
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        (new LocalDemoEpoch)->current();
    }

    public function test_provider_is_opt_in_and_never_binds_demo_in_production(): void
    {
        $this->app->forgetInstance(ExternalEpoch::class);
        (new AppServiceProvider($this->app))->register();
        $this->assertInstanceOf(LocalDemoEpoch::class, app(ExternalEpoch::class));
        $this->app->offsetUnset(ExternalEpoch::class);
        $this->app->instance('env', 'production');
        (new AppServiceProvider($this->app))->register();
        $this->assertFalse($this->app->bound(ExternalEpoch::class));
        $this->app->instance('env', 'local');
        config(['local_demo.enabled' => false]);
        (new AppServiceProvider($this->app))->register();
        $this->assertFalse($this->app->bound(ExternalEpoch::class));
    }

    public function test_pg_admin_fails_closed_without_external_epoch_even_with_mfa_off(): void
    {
        $this->app->offsetUnset(ExternalEpoch::class);
        config(['mfa.enabled' => false]);
        try {
            (new AdminMfaBoundary)->handle(Request::create('/admin'), fn () => $this->fail('Fence bypassed'));
            $this->fail('Expected 503');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertSame('security_runtime_unavailable', $error->getMessage());
        }
    }
}

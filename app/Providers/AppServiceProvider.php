<?php

namespace App\Providers;

use App\Http\Middleware\RequireAdminMfa;
use App\Http\Responses\AdminLogoutResponse;
use App\Mail\SyntheticTransport;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\MfaService;
use App\Services\Auth\Restore\ExternalEpoch;
use App\Services\Auth\Restore\LocalDemoEpoch;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Auth\Restore\SyntheticMaintenanceMode;
use App\Support\Session\ContextDatabaseSessionHandler;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('testing') && getenv('TELMANFIT_PG_TEST') === '1') {
            $this->app->singleton(ExternalEpoch::class, SyntheticEpoch::class);
            if (config('database.default') === 'pgsql') {
                $this->app->singleton(MaintenanceMode::class, SyntheticMaintenanceMode::class);
            }
        }
        if ($this->app->environment('local') && config('local_demo.enabled') === true) {
            $this->app->singleton(ExternalEpoch::class, LocalDemoEpoch::class);
        }
        $this->app->singleton(MfaService::class);
        $this->app->singleton(ClientAuth::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Спільна таблиця: request lifetime не визначає TTL інших contexts.
        app('session')->extend('database', fn ($app) => new ContextDatabaseSessionHandler(
            $app['db']->connection(config('session.connection')), config('session.table'), config('session.lifetime'), $app
        ));
        Mail::extend('auth4_synthetic', fn () => new SyntheticTransport);
        if (config('mfa.enabled')) {
            $this->app->bind(LogoutResponse::class, AdminLogoutResponse::class);
            Livewire::setUpdateRoute(fn ($handle) => Route::post('/admin/livewire/update', $handle)
                ->middleware(['web', RequireAdminMfa::class]));
        }
    }
}

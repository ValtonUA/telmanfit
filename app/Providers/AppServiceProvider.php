<?php

namespace App\Providers;

use App\Http\Middleware\RequireAdminMfa;
use App\Http\Responses\AdminLogoutResponse;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('mfa.enabled')) {
            $this->app->bind(LogoutResponse::class, AdminLogoutResponse::class);
            Livewire::setUpdateRoute(fn ($handle) => Route::post('/admin/livewire/update', $handle)
                ->middleware(['web', RequireAdminMfa::class]));
        }
    }
}

<?php

use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Middleware\ClientCsrf;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Stateless JSON API consumed by the React SPA (and, later, by the Telegram
| Mini App / PWA). Registered in bootstrap/app.php under the "/api" prefix
| with the "api" middleware group.
|
*/

Route::get('/health', HealthController::class)->name('api.health');

// Browser API зі штатними Laravel session/CSRF; health залишається stateless.
Route::middleware(['web', ClientCsrf::class])->group(function () {
    foreach ([
        ['get', '/auth/csrf', 'csrf'],
        ['post', '/auth/invitations/exchange', 'exchange'],
        ['post', '/auth/email-otp/request', 'request'],
        ['post', '/auth/email-otp/verify', 'verify'],
        ['post', '/auth/registration/complete', 'complete'],
        ['post', '/auth/mfa/verify', 'mfa'],
        ['get', '/me', 'me'],
        ['post', '/auth/logout', 'logout'],
    ] as [$method, $uri, $name]) {
        Route::$method($uri, ClientAuthController::class)->name('auth4.'.$name);
    }
});

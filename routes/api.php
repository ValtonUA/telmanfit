<?php

use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ClientMealPlanController;
use App\Http\Controllers\Api\ClientOnboardingController;
use App\Http\Controllers\Api\ClientSecurityController;
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
    // Одна реальна функція для клієнта: перегляд та збереження першої частини анкети.
    // Авторизація — той самий ClientSession, що в /api/me; без production bypass.
    Route::get('/onboarding', [ClientOnboardingController::class, 'show'])->name('onboarding.show');
    Route::get('/meal-plan', ClientMealPlanController::class)->name('client.meal-plan');
    Route::post('/onboarding', [ClientOnboardingController::class, 'store'])->name('onboarding.store');

    foreach ([
        ['post', 'reauth/request', 'reauth.request'],
        ['post', 'reauth/verify', 'reauth.verify'],
        ['post', 'reauth/mfa', 'reauth.mfa'],
        ['post', 'mfa/enroll', 'mfa.enroll'],
        ['post', 'mfa/confirm', 'mfa.confirm'],
        ['post', 'mfa/recovery', 'mfa.recovery'],
        ['post', 'mfa/disable', 'mfa.disable'],
        ['get', 'sessions', 'sessions'],
        ['post', 'sessions/revoke', 'sessions.revoke'],
        ['post', 'logout-all', 'logout_all'],
        ['post', 'email/request', 'email.request'],
        ['post', 'email/confirm', 'email.confirm'],
    ] as [$method, $uri, $name]) {
        Route::$method('/auth/security/'.$uri, ClientSecurityController::class)->name('auth5.'.$name);
    }
});

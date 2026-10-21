<?php

use App\Http\Controllers\Auth\AdminMfaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| React SPA fallback
|--------------------------------------------------------------------------
|
| Serves the production React build (public/app) for the "/app" prefix and
| any nested client-side route. Only this prefix is handled, so "/api" and
| "/admin" are never intercepted.
|
*/

Route::get('/app/{any?}', function () {
    $index = public_path('app/index.html');

    abort_unless(is_file($index), 404, 'SPA build not found. Run `npm run build` inside the client directory.');

    return response()->file($index, ['Content-Type' => 'text/html']);
})->where('any', '.*')->name('spa');

// AUTH-3 не змінює поточний login, поки controlled mode вимкнений.
if (config('mfa.enabled')) {
    foreach ([
        ['get', '/admin/login', 'login'],
        ['post', '/admin/auth/primary', 'primary'],
        ['get', '/admin/auth/challenge', 'challenge'],
        ['post', '/admin/auth/enroll', 'enroll'],
        ['post', '/admin/auth/confirm', 'confirm'],
        ['post', '/admin/auth/verify', 'verify'],
        ['post', '/admin/auth/logout', 'logout'],
    ] as [$method, $uri, $name]) {
        Route::$method($uri, AdminMfaController::class)->name('auth3.'.$name);
    }
}

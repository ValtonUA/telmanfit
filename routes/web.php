<?php

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

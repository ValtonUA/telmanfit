<?php

use App\Http\Controllers\Api\HealthController;
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

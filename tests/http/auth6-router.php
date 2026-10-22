<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Http\Auth6Barrier;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
require __DIR__.'/../pg/auth2-guard.php';
DB::statement("SET application_name = 'auth6_http'");
Event::listen(RouteMatched::class, fn ($event) => $event->route->middleware(Auth6Barrier::class));
require __DIR__.'/barrier.php';
$app->handleRequest(Request::capture());

<?php

use App\Services\Auth\Budgets\SyntheticBudgets;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/../pg/auth2-guard.php';
if (! SyntheticBudgets::enabled()) {
    throw new RuntimeException('b6_test_required');
}
$app->handleRequest(Request::capture());

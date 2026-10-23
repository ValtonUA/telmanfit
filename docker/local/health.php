<?php

use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Contracts\Console\Kernel;

try {
    require __DIR__.'/load-env.php';

    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    app(RestoreFence::class)->assert();
    $socket = @fsockopen('127.0.0.1', 9000, $errno, $error, 2);
    exit($socket ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Локальна перевірка недоступна.'.PHP_EOL);
    exit(1);
}

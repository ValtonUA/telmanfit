<?php

use Symfony\Component\Console\Input\ArgvInput;

/** CLI лише всередині local Compose; конфігурація ніколи не виводиться. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__.'/load-env.php';
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$arguments = $_SERVER['argv'];
array_splice($arguments, 1, 0, ['nutrition:import-xlsx']);
exit($app->handleCommand(new ArgvInput($arguments)));

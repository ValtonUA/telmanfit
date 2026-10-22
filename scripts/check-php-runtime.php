<?php

use App\Support\PhpRuntimePolicy;

// До Composer/Laravel: без .env, мережі, секретів та доступу до БД.
require_once __DIR__.'/../app/Support/PhpRuntimePolicy.php';

if (! PhpRuntimePolicy::supports(PHP_VERSION)) {
    fwrite(STDERR, 'Unsupported PHP runtime: '.PHP_VERSION.'. Require stable PHP 8.3.36+, 8.4.27+ or 8.5.12+ within those branches.'.PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PHP runtime policy OK: '.PHP_VERSION.' ('.PHP_SAPI.')'.PHP_EOL);

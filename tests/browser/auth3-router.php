<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

// Synthetic HTTP regression: lottery GC завжди запускається, включно з assurance outage.
$publicPath = dirname(__DIR__, 2).'/public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$resolved = realpath($publicPath.$uri);
if ($uri !== '/' && $resolved !== false && str_starts_with($resolved, realpath($publicPath))) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
unset($_SERVER['PATH_INFO']);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/../pg/auth2-guard.php';
config(['session.lottery' => [100, 100]]);
$app->handleRequest(Request::capture());

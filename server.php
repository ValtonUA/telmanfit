<?php

/*
|--------------------------------------------------------------------------
| Local development server router
|--------------------------------------------------------------------------
|
| `php artisan serve` automatically uses this file as the router script for
| the PHP built-in web server. It emulates the Apache/nginx "front controller"
| rewrite that the production web server performs: real files are returned
| as-is, every other request is handed to public/index.php.
|
| It also works around a quirk of the built-in server: when the request path
| lives below an existing directory (such as the SPA build in "public/app"),
| PHP splits the URL into SCRIPT_NAME + PATH_INFO, so Laravel would resolve
| "/foo" instead of "/app/foo" and the SPA fallback route would not match.
| Normalising those server variables preserves the original request URI.
|
*/

$publicPath = __DIR__.'/public';

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Serve existing static files (assets, favicons, ...) with the built-in server.
if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

$resolved = realpath($publicPath.$uri);

if ($uri !== '/' && $resolved !== false && str_starts_with($resolved, realpath($publicPath))) {
    return false;
}

// Keep Laravel's path info equal to the real request URI.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
unset($_SERVER['PATH_INFO']);

require_once $publicPath.'/index.php';

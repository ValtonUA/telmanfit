<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** Browser API вимагає token також для same-origin fetch (Laravel 13 origin shortcut). */
final class ClientCsrf extends PreventRequestForgery
{
    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}

<?php

use App\Http\Middleware\AdminMfaBoundary;
use App\Http\Middleware\AdminSessionContext;
use App\Http\Middleware\ClientAuthBoundary;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\MfaDenied;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ClientAuthBoundary::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AdminMfaBoundary::class);
        $middleware->web(prepend: [AdminSessionContext::class], append: [AdminMfaBoundary::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(fn (Throwable $e) => request()->is('api/auth/*', 'api/me'));
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/auth/*', 'api/me')) {
                return null;
            }
            if ($e instanceof AuthDenied) {
                return response()->json(['code' => $e->reason], $e->status, $e->status === 429 ? ['Retry-After' => '900'] : []);
            }
            if ($e instanceof ValidationException) {
                return response()->json(['code' => 'validation_error', 'errors' => $e->errors()], 422);
            }
            if ($e instanceof HttpExceptionInterface) {
                return response()->json(['code' => $e->getStatusCode() === 419 ? 'csrf_mismatch' : 'request_denied'], $e->getStatusCode());
            }

            return response()->json(['code' => $e instanceof MfaDenied ? 'invalid_challenge' : 'auth_unavailable'], $e instanceof MfaDenied ? 422 : 503);
        });
        $exceptions->dontFlash(['password', 'password_confirmation', 'code', 'secret', 'recovery', 'token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

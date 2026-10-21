<?php

namespace App\Http\Middleware;

use App\Services\Auth\AdminAssurance;
use App\Services\Auth\MfaDenied;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RequireAdminMfa
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('mfa.enabled')) {
            return $next($request);
        }
        try {
            // Lock тримається до завершення дії: revoke не переганяє sensitive write.
            return DB::transaction(function () use ($request, $next) {
                app(AdminAssurance::class)->assert($request);

                return $next($request);
            });
        } catch (MfaDenied) {
            abort(403, 'Потрібен повний вхід із MFA.');
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Throwable) {
            // Не логувати SQL bindings із секретами, не пропускати при outage.
            abort(503, 'Перевірка доступу тимчасово недоступна.');
        }
    }
}

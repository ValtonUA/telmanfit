<?php

namespace App\Http\Middleware;

use App\Services\Auth\AdminAssurance;
use App\Services\Auth\MfaDenied;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RequireAdminMfa
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('mfa.enabled')) {
            return $next($request);
        }
        $businessException = null;
        try {
            // Lock тримається до завершення дії: revoke не переганяє sensitive write.
            return DB::transaction(function () use ($request, $next, &$businessException) {
                app(AdminAssurance::class)->assert($request);

                try {
                    return $next($request);
                } catch (\Throwable $exception) {
                    // Зберегти саме цей виняток для штатного Laravel/Livewire handler.
                    $businessException = $exception;
                    throw $exception;
                }
            });
        } catch (\Throwable $exception) {
            if ($exception instanceof MfaDenied) {
                abort(403, 'Потрібен повний вхід із MFA.');
            }
            if ($exception === $businessException) {
                throw $exception;
            }
            // Збій assurance, begin/commit/rollback: відмова без SQL bindings.
            abort(503, 'Перевірка доступу тимчасово недоступна.');
        }
    }
}

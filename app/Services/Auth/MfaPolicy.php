<?php

namespace App\Services\Auth;

final class MfaPolicy
{
    public function assertControlled(): void
    {
        if (! config('mfa.enabled') || ! app()->environment(['local', 'testing'])
            || config('database.default') !== 'pgsql' || config('session.driver') !== 'database') {
            throw new MfaDenied;
        }
        foreach (['flow_seconds', 'session_seconds', 'attempts', 'recovery_count'] as $name) {
            if (filter_var(config('mfa.'.$name), FILTER_VALIDATE_INT) === false || (int) config('mfa.'.$name) < 1) {
                throw new MfaDenied;
            }
        }
        if (! in_array((string) config('mfa.window'), ['0', '1'], true)
            || ! preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', (string) config('mfa.key_version'))
            || (int) config('mfa.recovery_count') > 20) {
            throw new MfaDenied;
        }
    }
}

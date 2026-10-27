<?php

namespace App\Support\Library;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Без fetch/redirect на сервері; лише браузерне HTTPS до справжнього DNS host. */
final class ExternalContentUrl implements ValidationRule
{
    public static function safe(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7f<>"\\\\]/', $value)) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment'])
            || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (! str_contains($host, '.') || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('/(?:^|\.)(?:localhost|local|internal|lan|test|invalid|example|onion)$/i', $host)
            || preg_match('/\.(?:[0-9]+|0x[0-9a-f]+)$/i', $host)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::safe($value) === null) {
            $fail('Вкажіть коректне публічне HTTPS-посилання без облікових даних.');
        }
    }
}

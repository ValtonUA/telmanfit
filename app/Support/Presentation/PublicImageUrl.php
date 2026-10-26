<?php

namespace App\Support\Presentation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Лише browser HTTPS; сервер ніколи не завантажує URL і не звертається до R2. */
final class PublicImageUrl implements ValidationRule
{
    public static function safe(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f<>"\\\\]/', $value)) {
            return null;
        }
        $parts = parse_url($value);
        $host = strtolower($parts['host'] ?? '');
        $allowed = array_map('strtolower', config('presentation.image_hosts', []));
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || isset($parts['port']) || ! in_array($host, $allowed, true)
            || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('/\.(?:[0-9]+|0x[0-9a-f]+)$/i', $host)
            || preg_match('/(?:^|\.)(?:localhost|local|localdomain|lan|home|internal|intranet|corp|onion|test|invalid)$/i', $host)
            || preg_match('/\.(?:svg|html?|xml)(?:$|[?])/i', rawurldecode($parts['path'] ?? ''))) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::safe($value) === null) {
            $fail('Вкажіть публічне HTTPS-посилання з дозволеного домену. Якщо домен ще не налаштовано, залиште фото порожнім.');
        }
    }
}

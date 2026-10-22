<?php

namespace App\Services\Auth\Client;

/** P3: ASCII local-part, UTS46 nontransitional IDNA, без provider-specific правил. */
final class EmailNormalizer
{
    public function normalize(string $email): string
    {
        $email = trim($email);
        if (substr_count($email, '@') !== 1 || strlen($email) > 254) {
            throw new AuthDenied('invalid_email');
        }
        [$local, $domain] = explode('@', $email, 2);
        if (! preg_match('/^[\x21-\x7e]+$/D', $local) || ! function_exists('idn_to_ascii')) {
            throw new AuthDenied('invalid_email');
        }
        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46, $info);
        $result = strtolower($local.'@'.($ascii ?: ''));
        if (! $ascii || $info['errors'] !== 0 || strlen($result) > 254 || ! filter_var($result, FILTER_VALIDATE_EMAIL)) {
            throw new AuthDenied('invalid_email');
        }

        return $result;
    }
}

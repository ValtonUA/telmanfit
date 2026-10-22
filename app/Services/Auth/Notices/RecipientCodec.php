<?php

namespace App\Services\Auth\Notices;

use Illuminate\Encryption\Encrypter;

final class RecipientCodec
{
    private function codec(string $version): Encrypter
    {
        $key = config('auth_notices.keys.'.$version);
        if (! is_string($key) || ! str_starts_with($key, 'base64:')) {
            throw new NoticeUnavailable;
        }
        $bytes = base64_decode(substr($key, 7), true);
        if (! is_string($bytes) || strlen($bytes) !== 32) {
            throw new NoticeUnavailable;
        }

        return new Encrypter($bytes, 'AES-256-CBC');
    }

    public function encrypt(#[\SensitiveParameter] string $recipient, string $version): string
    {
        try {
            return $this->codec($version)->encryptString($recipient);
        } catch (\Throwable) {
            throw new NoticeUnavailable;
        }
    }

    public function decrypt(#[\SensitiveParameter] string $ciphertext, string $version): string
    {
        try {
            return $this->codec($version)->decryptString($ciphertext);
        } catch (\Throwable) {
            throw new NoticeUnavailable;
        }
    }
}

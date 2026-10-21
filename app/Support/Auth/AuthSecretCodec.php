<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/** Лише codec сховища AUTH-2. Не перевіряє OTP/TOTP та не авторизує користувача. */
final class AuthSecretCodec
{
    public function encryptTotp(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    public function decryptTotp(string $ciphertext): string
    {
        return Crypt::decryptString($ciphertext);
    }

    public function hashRecoveryCode(string $code): string
    {
        return Hash::driver('argon2id')->make($code);
    }

    public function hashInvitationToken(string $token): string
    {
        // Викликач має генерувати 256+ випадкових бітів; це НЕ хеш короткого OTP.
        return hash('sha256', $token);
    }
}

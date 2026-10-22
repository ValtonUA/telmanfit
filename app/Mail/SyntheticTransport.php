<?php

namespace App\Mail;

use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** Лише синтетична поштова скринька стенда; ciphertext замість log mailer. */
final class SyntheticTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $path = config('auth4.synthetic_mailbox');
        $email = $message->getOriginalMessage();
        if (! app()->environment(['local', 'testing']) || ! is_string($path)
            || ! preg_match('~^/tmp/telmanfit-auth4-[a-zA-Z0-9]+/mailbox$~', $path)
            || ! str_ends_with(strtolower($email->getTo()[0]->getAddress()), '@example.invalid')) {
            throw new \RuntimeException('Synthetic delivery unavailable');
        }
        $data = Crypt::encryptString(json_encode(['to' => $email->getTo()[0]->getAddress(), 'html' => $email->getHtmlBody()], JSON_THROW_ON_ERROR));
        if (file_put_contents($path, $data."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Synthetic delivery unavailable');
        }
        chmod($path, 0600);
    }

    public function __toString(): string
    {
        return 'auth4_synthetic';
    }
}

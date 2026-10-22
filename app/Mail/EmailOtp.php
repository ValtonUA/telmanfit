<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class EmailOtp extends Mailable
{
    public function __construct(#[\SensitiveParameter] private string $otp) {}

    public function build(): static
    {
        return $this->subject('TelmanFit: код підтвердження')->html('<p>Код підтвердження: <strong>'.e($this->otp).'</strong></p><p>Код чинний 5 хвилин.</p>');
    }

    public function __debugInfo(): array
    {
        return ['otp' => '[redacted]'];
    }
}

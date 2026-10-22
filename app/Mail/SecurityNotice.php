<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class SecurityNotice extends Mailable
{
    public function build(): static
    {
        return $this->subject('TelmanFit: адресу входу змінено')->html('<p>Електронну адресу входу до вашого акаунта TelmanFit змінено. Усі попередні сесії відкликано. Якщо це зробили не ви, зверніться до тренера через відомий вам канал.</p>');
    }
}

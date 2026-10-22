<?php

return [
    'enabled' => env('AUTH4_CONTROLLED', false),
    'rate_key' => env('AUTH4_RATE_KEY'),
    'key_version' => env('AUTH4_OTP_KEY_VERSION'),
    'keys' => ['test_v1' => env('AUTH4_OTP_KEY')],
    // Тільки synthetic transports. Production provider потребує P6.
    'synthetic_mailbox' => env('AUTH4_SYNTHETIC_MAILBOX'),
];

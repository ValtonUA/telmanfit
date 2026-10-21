<?php

return [
    // Лише контрольований стенд. Production rollout заблоковано P3/P5/P6.
    'enabled' => env('AUTH3_CONTROLLED', false),
    'flow_seconds' => env('AUTH3_FLOW_SECONDS'),
    'session_seconds' => env('AUTH3_SESSION_SECONDS'),
    'attempts' => env('AUTH3_ATTEMPTS'),
    'window' => env('AUTH3_TOTP_WINDOW'),
    'recovery_count' => env('AUTH3_RECOVERY_COUNT'),
    'key_version' => env('AUTH3_KEY_VERSION'),
];

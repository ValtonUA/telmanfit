<?php

return [
    // Лише synthetic/local; production adapter D8 відсутній.
    'key_version' => 'app_v1',
    'keys' => ['app_v1' => env('APP_KEY')],
];

<?php

return [
    // Тільки точні публічні DNS hosts, без wildcard. Порожній список дозволяє лише NULL.
    'image_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PUBLIC_IMAGE_HOSTS', ''))))),
];

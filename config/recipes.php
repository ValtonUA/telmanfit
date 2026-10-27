<?php

return [
    // Вмикати лише після окремого rollout review; production за замовчуванням закрито.
    'batch_editor_enabled' => env('APP_ENV') === 'local'
        && env('TELMANFIT_LOCAL_COMPOSE', false)
        && env('TELMANFIT_LOCAL_RECIPE_EDITOR', false),
];

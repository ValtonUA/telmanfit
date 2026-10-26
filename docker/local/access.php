<?php

require __DIR__.'/load-env.php';

// Лише локальна Docker-консоль; жодного HTTP endpoint або секретів у logs.
if (PHP_SAPI !== 'cli' || getenv('TELMANFIT_LOCAL_COMPOSE') !== 'true' || getenv('APP_ENV') !== 'local') {
    exit(1);
}
$access = json_decode(file_get_contents('/tmp/telmanfit-auth4-compose/access.json'), true, flags: JSON_THROW_ON_ERROR);
echo "Адмінка: http://127.0.0.1:8098/admin/login\nEmail: {$access['email']}\nПароль: {$access['password']}\nЗапрошення клієнта: {$access['invitation']}\nEmail клієнта: client@example.invalid\n";

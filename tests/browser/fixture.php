<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Тільки нова БД раннера UX-1; жодного fallback на .env чи наявні дані.
if (getenv('APP_ENV') !== 'testing'
    || ! preg_match('~^/tmp/telmanfit-ux1\.[a-zA-Z0-9]+/socket$~', (string) getenv('DB_HOST'))
    || getenv('DB_DATABASE') !== 'telmanfit_ux1_test'
    || getenv('DB_USERNAME') !== 'telmanfit_test_runner'
    || DB::getDriverName() !== 'pgsql'
    || User::exists() || Client::exists()) {
    throw new RuntimeException('Потрібна порожня ізольована UX-1 БД.');
}

$admin = new User;
$admin->forceFill(['name' => 'Тестовий Адміністратор', 'email' => 'admin@example.test', 'password' => 'Synthetic-UX1-only!', 'role' => 'admin'])->save();
$coach = new User;
$coach->forceFill(['name' => 'Тестова Тренерка', 'email' => 'coach@example.test', 'role' => 'coach'])->save();
foreach (range(1, 14) as $index) {
    $account = new User;
    $account->forceFill([
        'name' => $index === 14 ? 'Олександра Довгепрізвищедляперевіркиперенесення' : sprintf('Тестовий клієнт %02d', $index),
        'email' => "client{$index}@example.test", 'role' => 'client',
    ])->save();
    $client = new Client;
    $client->forceFill([
        'user_id' => $index === 13 ? null : $account->id,
        'coach_user_id' => $coach->id,
        'status' => array_keys(Client::STATUSES)[($index - 1) % 4],
        'created_at' => '2026-10-21 10:00:00', 'updated_at' => '2026-10-21 10:00:00',
        'coaching_started_at' => $index % 4 === 2 ? '2026-10-21 10:00:00' : null,
    ])->save();
}

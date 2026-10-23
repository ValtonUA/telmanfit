<?php

use App\Services\Auth\Restore\ExternalEpoch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

try {
    require __DIR__.'/load-env.php';

    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    app(ExternalEpoch::class)->current();
    $recipient = $argv[1] ?? 'client@example.invalid';
    if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ! str_ends_with($recipient, '@example.invalid')) {
        throw new RuntimeException('Лише локальна адреса @example.invalid.');
    }
    foreach (array_reverse(@file(config('auth4.synthetic_mailbox'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        $message = json_decode(Crypt::decryptString($line), true, flags: JSON_THROW_ON_ERROR);
        if ($message['to'] === $recipient && preg_match('/<strong>([0-9]{8})<\/strong>/', $message['html'], $match)) {
            echo $match[1].PHP_EOL;
            exit;
        }
    }
    fwrite(STDERR, "Спочатку запросіть OTP для локальної адреси у /app.\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Локальна перевірка недоступна.'.PHP_EOL);
    exit(1);
}

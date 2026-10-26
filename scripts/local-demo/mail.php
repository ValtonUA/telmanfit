<?php

use App\Services\Auth\Restore\LocalDemoEpoch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(LocalDemoEpoch::class)->current();
foreach (array_reverse(@file(config('auth4.synthetic_mailbox'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
    $message = json_decode(Crypt::decryptString($line), true, flags: JSON_THROW_ON_ERROR);
    if ($message['to'] === 'client@example.invalid' && preg_match('/<strong>([0-9]{8})<\/strong>/', $message['html'], $match)) {
        echo $match[1].PHP_EOL;
        exit;
    }
}
fwrite(STDERR, "Спочатку запросіть OTP для client@example.invalid у /app.\n");
exit(1);

<?php

$dir = getenv('DEMO_WORK');
if (! is_string($dir) || ! preg_match('~^/tmp/telmanfit-auth4-[a-zA-Z0-9]+$~D', $dir) || ! is_dir($dir)) {
    throw new RuntimeException('Потрібен приватний mktemp каталог.');
}
$random = fn () => bin2hex(random_bytes(32));
$uuid = bin2hex(random_bytes(16));
$epoch = substr($uuid, 0, 8).'-'.substr($uuid, 8, 4).'-4'.substr($uuid, 13, 3).'-8'.substr($uuid, 17, 3).'-'.substr($uuid, 20);
$values = [
    'APP_ENV' => 'local', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false',
    'APP_URL' => 'http://127.0.0.1:8098', 'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_CONFIG_CACHE' => "$dir/config.php", 'APP_ROUTES_CACHE' => "$dir/routes.php",
    'APP_EVENTS_CACHE' => "$dir/events.php", 'APP_SERVICES_CACHE' => "$dir/services.php", 'APP_PACKAGES_CACHE' => "$dir/packages.php",
    'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
    'DB_DATABASE' => 'telmanfit_demo_'.bin2hex(random_bytes(8)), 'DB_USERNAME' => 'telmanfit_demo_runner', 'DB_PASSWORD' => $random(),
    'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'pgsql', 'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false',
    'CACHE_STORE' => 'database', 'DB_CACHE_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'auth4_synthetic',
    'AUTH4_SYNTHETIC_MAILBOX' => "$dir/mailbox", 'LOG_CHANNEL' => 'stderr', 'PAO_DISABLE' => '1',
    'AUTH3_CONTROLLED' => 'true', 'AUTH3_FLOW_SECONDS' => '600', 'AUTH3_SESSION_SECONDS' => '28800',
    'AUTH3_ATTEMPTS' => '10', 'AUTH3_TOTP_WINDOW' => '1', 'AUTH3_RECOVERY_COUNT' => '3', 'AUTH3_KEY_VERSION' => 'test_v1',
    'AUTH4_CONTROLLED' => 'true', 'AUTH5_CONTROLLED' => 'true', 'AUTH4_OTP_KEY_VERSION' => 'test_v1',
    'AUTH4_OTP_KEY' => $random(), 'AUTH4_RATE_KEY' => $random(),
    'TELMANFIT_PG_TEST' => '0', 'TELMANFIT_LOCAL_DEMO' => 'true', 'TELMANFIT_LOCAL_EPOCH_FILE' => "$dir/epoch",
    'DEMO_PASSWORD' => $random(), 'DEMO_INVITE' => $random(),
];
$env = '';
foreach ($values as $key => $value) {
    $env .= 'export '.$key.'='.escapeshellarg($value)."\n";
}
file_put_contents("$dir/epoch", $epoch);
file_put_contents("$dir/env.sh", $env);
file_put_contents("$dir/postgres.env", 'POSTGRES_PASSWORD='.$random()."\n");
file_put_contents("$dir/access.txt", "Admin: owner@example.invalid\nPassword: {$values['DEMO_PASSWORD']}\nInvitation: {$values['APP_URL']}/app/register#invite={$values['DEMO_INVITE']}\nClient: client@example.invalid\n");
foreach (['epoch', 'env.sh', 'postgres.env', 'access.txt'] as $file) {
    chmod("$dir/$file", 0600);
}

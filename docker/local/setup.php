<?php

// Працює лише в setup-контейнері, до запуску PostgreSQL. Жодних .env або мережі.
$state = '/tmp/telmanfit-auth4-compose';
$epochDir = '/run/telmanfit-epoch';
$secrets = '/run/database-secrets';
foreach ([$state, $epochDir, $secrets] as $dir) {
    if (! is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
}
foreach ([$state, $epochDir] as $dir) {
    chmod($dir, 0700);
}
$lock = fopen("$state/setup.lock", 'c');
flock($lock, LOCK_EX);
$required = ["$state/runtime.env", "$state/access.json", "$epochDir/epoch", "$secrets/password", "$secrets/init.sql"];
$existing = array_filter($required, 'is_file');
if ($existing) {
    if (count($existing) !== count($required) || ! is_file("$state/setup-ready")) {
        throw new RuntimeException('Неповні локальні секрети. Автоматична регенерація заборонена; відновіть volumes з резервної копії.');
    }
    echo "Постійні локальні ключі збережено.\n";
    exit;
}
if (is_file("$state/setup-ready")) {
    throw new RuntimeException('Втрачені секрети. Автоматичне відновлення заборонено.');
}
umask(0077);
$random = fn () => bin2hex(random_bytes(32));
$id = bin2hex(random_bytes(8));
$uuid = bin2hex(random_bytes(16));
$epoch = substr($uuid, 0, 8).'-'.substr($uuid, 8, 4).'-4'.substr($uuid, 13, 3).'-8'.substr($uuid, 17, 3).'-'.substr($uuid, 20);
$password = $random();
$adminPassword = $random();
$invite = $random();
$featureProject = getenv('TELMANFIT_FEATURE_PROJECT');
$featurePort = getenv('TELMANFIT_FEATURE_PORT');
if (($featureProject || $featurePort) && (! preg_match('/^telmanfit-feature-[a-f0-9]{24}$/D', $featureProject ?: '')
    || ! ctype_digit($featurePort ?: '') || (int) $featurePort < 20000 || (int) $featurePort > 59999)) {
    throw new RuntimeException('Некоректна ідентичність feature-середовища.');
}
$origin = $featureProject ? 'http://'.$featureProject.'.localhost:'.$featurePort : 'http://127.0.0.1:8098';
$values = [
    'APP_NAME' => 'TelmanFit', 'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => $origin,
    'APP_MAINTENANCE_DRIVER' => 'file', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
    'DB_HOST' => 'database', 'DB_PORT' => '5432', 'DB_DATABASE' => 'telmanfit_local_'.$id,
    'DB_USERNAME' => 'telmanfit_local_runner', 'DB_PASSWORD' => $password,
    'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'pgsql', 'SESSION_DOMAIN' => 'null',
    'SESSION_SECURE_COOKIE' => 'false', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database',
    'MAIL_MAILER' => 'auth4_synthetic', 'AUTH4_SYNTHETIC_MAILBOX' => "$state/mailbox",
    'LOG_CHANNEL' => 'stderr', 'PAO_DISABLE' => '1',
    'AUTH3_CONTROLLED' => 'true', 'AUTH3_FLOW_SECONDS' => '600', 'AUTH3_SESSION_SECONDS' => '28800',
    'AUTH3_ATTEMPTS' => '10', 'AUTH3_TOTP_WINDOW' => '1', 'AUTH3_RECOVERY_COUNT' => '3', 'AUTH3_KEY_VERSION' => 'test_v1',
    'AUTH4_CONTROLLED' => 'true', 'AUTH5_CONTROLLED' => 'true', 'AUTH4_OTP_KEY_VERSION' => 'test_v1',
    'AUTH4_OTP_KEY' => $random(), 'AUTH4_RATE_KEY' => $random(),
    'TELMANFIT_PG_TEST' => '0', 'TELMANFIT_LOCAL_DEMO' => 'false', 'TELMANFIT_LOCAL_COMPOSE' => 'true',
    'LOCAL_CLUSTER_ID' => $id,
];
if ($featureProject) {
    $values['SESSION_COOKIE'] = str_replace('-', '_', $featureProject).'_session';
}
$env = '';
foreach ($values as $key => $value) {
    $env .= 'export '.$key.'='.escapeshellarg($value)."\n";
}
file_put_contents("$state/runtime.env", $env);
file_put_contents("$state/access.json", json_encode(['email' => 'owner@example.invalid', 'password' => $adminPassword,
    'invitation' => "$origin/app/register#invite=$invite"], JSON_THROW_ON_ERROR));
file_put_contents("$epochDir/epoch", $epoch);
file_put_contents("$secrets/password", $random());
$sql = "CREATE ROLE telmanfit_local_runner LOGIN PASSWORD '$password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;\n"
    ."CREATE DATABASE {$values['DB_DATABASE']} OWNER telmanfit_local_runner TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'uk-UA';\n"
    ."REVOKE ALL ON DATABASE {$values['DB_DATABASE']} FROM PUBLIC;\n"
    ."COMMENT ON DATABASE {$values['DB_DATABASE']} IS 'telmanfit-compose:$id';\n";
file_put_contents("$secrets/init.sql", $sql);
// PostgreSQL отримує лише свої файли; web-контейнер не отримує жодних секретів.
chmod($secrets, 0755);
foreach (["$secrets/password", "$secrets/init.sql"] as $file) {
    chmod($file, 0444);
}
foreach ([$state, $epochDir, "$state/runtime.env", "$state/access.json", "$epochDir/epoch"] as $file) {
    chown($file, 33);
    chgrp($file, 33);
}
file_put_contents("$state/setup-ready", '1');
echo "Початкові локальні секрети створено. Реквізити: docker compose exec app php docker/local/access.php\n";

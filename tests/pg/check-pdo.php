<?php

// Перевірка драйвера, яким користується Laravel, без bootstrap/.env/міграцій.
$socket = getenv('PG_TEST_SOCKET_DIR');
$password = getenv('PGPASSWORD');
if (! preg_match('~^/tmp/telmanfit-g4\.[a-zA-Z0-9]+/socket$~', (string) $socket) || ! $password) {
    fwrite(STDERR, "Missing isolated test connection parameters\n");
    exit(2);
}
try {
    $pdo = new PDO(
        "pgsql:host={$socket};port=5432;dbname=telmanfit_test;connect_timeout=10",
        'telmanfit_test_runner',
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $identity = $pdo->query('SELECT current_database(), current_user, session_user')->fetch(PDO::FETCH_NUM);
    if ($identity !== ['telmanfit_test', 'telmanfit_test_runner', 'telmanfit_test_runner']) {
        throw new RuntimeException('Unexpected connection identity');
    }
    $privileged = $pdo->query('SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname = current_user')->fetchColumn();
    if ($privileged) {
        throw new RuntimeException('Privileged test role');
    }
    echo "PDO connectivity: OK (private Unix socket; telmanfit_test / telmanfit_test_runner / telmanfit_test_runner)\n";
} catch (Throwable $exception) {
    // Не друкуємо exception: у діагностиці можуть бути параметри підключення.
    fwrite(STDERR, "PDO connectivity: FAILED\n");
    exit(1);
}

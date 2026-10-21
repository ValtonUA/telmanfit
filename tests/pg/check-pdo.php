<?php

// Перевірка драйвера, яким користується Laravel, без bootstrap/.env/міграцій.
$port = getenv('PG_TEST_PORT');
$password = getenv('PGPASSWORD');
if (! ctype_digit((string) $port) || ! $password) {
    fwrite(STDERR, "Missing isolated test connection parameters\n");
    exit(2);
}
try {
    $pdo = new PDO(
        "pgsql:host=127.0.0.1;port={$port};dbname=telmanfit_test;connect_timeout=10",
        'telmanfit_test_runner',
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $identity = $pdo->query('SELECT current_database(), current_user, session_user')->fetch(PDO::FETCH_NUM);
    if ($identity !== ['telmanfit_test', 'telmanfit_test_runner', 'telmanfit_test_runner']) {
        throw new RuntimeException('Unexpected connection identity');
    }
    echo "PDO connectivity: OK (telmanfit_test / telmanfit_test_runner)\n";
} catch (Throwable $exception) {
    // Не друкуємо exception: у діагностиці можуть бути параметри підключення.
    fwrite(STDERR, "PDO connectivity: FAILED\n");
    exit(1);
}

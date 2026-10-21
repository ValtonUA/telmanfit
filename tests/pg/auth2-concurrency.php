<?php

use App\Support\Auth\AuthSecretCodec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/auth2-bootstrap.php';

function must(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

// Два реальні незалежні libpq connections; B надсилає SQL асинхронно.
$dsn = 'host='.getenv('DB_HOST').' port=5432 dbname=telmanfit_stage1_test user=telmanfit_test_runner';
$a = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
$b = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
must($a !== false && $b !== false, 'Потрібні два з’єднання.');
foreach ([$a, $b] as $connection) {
    pg_query($connection, "SET statement_timeout='10s'");
    $identity = pg_fetch_assoc(pg_query($connection, "SELECT current_database() AS db, current_user AS usr, current_setting('server_version_num')::int / 10000 AS major"));
    must($identity === ['db' => 'telmanfit_stage1_test', 'usr' => 'telmanfit_test_runner', 'major' => '18'], 'Невірна identity.');
}
$pidB = (int) pg_fetch_result(pg_query($b, 'SELECT pg_backend_pid()'), 0, 0);
must($pidB !== (int) pg_fetch_result(pg_query($a, 'SELECT pg_backend_pid()'), 0, 0), 'З’єднання не незалежні.');

function waitForLock($a, int $pid): void
{
    $deadline = microtime(true) + 5;
    do {
        pg_query($a, 'SELECT pg_stat_clear_snapshot()');
        $waiting = pg_fetch_result(pg_query_params($a, "SELECT coalesce(wait_event_type='Lock',false) FROM pg_stat_activity WHERE pid=$1", [$pid]), 0, 0);
        if ($waiting === 't') {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('B не заблоковано: тест не довів реальну конкуренцію.');
}

function race($a, $b, int $pid, string $name, string $sql, bool $rollbackA = false): void
{
    pg_query($a, 'BEGIN');
    $first = pg_query($a, $sql);
    must($first !== false && pg_affected_rows($first) === 1, $name.': A має змінити один рядок');
    pg_query($b, 'BEGIN');
    must(pg_send_query($b, $sql), 'Не вдалося надіслати B');
    waitForLock($a, $pid);
    pg_query($a, $rollbackA ? 'ROLLBACK' : 'COMMIT');
    $second = pg_get_result($b);
    must($second !== false && pg_result_status($second) !== PGSQL_FATAL_ERROR, $name.': помилка B');
    must(pg_affected_rows($second) === ($rollbackA ? 1 : 0), $name.': неправильна кількість переможців');
    while (pg_get_result($b) !== false) {
    }
    pg_query($b, 'COMMIT');
    echo $name.': PASS; B очікував PG row lock; '.($rollbackA ? 'rollback A дозволив B' : 'рівно один переможець')."\n";
}

// Rollback precheck lock закриває TOCTOU: concurrent INSERT не може пройти між check/drop.
pg_query($a, 'BEGIN');
pg_query($a, 'LOCK TABLE auth_audit_events IN ACCESS EXCLUSIVE MODE');
must(pg_fetch_result(pg_query($a, 'SELECT count(*) FROM auth_audit_events'), 0, 0) === '0', 'Audit має бути порожній');
pg_query($b, 'BEGIN');
pg_send_query($b, "INSERT INTO auth_audit_events(event_type,outcome,request_id) VALUES('rollback.probe','denied','00000000-0000-4000-8000-000000000001')");
waitForLock($a, $pidB);
pg_query($a, 'ROLLBACK');
must(pg_affected_rows(pg_get_result($b)) === 1, 'Concurrent INSERT не відновився після rollback');
while (pg_get_result($b) !== false) {
}
pg_query($b, 'ROLLBACK');
echo 'rollback precheck vs INSERT: PASS; ACCESS EXCLUSIVE lock перевірено
';

DB::table('users')->insert(['id' => 6000000000, 'name' => 'Синтетичний concurrency', 'role' => 'admin']);
$flow = DB::table('auth_flows')->insertGetId(['public_id' => (string) Str::uuid(), 'browser_binding_hash' => str_repeat('a', 64), 'audience' => 'client', 'purpose' => 'login', 'user_id' => 6000000000, 'auth_version_at_start' => 0, 'expires_at' => now()->addHour()]);
$challenge = DB::table('auth_challenges')->insertGetId(['public_id' => (string) Str::uuid(), 'flow_id' => $flow, 'generation' => 1, 'email_normalized' => 'synthetic@example.invalid', 'code_mac' => str_repeat('a', 64), 'key_version' => 'test', 'max_attempts' => 5, 'expires_at' => now()->addHour()]);
$codec = new AuthSecretCodec;
$batch = (string) Str::uuid();
DB::table('user_totp_factors')->insert(['user_id' => 6000000000, 'secret_ciphertext' => $codec->encryptTotp('SYNTHETIC'), 'key_version' => 'test', 'confirmed_at' => DB::raw('clock_timestamp()'), 'recovery_batch_id' => $batch]);
$recovery = DB::table('mfa_recovery_codes')->insertGetId(['user_id' => 6000000000, 'selector' => (string) Str::uuid(), 'code_hash' => $codec->hashRecoveryCode('synthetic'), 'batch_id' => $batch]);

// Споживання та тестовий session grant — один SQL/commit; програвший не видає session.
race($a, $b, $pidB, 'challenge + session grant', "WITH accepted AS (
 UPDATE auth_challenges SET consumed_at=clock_timestamp() WHERE id=$challenge
 AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at>clock_timestamp() AND attempts<max_attempts RETURNING id
) INSERT INTO sessions(id,user_id,payload,last_activity)
 SELECT 'challenge_' || id,6000000000,'prototype',0 FROM accepted");
must(DB::table('sessions')->where('id', 'challenge_'.$challenge)->count() === 1, 'Подвійна session');
race($a, $b, $pidB, 'recovery + session grant', "WITH accepted AS (
 UPDATE mfa_recovery_codes SET used_at=clock_timestamp() WHERE id=$recovery
 AND used_at IS NULL AND revoked_at IS NULL AND batch_id=(SELECT recovery_batch_id FROM user_totp_factors WHERE user_id=6000000000) RETURNING id
) INSERT INTO sessions(id,user_id,payload,last_activity) SELECT 'recovery_' || id,6000000000,'prototype',0 FROM accepted");
race($a, $b, $pidB, 'TOTP cross-context accepted step', 'UPDATE user_totp_factors SET last_used_step=10000000000 WHERE user_id=6000000000 AND (last_used_step IS NULL OR last_used_step<10000000000)');
foreach ([null, now()->addHour()] as $expiry) {
    $invitation = DB::table('client_invitations')->insertGetId(['token_hash' => hash('sha256', random_bytes(32)), 'created_by_user_id' => 6000000000, 'coach_user_id' => 6000000000, 'expires_at' => $expiry]);
    $mode = $expiry === null ? 'indefinite' : 'expiring';
    race($a, $b, $pidB, "invitation $mode consume vs consume", "UPDATE client_invitations SET consumed_at=clock_timestamp(),consumed_by_user_id=6000000000 WHERE id=$invitation AND consumed_at IS NULL AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>clock_timestamp())");
}
race($a, $b, $pidB, 'generation compare-and-swap', "UPDATE auth_flows SET generation=2 WHERE id=$flow AND generation=1 AND state='pending'");

// Revocation перемагає request: B блокується на user, потім бачить нову версію.
pg_query($a, 'BEGIN');
pg_query($a, 'UPDATE users SET auth_version=auth_version+1 WHERE id=6000000000');
pg_query($b, 'BEGIN');
pg_send_query($b, 'SELECT auth_version FROM users WHERE id=6000000000 FOR UPDATE');
waitForLock($a, $pidB);
pg_query($a, 'COMMIT');
$version = pg_fetch_result(pg_get_result($b), 0, 0);
while (pg_get_result($b) !== false) {
}
must($version === '1', 'B не побачив відкликання');
pg_query($b, 'ROLLBACK');
echo "revoke before sensitive request: PASS; застаріла version=0 відхиляється\n";

// Request перемагає revocation: mutation закінчується під user lock до bump.
pg_query($a, 'BEGIN');
pg_query($a, 'SELECT auth_version FROM users WHERE id=6000000000 FOR UPDATE');
pg_query($b, 'BEGIN');
pg_send_query($b, 'UPDATE users SET auth_version=auth_version+1 WHERE id=6000000000 RETURNING auth_version');
waitForLock($a, $pidB);
pg_query($a, "INSERT INTO sessions(id,user_id,payload,last_activity) VALUES('before_revoke',6000000000,'prototype',0)");
pg_query($a, "INSERT INTO auth_session_states(session_id,user_id,audience,browser_binding_hash,auth_version,primary_method,primary_verified_at,absolute_expires_at)
 VALUES('before_revoke',6000000000,'client',repeat('a',64),1,'email_otp',clock_timestamp(),clock_timestamp()+interval '1 hour')");
pg_query($a, 'COMMIT');
must(pg_fetch_result(pg_get_result($b), 0, 0) === '2', 'Revocation не завершилася');
while (pg_get_result($b) !== false) {
}
pg_query($b, 'COMMIT');
must(DB::selectOne("SELECT count(*) AS n FROM auth_session_states s JOIN users u ON u.id=s.user_id WHERE s.session_id='before_revoke' AND s.auth_version=u.auth_version AND s.revoked_at IS NULL AND s.absolute_expires_at>clock_timestamp()")->n === 0, 'Старий grant не відкликано');
echo "sensitive request before revoke: PASS; grant version=1 стає застарілим після bump=2\n";

// Невдалий consume не залишає часткового стану; інша транзакція має шанс.
race($a, $b, $pidB, 'TOTP acceptance rollback', 'UPDATE user_totp_factors SET last_used_step=10000000001 WHERE user_id=6000000000 AND last_used_step<10000000001', true);

// Запрошення revoke vs consume: різні операції, один lock та terminal predicate.
foreach ([null, now()->addHour()] as $expiry) {
    $other = DB::table('client_invitations')->insertGetId(['token_hash' => hash('sha256', random_bytes(32)), 'created_by_user_id' => 6000000000, 'coach_user_id' => 6000000000, 'expires_at' => $expiry]);
    pg_query($a, 'BEGIN');
    pg_query($a, "UPDATE client_invitations SET revoked_at=clock_timestamp(),revoked_by_user_id=6000000000 WHERE id=$other");
    pg_query($b, 'BEGIN');
    pg_send_query($b, "UPDATE client_invitations SET consumed_at=clock_timestamp(),consumed_by_user_id=6000000000 WHERE id=$other AND consumed_at IS NULL AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>clock_timestamp())");
    waitForLock($a, $pidB);
    pg_query($a, 'COMMIT');
    must(pg_affected_rows(pg_get_result($b)) === 0, 'Відкликане запрошення використане');
    while (pg_get_result($b) !== false) {
    }
    pg_query($b, 'COMMIT');
    echo 'invitation '.($expiry === null ? 'indefinite' : 'expiring')." revoke vs consume: PASS\n";

}

// Resend збільшує generation під flow lock; verifier старого challenge перечитує її.
pg_query($a, 'BEGIN');
pg_query($a, "UPDATE auth_flows SET generation=3 WHERE id=$flow");
pg_query($b, 'BEGIN');
pg_send_query($b, "SELECT generation FROM auth_flows WHERE id=$flow FOR UPDATE");
waitForLock($a, $pidB);
pg_query($a, 'COMMIT');
must(pg_fetch_result(pg_get_result($b), 0, 0) === '3', 'Стару generation не відхилено');
while (pg_get_result($b) !== false) {
}
pg_query($b, 'ROLLBACK');
echo 'resend vs old challenge: PASS; generation=1 відхилено після lock
';

// Rotation і consume серіалізуються на одному factor, а не на cache/batch snapshot.
pg_query($a, 'BEGIN');
pg_query($a, "UPDATE user_totp_factors SET recovery_batch_id='00000000-0000-4000-8000-000000000001' WHERE user_id=6000000000");
pg_query($b, 'BEGIN');
pg_send_query($b, 'SELECT recovery_batch_id FROM user_totp_factors WHERE user_id=6000000000 FOR UPDATE');
waitForLock($a, $pidB);
pg_query($a, 'COMMIT');
must(pg_fetch_result(pg_get_result($b), 0, 0) !== $batch, 'Старий recovery batch ще активний');
while (pg_get_result($b) !== false) {
}
pg_query($b, 'ROLLBACK');
echo 'recovery rotation vs consume: PASS; старий batch відхилено
';

pg_close($a);
pg_close($b);
// Увесь контейнер і його tmpfs знищуються runner-ом навіть при помилці.
echo "AUTH-2 concurrency: 14 scenarios PASS; це транзакційний прототип, не production auth service\n";

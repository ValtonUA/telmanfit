<?php

use App\Services\Auth\Notices\NoticeDispatcher;
use App\Services\Auth\Notices\NoticeType;
use App\Services\Auth\Notices\NoticeWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'B4 concurrency failed: '.get_class($error).' line '.$error->getLine()."\n");
    exit(1);
});
if (($argv[1] ?? '') === 'worker') {
    DB::statement("SET application_name='auth6b4_worker'");
    $input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (in_array($input['action'], ['crash-before', 'crash-after'], true)) {
        DB::beginTransaction();
        DB::table('users')->where('id', $input['subject'])->increment('auth_version');
        app(NoticeWriter::class)->write($input['subject'], NoticeType::MfaChanged, ['current' => 'synthetic@example.invalid'], request: $input['request']);
        if ($input['action'] === 'crash-after') {
            DB::commit();
        }
        echo "ready\n";
        fflush(STDOUT);
        fgets(STDIN); // Parent terminate: справжній crash, не application rollback.
        exit;
    }
    if ($input['action'] === 'claim') {
        $rows = app(NoticeDispatcher::class)->claim(50);
        echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() n')->n, 'ids' => array_column($rows, 'id')]);
    } else {
        DB::transaction(fn () => app(NoticeWriter::class)->write($input['subject'], NoticeType::MfaChanged, ['current' => 'synthetic@example.invalid'], request: $input['request']));
        echo 'written';
    }
    exit;
}
function spawnB4(array $input): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input)."\n");
    fclose($pipes[0]);

    return [$process, $pipes];
}
function finishB4(array $worker): string
{
    [$process, $pipes] = $worker;
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '') {
        throw new RuntimeException('Synthetic worker failed');
    }

    return $output;
}
$subject = DB::table('users')->insertGetId(['name' => 'B4 concurrent synthetic', 'role' => 'client']);
$ids = [];
for ($i = 0; $i < 3; $i++) {
    $request = DB::transaction(fn () => app(NoticeWriter::class)->write($subject, NoticeType::MfaChanged, ['current' => 'synthetic@example.invalid']));
    $ids[] = DB::table('auth_security_notice_outbox')->where('request_id', $request)->value('id');
}
// Parent тримає один row lock; два незалежні claimers мусять його пропустити.
DB::beginTransaction();
DB::table('auth_security_notice_outbox')->where('id', $ids[0])->lockForUpdate()->first();
$one = spawnB4(['action' => 'claim']);
$two = spawnB4(['action' => 'claim']);
$a = json_decode(finishB4($one), true);
$b = json_decode(finishB4($two), true);
if ($a['pid'] === $b['pid'] || array_intersect($a['ids'], $b['ids']) !== []
    || in_array($ids[0], array_merge($a['ids'], $b['ids']), true)
    || count(array_intersect(array_merge($a['ids'], $b['ids']), [$ids[1], $ids[2]])) !== 2) {
    throw new RuntimeException('SKIP LOCKED disjoint claim failed');
}
DB::rollBack();
// Повтори одного server request UUID чекають advisory lock і створюють один row.
$request = (string) Str::uuid();
DB::beginTransaction();
DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,64004))', [$request]);
$one = spawnB4(['action' => 'write', 'subject' => $subject, 'request' => $request]);
$two = spawnB4(['action' => 'write', 'subject' => $subject, 'request' => $request]);
$waiting = false;
for ($n = 0; $n < 100; $n++) {
    DB::select('SELECT pg_stat_clear_snapshot()');
    if (DB::selectOne("SELECT count(DISTINCT pid) n FROM pg_stat_activity WHERE application_name='auth6b4_worker' AND wait_event_type='Lock'")->n === 2) {
        $waiting = true;
        break;
    }
    usleep(40000);
}
DB::commit();
if (finishB4($one) !== 'written' || finishB4($two) !== 'written' || ! $waiting
    || DB::table('auth_security_notice_outbox')->where('request_id', $request)->count() !== 1) {
    throw new RuntimeException('Concurrent idempotency failed');
}
echo "B4 concurrency PASS: two independent PIDs, SKIP LOCKED contention, disjoint claims, two observed lock waits, one idempotent intent\n";

foreach (['crash-before', 'crash-after'] as $stage) {
    $request = (string) Str::uuid();
    $before = DB::table('users')->where('id', $subject)->value('auth_version');
    $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['action' => $stage, 'subject' => $subject, 'request' => $request])."\n");
    fflush($pipes[0]);
    stream_set_timeout($pipes[1], 10);
    if (fgets($pipes[1]) !== "ready\n") {
        throw new RuntimeException('Crash barrier failed');
    }
    proc_terminate($process, 15);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $expected = $stage === 'crash-before' ? 0 : 1;
    if (DB::table('auth_security_notice_outbox')->where('request_id', $request)->count() !== $expected
        || DB::table('users')->where('id', $subject)->value('auth_version') !== $before + $expected) {
        throw new RuntimeException('Process crash atomicity failed');
    }
}
echo "B4 process crash PASS: SIGTERM before commit rolls back mutation/notice/audit; after commit before signal preserves durable intent\n";

<?php

use App\Models\User;
use App\Services\Catalog\RecipeCoverWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/auth2-bootstrap.php';
config(['presentation.image_hosts' => ['images.example.com']]);
$writer = app(RecipeCoverWriter::class);
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-cover-worker'");
    try {
        $writer->save(User::findOrFail($data['actor']), $data['recipe'], 'https://images.example.com/second.png', $data['token']);
        exit(2);
    } catch (ValidationException) {
        exit($data['case'] === 'stale' ? 0 : 3);
    } catch (HttpException $e) {
        exit($data['case'] === 'revoked' && $e->getStatusCode() === 403 ? 0 : 4);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic cover concurrency', 'role' => 'admin']));
$recipe = DB::table('recipes')->insertGetId(['title' => 'Synthetic cover concurrency']);
$file = tempnam(sys_get_temp_dir(), 'telmanfit-cover-concurrency-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['stale', 'revoked'] as $case) {
        file_put_contents($file, json_encode(['actor' => $actor->id, 'recipe' => $recipe, 'token' => $writer->token($actor, $recipe), 'case' => $case]));
        DB::beginTransaction();
        if ($case === 'stale') {
            DB::table('recipes')->where('id', $recipe)->lockForUpdate()->first();
        } else {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Cover worker не запущений');
        }
        fclose($pipes[0]);
        $waiting = false;
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-cover-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведено реальне очікування cover lock: '.$case);
        }
        if ($case === 'stale') {
            $writer->save($actor, $recipe, 'https://images.example.com/first.png', $writer->token($actor, $recipe));
        } else {
            DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
        }
        DB::commit();
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($worker);
        $worker = null;
        if ($code !== 0 || DB::table('recipes')->where('id', $recipe)->value('image_url') !== 'https://images.example.com/first.png') {
            throw new RuntimeException('Cover lost update / worker failed '.$case.': '.$err.$out);
        }
    }
    echo "Recipe cover concurrency PASS: independent connections, proven row lock waits, stale CAS and revoked role rejected, no lost update.\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if (is_resource($worker)) {
        proc_terminate($worker);
        proc_close($worker);
    }
    unlink($file);
}

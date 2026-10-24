<?php

use App\Models\User;
use App\Services\Recipes\BatchEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Recipes\BatchFixture;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
config(['recipes.batch_editor_enabled' => true]);
$editor = app(BatchEditor::class);
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-batch-command-worker'");
    try {
        $editor->save(User::findOrFail($data['actor']), $data['id'], $data['state']['revision'], $data['state']['payload'], $data['token'], true);
        exit(2); // Конкурентний stale write не повинен пройти.
    } catch (ValidationException) {
        exit(0);
    }
}
$fixture = new class
{
    use BatchFixture;

    public function make(): array
    {
        return DB::transaction(fn () => $this->batch());
    }
};
$b = $fixture->make();
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic concurrency author', 'role' => 'admin']));
$file = tempnam(sys_get_temp_dir(), 'telmanfit-batch-command-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['source', 'revision'] as $case) {
        $state = $editor->open($actor, $b['version']);
        $preview = $editor->preview($actor, $b['version'], $state['revision'], $state['payload']);
        file_put_contents($file, json_encode(['id' => $b['version'], 'actor' => $actor->id, 'state' => $state, 'token' => $preview['token']]));
        DB::beginTransaction();
        $recipe = DB::table('recipe_versions')->where('id', $b['version'])->value('recipe_id');
        DB::table('recipes')->where('id', $recipe)->lockForUpdate()->first();
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Не запущено worker.');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-batch-command-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведене очікування command lock.');
        }
        if ($case === 'source') {
            DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '124.00']);
        } else {
            $state['payload']['title'] = 'Winner';
            $p = $editor->preview($actor, $b['version'], $state['revision'], $state['payload']);
            $editor->save($actor, $b['version'], $state['revision'], $state['payload'], $p['token'], true);
        }
        DB::commit();
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($worker);
        $worker = null;
        if ($code !== 0) {
            throw new RuntimeException('Stale command не відхилено: '.$stderr);
        }
        $after = $editor->open($actor, $b['version']);
        if (($case === 'source' && $after['revision'] !== $state['revision']) || ($case === 'revision' && $after['payload']['title'] !== 'Winner')) {
            throw new RuntimeException('Конкурентний stale command змінив aggregate.');
        }
    }
} finally {
    while (DB::transactionLevel()) {
        DB::rollBack();
    }
    if (is_resource($worker)) {
        proc_terminate($worker);
        proc_close($worker);
    }
    unlink($file);
}
echo "Batch command concurrency PASS: real recipe lock wait; stale source and stale revision roll back.\n";

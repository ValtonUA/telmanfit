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
        if (($data['command'] ?? '') === 'personal') {
            $editor->createPersonalCopy(User::findOrFail($data['actor']), $data['id'], $data['state']['revision'], $data['owner']);
            exit(2);
        }
        if (($data['command'] ?? '') === 'clone') {
            $editor->createVersion(User::findOrFail($data['actor']), $data['id'], $data['state']['revision']);
            exit(2);
        }
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
    // Дві команди нового draft справді конкурують за один recipe lock.
    $state = $editor->open($actor, $b['version']);
    $category = DB::table('recipe_categories')->insertGetId(['name' => 'Concurrency clone category']);
    $ingredientCategory = DB::table('ingredient_categories')->insertGetId(['name' => 'Concurrency clone ingredient']);
    DB::table('ingredients')->where('id', $b['ingredient'])->update(['ingredient_category_id' => $ingredientCategory]);
    $state['payload']['category_id'] = (string) $category;
    $state['payload']['directions'] = ['Взяти {{ingredient:'.$b['row'].':grams}}'];
    $preview = $editor->preview($actor, $b['version'], $state['revision'], $state['payload']);
    $editor->save($actor, $b['version'], $state['revision'], $state['payload'], $preview['token'], true, true);
    $state = $editor->open($actor, $b['version']);
    $preview = $editor->preview($actor, $b['version'], $state['revision'], $state['payload']);
    $editor->publish($actor, $b['version'], $state['revision'], $preview['token']);
    $state = $editor->open($actor, $b['version']);
    $source = DB::table('recipe_versions')->find($b['version']);
    file_put_contents($file, json_encode(['command' => 'clone', 'id' => $b['version'], 'actor' => $actor->id, 'state' => $state]));
    DB::beginTransaction();
    DB::table('recipes')->where('id', $source->recipe_id)->lockForUpdate()->first();
    $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($worker)) {
        throw new RuntimeException('Не запущено clone worker.');
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
        throw new RuntimeException('Clone worker не чекає справжнього PG lock.');
    }
    $clone = $editor->createVersion($actor, $b['version'], $state['revision']);
    DB::commit();
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($worker);
    $worker = null;
    if ($code !== 0 || DB::table('recipe_versions')->where('recipe_id', $source->recipe_id)->where('status', 'draft')->count() !== 1) {
        throw new RuntimeException('Clone race має залишити рівно один draft: '.$stderr);
    }
    DB::purge(); // Справжній COMMIT/reopen; revision не припускається рівною 1.
    $copy = $editor->open($actor, $clone);
    if ($copy['status'] !== 'draft' || $copy['payload']['rows'][0]['id'] === (string) $b['row']) {
        throw new RuntimeException('Clone COMMIT/reopen/remap failure.');
    }
    $preview = $editor->preview($actor, $clone, $copy['revision'], $copy['payload']);
    $editor->save($actor, $clone, $copy['revision'], $copy['payload'], $preview['token']);
    try {
        $editor->save($actor, $clone, $copy['revision'], $copy['payload'], $preview['token']);
        throw new RuntimeException('Clone stale Save мав бути відхилений.');
    } catch (ValidationException) {
        if ($source != DB::table('recipe_versions')->find($b['version'])) {
            throw new RuntimeException('Clone race/Save змінили published source.');
        }
    }

    $owner = DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
    $other = DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
    foreach (['duplicate', 'source-owner', 'target-delete'] as $case) {
        file_put_contents($file, json_encode(['command' => 'personal', 'id' => $b['version'], 'actor' => $actor->id, 'state' => $state, 'owner' => $case === 'target-delete' ? $other : $owner]));
        DB::beginTransaction();
        if ($case === 'target-delete') {
            DB::table('clients')->where('id', $other)->lockForUpdate()->first();
        } else {
            DB::table('recipes')->where('id', $source->recipe_id)->lockForUpdate()->first();
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Не запущено personal worker.');
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
            throw new RuntimeException('Personal worker не чекає PG lock.');
        }
        if ($case === 'duplicate') {
            $personal = $editor->createPersonalCopy($actor, $b['version'], $state['revision'], $owner);
        } elseif ($case === 'source-owner') {
            DB::table('recipes')->where('id', $source->recipe_id)->update(['owner_client_id' => $other]);
        } else {
            DB::table('clients')->where('id', $other)->delete();
        }
        DB::commit();
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($worker);
        $worker = null;
        if ($code !== 0) {
            throw new RuntimeException('Personal stale/duplicate command не відхилено: '.$stderr);
        }
        if ($case === 'source-owner') {
            DB::table('recipes')->where('id', $source->recipe_id)->update(['owner_client_id' => null]);
        }
    }
    DB::purge();
    $personalState = $editor->open($actor, $personal);
    $card = DB::table('recipes')->find(DB::table('recipe_versions')->find($personal)->recipe_id);
    if ($card->parent_recipe_id !== $source->recipe_id || $card->owner_client_id !== $owner
        || DB::table('recipes')->where('parent_recipe_id', $source->recipe_id)->count() !== 1
        || $personalState['status'] !== 'draft' || $personalState['payload']['rows'][0]['id'] === (string) $b['row']) {
        throw new RuntimeException('Personal COMMIT/reload/owner/remap failure.');
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
echo "Batch command concurrency PASS: real recipe lock wait; stale source/revision rollback; clone race leaves one draft; COMMIT/reopen/remap/CAS preserve source; C2 duplicate/source-owner/target-delete real lock waits reject stale copies.\n";

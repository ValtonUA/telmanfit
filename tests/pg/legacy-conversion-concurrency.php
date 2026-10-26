<?php

use App\Models\User;
use App\Services\Recipes\BatchEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Recipes\LegacyFixture;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
config(['recipes.batch_editor_enabled' => true]);
$editor = app(BatchEditor::class);
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-legacy-conversion-worker'");
    try {
        $editor->convertLegacy(User::findOrFail($data['actor']), $data['id'], $data['revision'], $data['token'], true, true);
        exit(2);
    } catch (ValidationException) {
        exit(0);
    }
}
(require database_path('migrations/2026_10_24_030000_allow_explicit_legacy_conversion.php'))->up();
$fixture = new class
{
    use LegacyFixture;

    public function make(bool $published): array
    {
        return DB::transaction(fn () => $this->legacy($published));
    }
};
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Legacy concurrency synthetic', 'role' => 'admin']));
$file = tempnam(sys_get_temp_dir(), 'telmanfit-legacy-concurrency-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['draft', 'published', 'catalog', 'revision'] as $case) {
        $b = $fixture->make($case === 'published');
        $revision = (string) DB::table('recipe_versions')->where('id', $b['version'])->value('lock_version');
        $p = $editor->previewLegacy($actor, $b['version'], $revision);
        file_put_contents($file, json_encode(['actor' => $actor->id, 'id' => $b['version'], 'revision' => $revision, 'token' => $p['token']]));
        DB::beginTransaction();
        DB::table('recipes')->where('id', $b['recipe'])->lockForUpdate()->first();
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Не запущено worker');
        }
        fclose($pipes[0]);
        $waiting = false;
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-legacy-conversion-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведене очікування legacy lock');
        }
        if ($case === 'catalog') {
            DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '121.00']);
        } elseif ($case === 'revision') {
            DB::table('recipe_directions')->where('recipe_version_id', $b['version'])->update(['image_url' => 'https://example.invalid/race.png']);
        } else {
            $new = $editor->convertLegacy($actor, $b['version'], $revision, $p['token'], true, true);
        }
        DB::commit();
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($worker);
        $worker = null;
        if ($code !== 0) {
            throw new RuntimeException('Stale conversion не відхилено: '.$stderr);
        }
        if (DB::table('recipe_versions')->where('recipe_id', $b['recipe'])->where('status', 'draft')->count() !== 1) {
            throw new RuntimeException('Порушено one draft');
        }
        if (in_array($case, ['draft', 'published'], true)) {
            $state = $editor->open($actor, $new);
            if ($state['payload']['rows'][0]['quantity'] !== '150.00') {
                throw new RuntimeException('Після COMMIT/reload змінилися грами');
            }
        }
    }
    echo "C3 concurrency PASS: real recipe locks, duplicate draft/published conversion, stale catalog/revision, COMMIT/reload.\n";
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

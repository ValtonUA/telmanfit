<?php

use App\Models\Catalog\Ingredient;
use App\Models\Catalog\Uom;
use App\Models\User;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Catalog\CatalogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$writer = app(CatalogWriter::class);
if (($argv[1] ?? '') === 'worker') {
    $d = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-food-catalog-worker'");
    try {
        $model = $d['model'] === 'uom' ? Uom::findOrFail($d['id']) : Ingredient::findOrFail($d['id']);
        $writer->save(User::findOrFail($d['actor']), $model, $d['input'], $d['revision']);
        exit($d['expect'] === 'success' ? 0 : 2);
    } catch (ValidationException) {
        exit($d['expect'] === 'stale' ? 0 : 3);
    }
}
if (! Schema::hasTable('auth_security_runtime')) {
    foreach (glob(database_path('migrations/2026_10_22_*.php')) as $file) {
        (require $file)->up();
    }
    (require database_path('migrations/2026_10_24_000000_revise_client_profiles.php'))->up();
}
DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL,
    'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()'), 'state' => 'ready']);
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic catalog concurrency', 'role' => 'admin']));
$input = ['name' => 'Synthetic concurrency', 'kcal_per_100g' => '100.00', 'protein_per_100g' => '10.00', 'fat_per_100g' => '0.00', 'carbs_per_100g' => '5.00', 'conversions' => []];
$unit = $writer->save($actor, new Uom, ['code' => 'catalog-concurrency', 'kind' => 'count', 'label' => 'Тест']);
$record = $writer->save($actor, new Ingredient, $input);
$file = tempnam(sys_get_temp_dir(), 'telmanfit-catalog-concurrency-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['optimistic', 'conversion_phantom', 'uom_lock_order'] as $case) {
        $record->refresh();
        $d = ['actor' => $actor->id, 'id' => $record->id, 'input' => $input, 'model' => 'ingredient', 'revision' => $writer->revision($record), 'expect' => 'stale'];
        if ($case === 'uom_lock_order') {
            $d = ['actor' => $actor->id, 'id' => $unit->id, 'input' => ['code' => $unit->code, 'kind' => 'count', 'label' => 'Нова назва'], 'model' => 'uom', 'revision' => $writer->revision($unit), 'expect' => 'success'];
        }
        file_put_contents($file, json_encode($d));
        DB::beginTransaction();
        DB::table('ingredients')->where('id', $record->id)->lockForUpdate()->first();
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Worker не запущено');
        }
        fclose($pipes[0]);
        $waiting = false;
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-food-catalog-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведено реальне очікування ingredient lock: '.$case);
        }
        if ($case === 'optimistic') {
            $writer->save($actor, $record, array_replace($input, ['name' => 'Перший автор']), $writer->revision($record));
        } elseif ($case === 'conversion_phantom') {
            DB::table('ingredient_uoms')->insert(['ingredient_id' => $record->id, 'uom_id' => $unit->id, 'grams_per_uom' => '12.00', 'is_default' => true]);
        } else {
            // Parent → conversion → UOM без інверсії із конкурентним UOM writer.
            DB::table('ingredient_uoms')->where('ingredient_id', $record->id)->update(['grams_per_uom' => '13.00']);
            DB::table('uoms')->where('id', $unit->id)->sharedLock()->first();
        }
        DB::commit();
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($worker);
        $worker = null;
        if ($code !== 0) {
            throw new RuntimeException('Catalog worker failed '.$case.': '.$err.$out);
        }
        if ($case === 'optimistic' && $record->fresh()->name !== 'Перший автор') {
            throw new RuntimeException('Lost update');
        }
    }
    echo "Food catalog concurrency PASS: real ingredient locks, CAS rejection, conversion phantom, UOM lock order, COMMIT/reload.\n";
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

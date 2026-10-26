<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Recipes\BatchFixture;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-selector-worker'");
    DB::statement("SET lock_timeout='10s'");
    try {
        DB::transaction(function () use ($data) {
            if (in_array($data['case'], ['insert', 'publication'], true)) {
                DB::table('meal_items')->insert($data['item']);
            } elseif ($data['case'] === 'delete') {
                DB::table('recipe_batch_formats')->where('id', $data['format'])->delete();
            } elseif ($data['case'] === 'downgrade') {
                DB::table('recipe_versions')->where('id', $data['version'])->update(['status' => 'draft', 'lock_version' => DB::raw('lock_version+1')]);
            } else {
                DB::table('recipe_batch_formats')->where('id', $data['format'])->update(['kcal_total' => '0.00']);
            }
        });
        exit(in_array($data['case'], ['insert', 'publication'], true) ? 0 : 2);
    } catch (QueryException $e) {
        exit(in_array($data['case'], ['update', 'delete', 'downgrade'], true) && ($e->errorInfo[0] ?? '') === 'P0001' ? 0 : 3);
    }
}
if (! Schema::hasColumn('meal_items', 'recipe_version_id')) {
    (require database_path('migrations/2026_10_25_010000_add_meal_item_recipe_selectors.php'))->up();
}
$fixture = new class
{
    use BatchFixture;

    public function make(bool $published = true): array
    {
        return DB::transaction(function () use ($published) {
            $b = $this->batch();
            if ($published) {
                $this->publish($b['version']);
            }

            return $b;
        });
    }

    public function publishVersion(int $id): void
    {
        $this->publish($id);
    }
};
$b = $fixture->make();
$publishing = $fixture->make(false);
$plan = DB::table('meal_plans')->insertGetId(['title' => 'Synthetic selectors concurrency']);
$v = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
$d = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $v, 'day_number' => 1]);
$type = DB::table('meal_types')->insertGetId(['code' => 'selector-concurrency', 'title' => 'Synthetic']);
$meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $d, 'meal_type_id' => $type]);
$serving = DB::table('uoms')->where('code', 'serving')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
$item = ['meal_id' => $meal, 'food_id' => DB::table('recipe_versions')->where('id', $b['version'])->value('food_id'),
    'quantity' => '0.50', 'uom_id' => $serving, 'recipe_version_id' => $b['version'],
    'recipe_batch_format_id' => $b['formats']['half_week'], 'recipe_portion_divisor' => 3];
$file = tempnam(sys_get_temp_dir(), 'telmanfit-selectors-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['insert', 'update', 'delete', 'downgrade', 'publication'] as $case) {
        $countBefore = DB::table('meal_items')->where('meal_id', $meal)->count();
        $before = DB::table('recipe_batch_formats')->where('id', $b['formats']['half_week'])->first();
        DB::beginTransaction();
        $workerItem = $item;
        if ($case === 'publication') {
            // Справжня uncommitted publication: food ще не видимий іншій сесії,
            // explicit version lock чекає, перечитує published root і лише тоді measure.
            $fixture->publishVersion($publishing['version']);
            $workerItem = array_replace($item, ['recipe_version_id' => $publishing['version'],
                'recipe_batch_format_id' => $publishing['formats']['half_week'],
                'food_id' => DB::table('recipe_versions')->where('id', $publishing['version'])->value('food_id')]);
        } elseif ($case === 'insert') {
            DB::table('recipe_versions')->where('id', $b['version'])->lockForUpdate()->first();
        } else {
            // INSERT selector бере root SHARE; child writer уже тримає child,
            // але очікує root. Reader не бере child lock, тому інверсії немає.
            DB::table('meal_items')->insert($item);
        }
        file_put_contents($file, json_encode(['case' => $case, 'item' => $workerItem, 'version' => $b['version'], 'format' => $b['formats']['half_week']]));
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Worker не запущено');
        }
        fclose($pipes[0]);
        $waiting = false;
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-selector-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведено lock wait: '.$case);
        }
        DB::commit();
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($worker);
        $worker = null;
        if ($code !== 0 || DB::table('meal_items')->where('meal_id', $meal)->count() !== $countBefore + 1 || $before != DB::table('recipe_batch_formats')->where('id', $b['formats']['half_week'])->first()) {
            throw new RuntimeException('Selector concurrency failed '.$case.': '.$out.$err);
        }
    }
    echo "Meal item selectors concurrency PASS: publication/root-vs-selector and selector-vs-format update/delete/downgrade; real lock waits, frozen data, no deadlock.\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if (is_resource($worker)) {
        proc_terminate($worker, 9);
        proc_close($worker);
    }
    unlink($file);
}

<?php

use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$writer = app(MealItemWriter::class);
$structure = app(MealPlanStructure::class);
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-item-writer-worker'");
    DB::statement("SET lock_timeout='10s'");
    $actor = User::findOrFail($data['actor']);
    $version = MealPlanVersion::findOrFail($data['version']);
    try {
        if ($data['case'] === 'item-before-week') {
            $structure->deleteWeek($actor, $version, $data['snapshot']);
        } else {
            $writer->save($actor, $version, $data['input'], $data['item']);
        }
        exit(2);
    } catch (ValidationException) {
        exit(in_array($data['case'], ['edit', 'double-add', 'item-before-week', 'week-before-item', 'conversion', 'uom'], true) ? 0 : 3);
    } catch (HttpException $e) {
        exit($data['case'] === 'publish' && $e->getStatusCode() === 403 ? 0 : 4);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic item writer race admin', 'role' => 'admin']));
$type = DB::table('meal_types')->insertGetId(['code' => 'writer-concurrency', 'title' => 'Синтетичний прийом writer race']);
[$food, $ingredient] = DB::transaction(function (): array {
    $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
    $ingredient = DB::table('ingredients')->insertGetId(['food_id' => $food, 'name' => 'Synthetic writer race ingredient', 'kcal_per_100g' => '123.45', 'protein_per_100g' => '10.00', 'fat_per_100g' => '1.00', 'carbs_per_100g' => '2.00']);

    return [$food, $ingredient];
});
$unit = DB::table('uoms')->insertGetId(['code' => 'writer-race-piece', 'kind' => 'count', 'label' => 'штука']);
DB::table('ingredient_uoms')->insert(['ingredient_id' => $ingredient, 'uom_id' => $unit, 'grams_per_uom' => '33.33', 'is_default' => true]);
$file = tempnam(sys_get_temp_dir(), 'telmanfit-item-writer-race-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['edit', 'double-add', 'item-before-week', 'week-before-item', 'publish', 'conversion', 'uom'] as $case) {
        $version = app(MealPlanFoundation::class)->create($actor, ['title' => 'Synthetic item race '.$case])->versions()->sole();
        $structure->generate($actor, $version, ['start_day' => 1, 'meal_type_ids' => [$type]]);
        $meal = $version->days()->orderBy('id')->first()->meals()->sole()->id;
        $input = ['meal_id' => $meal, 'food_id' => $food, 'quantity' => '0.50', 'uom_id' => $unit, 'sort_order' => 0,
            'expected' => $writer->mealToken($actor, $version, $meal), 'catalog_expected' => $writer->catalog($actor, $version, $food)['token']];
        $item = $case === 'edit' ? $writer->save($actor, $version, $input) : null;
        if ($item) {
            $input['expected'] = MealItemWriter::token($item);
        }
        $snapshot = $structure->weekDeletionSnapshots($actor, $version)[0];
        $data = ['case' => $case, 'actor' => $actor->id, 'version' => $version->id, 'input' => $input, 'item' => $item?->id, 'snapshot' => $snapshot];
        file_put_contents($file, json_encode($data));
        DB::beginTransaction();
        if (in_array($case, ['double-add', 'item-before-week', 'edit'], true)) {
            $writer->save($actor, $version, array_replace($input, ['quantity' => '1.25']), $item?->id);
        } elseif ($case === 'week-before-item') {
            $structure->deleteWeek($actor, $version, $snapshot);
        } elseif ($case === 'publish') {
            foreach ($version->days()->with('meals')->get() as $day) {
                $target = $day->meals->sole()->id;
                $writer->save($actor, $version, array_replace($input, ['meal_id' => $target, 'expected' => $writer->mealToken($actor, $version, $target)]));
            }
            DB::table('meal_plan_versions')->where('id', $version->id)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        } elseif ($case === 'conversion') {
            DB::table('ingredient_uoms')->where('ingredient_id', $ingredient)->where('uom_id', $unit)->update(['grams_per_uom' => '40.00']);
        } else {
            DB::table('ingredients')->where('id', $ingredient)->lockForUpdate()->first();
            DB::table('uoms')->where('id', $unit)->update(['label' => 'змінена штука']);
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Worker не запущено');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-item-writer-worker' AND wait_event_type='Lock') waiting")->waiting;
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
        if ($code !== 0) {
            throw new RuntimeException('Writer race '.$case.' failed '.$code.': '.$out.$err);
        }
        $ids = DB::table('meals')->whereIn('meal_plan_day_id', $version->days()->select('id'))->pluck('id');
        $count = DB::table('meal_items')->whereIn('meal_id', $ids)->count();
        $expected = match ($case) {
            'edit', 'double-add', 'item-before-week' => 1, 'publish' => 7, default => 0
        };
        if ($count !== $expected) {
            throw new RuntimeException('Partial/lost writer result: '.$case);
        }
    }
    echo "Meal item writer concurrency PASS: concurrent edit/double add, item-vs-week deletion both orders, write-vs-publish, conversion/UOM changes; independent sessions, real lock waits, no partial writes/deadlocks.\n";
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

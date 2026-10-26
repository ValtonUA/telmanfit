<?php

use App\Models\MealItem;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
use App\Services\MealPlans\MealPlanWeekRepeater;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$repeater = app(MealPlanWeekRepeater::class);
$structure = app(MealPlanStructure::class);
if (($argv[1] ?? '') === 'worker') {
    $d = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-repeat-worker'");
    DB::statement("SET lock_timeout='10s'");
    $actor = User::findOrFail($d['actor']);
    $version = MealPlanVersion::findOrFail($d['version']);
    try {
        if ($d['case'] === 'repeat-before-raw-insert') {
            DB::transaction(function () use ($d): void {
                DB::table('meal_items')->insert(['meal_id' => $d['meal'], 'food_id' => $d['food'], 'quantity' => '2.25', 'uom_id' => $d['g'], 'resolved_quantity_grams' => '2.25']);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } elseif ($d['case'] === 'repeat-before-edit') {
            app(MealItemWriter::class)->delete($actor, $version, $d['item'], $d['item_token']);
        } elseif ($d['case'] === 'repeat-before-delete') {
            $structure->deleteWeek($actor, $version, $d['week']);
        } else {
            $repeater->repeat($actor, $version, $d['input']);
        }
        exit(2);
    } catch (QueryException $e) {
        exit($d['case'] === 'repeat-before-raw-insert' && $e->getCode() === '23503' ? 0 : 5);
    } catch (ValidationException) {
        exit(in_array($d['case'], ['double-repeat', 'repeat-before-edit', 'repeat-before-delete', 'edit-before-repeat', 'append-before-repeat', 'delete-before-repeat', 'raw-insert-before-repeat'], true) ? 0 : 3);
    } catch (HttpException $e) {
        exit(in_array($d['case'], ['publish', 'role', 'fence'], true) && $e->getStatusCode() === 403 ? 0 : 4);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic repeat race admin', 'role' => 'admin']));
$type = DB::table('meal_types')->insertGetId(['code' => 'repeat-race', 'title' => 'Синтетичний прийом повторення']);
$food = DB::transaction(function (): int {
    $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
    DB::table('ingredients')->insert(['food_id' => $food, 'name' => 'Synthetic repeat race ingredient', 'kcal_per_100g' => '100.00', 'protein_per_100g' => '10.00', 'fat_per_100g' => '1.00', 'carbs_per_100g' => '2.00']);

    return $food;
});
$g = DB::table('uoms')->where('code', 'g')->value('id');
$file = tempnam(sys_get_temp_dir(), 'telmanfit-repeat-race-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['double-repeat', 'repeat-before-edit', 'repeat-before-delete', 'repeat-before-raw-insert', 'edit-before-repeat', 'append-before-repeat', 'delete-before-repeat', 'raw-insert-before-repeat', 'publish', 'role', 'fence'] as $case) {
        $version = app(MealPlanFoundation::class)->create($actor, ['title' => 'Synthetic repeat race '.$case])->versions()->sole();
        foreach ([1, 8] as $start) {
            $structure->generate($actor, $version, ['start_day' => $start, 'meal_type_ids' => [$type]]);
        }
        DB::transaction(function () use ($version, $food, $g): void {
            foreach ($version->days()->with('meals')->get() as $day) {
                DB::table('meal_items')->insert(['meal_id' => $day->meals->sole()->id, 'food_id' => $food, 'quantity' => '1.25', 'uom_id' => $g, 'resolved_quantity_grams' => '1.25']);
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $input = ['source_week' => 1, 'total_weeks' => 4, 'expected' => $repeater->snapshot($actor, $version)['expected']];
        $input['confirmation'] = $repeater->preview($actor, $version, $input)['confirmation'];
        $week = $structure->weekDeletionSnapshots($actor, $version)[1];
        $week += $structure->confirmWeekDeletion($actor, $version, $week);
        $meal = $version->days()->where('day_number', 8)->sole()->meals()->sole()->id;
        $item = MealItem::where('meal_id', $meal)->sole();
        file_put_contents($file, json_encode(['case' => $case, 'actor' => $actor->id, 'version' => $version->id, 'input' => $input, 'week' => $week, 'item' => $item->id, 'item_token' => MealItemWriter::token($item), 'meal' => $meal, 'food' => $food, 'g' => $g]));
        DB::beginTransaction();
        if (in_array($case, ['double-repeat', 'repeat-before-edit', 'repeat-before-delete', 'repeat-before-raw-insert'], true)) {
            $repeater->repeat($actor, $version, $input);
        } elseif ($case === 'edit-before-repeat') {
            app(MealItemWriter::class)->delete($actor, $version, $item->id, MealItemWriter::token($item));
        } elseif ($case === 'append-before-repeat') {
            $structure->generate($actor, $version, ['start_day' => 15, 'meal_type_ids' => [$type]]);
        } elseif ($case === 'delete-before-repeat') {
            $structure->deleteWeek($actor, $version, $week);
        } elseif ($case === 'raw-insert-before-repeat') {
            DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $food, 'quantity' => '2.25', 'uom_id' => $g, 'resolved_quantity_grams' => '2.25']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } elseif ($case === 'publish') {
            DB::table('meal_plan_versions')->where('id', $version->id)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        } elseif ($case === 'role') {
            DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
        } else {
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Не запущено independent repeat worker');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT FROM pg_stat_activity WHERE application_name='telmanfit-repeat-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведено PG lock wait: '.$case);
        }
        DB::commit();
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($worker);
        $worker = null;
        if ($status !== 0) {
            throw new RuntimeException($case.' worker '.$status.': '.$error);
        }
        $expected = in_array($case, ['double-repeat', 'repeat-before-edit', 'repeat-before-delete', 'repeat-before-raw-insert'], true) ? 28 : ($case === 'append-before-repeat' ? 21 : ($case === 'delete-before-repeat' ? 7 : 14));
        if ($version->days()->count() !== $expected) {
            throw new RuntimeException('Неочікувана структура: '.$case);
        }
        if ($case === 'raw-insert-before-repeat' && DB::table('meal_items')->where('meal_id', $meal)->count() !== 2) {
            throw new RuntimeException('Втрачено конкурентну позицію');
        }
        DB::table('users')->where('id', $actor->id)->update(['role' => 'admin']);
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
        echo 'Repeat concurrency '.$case." PASS (proven lock wait)\n";
    }
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

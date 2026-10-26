<?php

use App\Models\MealItem;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanPublisher;
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
$publisher = app(MealPlanPublisher::class);
if (($argv[1] ?? '') === 'worker') {
    $d = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-publication-worker'");
    DB::statement("SET lock_timeout='10s'");
    try {
        if ($d['case'] === 'publish-before-raw-edit') {
            DB::table('meal_items')->where('id', $d['item'])->update(['quantity' => '2', 'resolved_quantity_grams' => '2']);
        } elseif ($d['case'] === 'publish-before-writer') {
            app(MealItemWriter::class)->delete(User::findOrFail($d['actor']), MealPlanVersion::findOrFail($d['version']), $d['item'], $d['item_token']);
        } elseif ($d['case'] === 'publish-before-repeat') {
            app(MealPlanWeekRepeater::class)->repeat(User::findOrFail($d['actor']), MealPlanVersion::findOrFail($d['version']), $d['repeat']);
        } elseif ($d['case'] === 'publish-before-week-delete') {
            app(MealPlanStructure::class)->deleteWeek(User::findOrFail($d['actor']), MealPlanVersion::findOrFail($d['version']), $d['week']);
        } else {
            $publisher->publish(User::findOrFail($d['actor']), MealPlanVersion::findOrFail($d['version']), $d['token']);
        }
        exit(2);
    } catch (HttpException $e) {
        exit($e->getStatusCode() === 403 ? 0 : 3);
    } catch (ValidationException) {
        exit(in_array($d['case'], ['edit-before-publish', 'aba-before-publish', 'repeat-before-publish', 'delete-before-publish', 'append-before-publish', 'conversion-before-publish'], true) ? 0 : 4);
    } catch (QueryException) {
        exit($d['case'] === 'publish-before-raw-edit' ? 0 : 5);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic publication admin', 'role' => 'admin']));
$type = DB::table('meal_types')->insertGetId(['code' => 'publication-race', 'title' => 'Синтетичний прийом']);
$food = DB::transaction(function (): int {
    $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
    DB::table('ingredients')->insert(['food_id' => $food, 'name' => 'Synthetic publication ingredient', 'kcal_per_100g' => '100', 'protein_per_100g' => '10', 'fat_per_100g' => '1', 'carbs_per_100g' => '2']);

    return $food;
});
$ingredient = DB::table('ingredients')->where('food_id', $food)->value('id');
$unit = DB::table('uoms')->insertGetId(['code' => 'publication-test', 'kind' => 'count', 'label' => 'synthetic']);
DB::table('ingredient_uoms')->insert(['ingredient_id' => $ingredient, 'uom_id' => $unit, 'grams_per_uom' => '1']);
$file = tempnam(sys_get_temp_dir(), 'telmanfit-publication-race-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['double-publish', 'publish-before-raw-edit', 'edit-before-publish', 'aba-before-publish', 'repeat-before-publish', 'delete-before-publish', 'publish-before-writer', 'publish-before-repeat', 'publish-before-week-delete', 'append-before-publish', 'conversion-before-publish', 'role', 'fence'] as $case) {
        $version = app(MealPlanFoundation::class)->create($actor, ['title' => 'Synthetic publication '.$case])->versions()->sole();
        app(MealPlanStructure::class)->generate($actor, $version, ['start_day' => 1, 'meal_type_ids' => [$type]]);
        DB::transaction(function () use ($version, $food, $unit): void {
            foreach ($version->days()->with('meals')->get() as $day) {
                DB::table('meal_items')->insert(['meal_id' => $day->meals->sole()->id, 'food_id' => $food, 'quantity' => '1', 'uom_id' => $unit, 'resolved_quantity_grams' => '1']);
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $item = DB::table('meal_items')->whereIn('meal_id', DB::table('meals')->whereIn('meal_plan_day_id', $version->days()->select('id'))->select('id'))->first();
        $token = $publisher->preview($actor, $version);
        $repeat = ['source_week' => 1, 'total_weeks' => 2, 'expected' => app(MealPlanWeekRepeater::class)->snapshot($actor, $version)['expected']];
        $week = app(MealPlanStructure::class)->weekDeletionSnapshots($actor, $version)[0];
        $week += app(MealPlanStructure::class)->confirmWeekDeletion($actor, $version, $week);
        file_put_contents($file, json_encode(['case' => $case, 'actor' => $actor->id, 'version' => $version->id, 'token' => $token, 'item' => $item->id, 'item_token' => MealItemWriter::token(MealItem::findOrFail($item->id)), 'repeat' => $repeat, 'week' => $week]));
        DB::beginTransaction();
        if (in_array($case, ['double-publish', 'publish-before-raw-edit', 'publish-before-writer', 'publish-before-repeat', 'publish-before-week-delete'], true)) {
            $publisher->publish($actor, $version, $token);
        } elseif ($case === 'edit-before-publish') {
            DB::table('meal_items')->where('id', $item->id)->update(['quantity' => '2', 'resolved_quantity_grams' => '2']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } elseif ($case === 'aba-before-publish') {
            DB::table('meal_items')->where('id', $item->id)->update(['quantity' => '2', 'resolved_quantity_grams' => '2']);
            DB::table('meal_items')->where('id', $item->id)->update(['quantity' => '1', 'resolved_quantity_grams' => '1']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } elseif ($case === 'repeat-before-publish') {
            app(MealPlanWeekRepeater::class)->repeat($actor, $version, $repeat);
        } elseif ($case === 'delete-before-publish') {
            app(MealPlanStructure::class)->deleteWeek($actor, $version, $week);
        } elseif ($case === 'append-before-publish') {
            app(MealPlanStructure::class)->generate($actor, $version, ['start_day' => 8, 'meal_type_ids' => [$type]]);
        } elseif ($case === 'conversion-before-publish') {
            DB::table('ingredient_uoms')->where('ingredient_id', $ingredient)->where('uom_id', $unit)->update(['grams_per_uom' => '2']);
        } elseif ($case === 'role') {
            DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
        } else {
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Не запущено independent publication worker');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT FROM pg_stat_activity WHERE application_name='telmanfit-publication-worker' AND wait_event_type='Lock') waiting")->waiting;
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
        $expected = in_array($case, ['double-publish', 'publish-before-raw-edit', 'publish-before-writer', 'publish-before-repeat', 'publish-before-week-delete'], true) ? 'published' : 'draft';
        if ($version->fresh()->status !== $expected) {
            throw new RuntimeException('Неочікуваний статус: '.$case);
        }
        DB::table('users')->where('id', $actor->id)->update(['role' => 'admin']);
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
        DB::table('ingredient_uoms')->where('ingredient_id', $ingredient)->where('uom_id', $unit)->update(['grams_per_uom' => '1']);
        echo 'Publication concurrency '.$case." PASS (proven lock wait)\n";
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

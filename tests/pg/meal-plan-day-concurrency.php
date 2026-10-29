<?php

use App\Models\MealItem;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanStructure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/auth2-bootstrap.php';
require __DIR__.'/legacy-meal-plan-fixture.php';
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$writer = app(MealItemWriter::class);
$structure = app(MealPlanStructure::class);
if (($argv[1] ?? '') === 'worker') {
    $d = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-day-editor-worker'");
    DB::statement("SET lock_timeout='10s'");
    $actor = User::findOrFail($d['actor']);
    $version = MealPlanVersion::findOrFail($d['version']);
    try {
        if ($d['case'] === 'place-before-delete') {
            $writer->delete($actor, $version, $d['item'], $d['item_token']);
        } else {
            $writer->place($actor, $version, $d['day'], $d['item'], $d['target'], null, $d['token']);
        }
        exit(2);
    } catch (ValidationException) {
        exit(in_array($d['case'], ['double-place', 'delete-before-place', 'place-before-delete', 'week-before-place', 'aba-place'], true) ? 0 : 3);
    } catch (HttpException|AuthorizationException $e) {
        exit(in_array($d['case'], ['publish', 'role', 'fence'], true) ? 0 : 4);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic day race admin', 'role' => 'admin']));
$type = DB::table('meal_types')->insertGetId(['code' => 'day-race-source', 'title' => 'Синтетичне джерело']);
$targetType = DB::table('meal_types')->insertGetId(['code' => 'day-race-target', 'title' => 'Синтетична ціль']);
$food = DB::transaction(function (): int {
    $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
    DB::table('ingredients')->insert(['food_id' => $food, 'name' => 'Synthetic day race food', 'kcal_per_100g' => '100.00', 'protein_per_100g' => '10.00', 'fat_per_100g' => '1.00', 'carbs_per_100g' => '2.00']);

    return $food;
});
$g = DB::table('uoms')->where('code', 'g')->value('id');
$file = tempnam(sys_get_temp_dir(), 'telmanfit-day-race-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['double-place', 'delete-before-place', 'place-before-delete', 'week-before-place', 'aba-place', 'publish', 'role', 'fence'] as $case) {
        $version = syntheticLegacyEmptyMealPlan('Synthetic day race '.$case)->versions()->sole();
        $structure->generate($actor, $version, ['start_day' => 1, 'meal_type_ids' => [$type, $targetType]]);
        $day = $version->days()->orderBy('id')->first();
        $meal = $day->meals()->where('meal_type_id', $type)->sole()->id;
        $target = $day->meals()->where('meal_type_id', $targetType)->sole()->id;
        $add = function (int $mealId) use ($writer, $actor, $version, $food, $g): MealItem {
            return $writer->save($actor, $version, ['meal_id' => $mealId, 'food_id' => $food, 'quantity' => '1.00', 'uom_id' => $g, 'sort_order' => 0,
                'expected' => $writer->mealToken($actor, $version, $mealId), 'catalog_expected' => $writer->catalog($actor, $version, $food)['token']]);
        };
        $item = $add($meal);
        if ($case === 'publish') {
            foreach ($version->days()->with('meals')->get()->flatMap->meals as $m) {
                if ($m->id !== $meal) {
                    $add($m->id);
                }
            }
        }
        file_put_contents($file, json_encode(['case' => $case, 'actor' => $actor->id, 'version' => $version->id, 'day' => $day->id,
            'item' => $item->id, 'target' => $target, 'token' => $writer->dayToken($actor, $version, $day->id), 'item_token' => MealItemWriter::token($item)]));
        DB::beginTransaction();
        if (in_array($case, ['double-place', 'place-before-delete', 'aba-place'], true)) {
            $writer->place($actor, $version, $day->id, $item->id, $target, null, $writer->dayToken($actor, $version, $day->id));
            if ($case === 'aba-place') {
                $writer->place($actor, $version, $day->id, $item->id, $meal, null, $writer->dayToken($actor, $version, $day->id));
            }
        } elseif ($case === 'delete-before-place') {
            $writer->delete($actor, $version, $item->id, MealItemWriter::token($item));
        } elseif ($case === 'week-before-place') {
            $snapshot = $structure->weekDeletionSnapshots($actor, $version)[0];
            $structure->deleteWeek($actor, $version, $snapshot + $structure->confirmWeekDeletion($actor, $version, $snapshot));
        } elseif ($case === 'publish') {
            DB::table('meal_plan_versions')->where('id', $version->id)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        } elseif ($case === 'role') {
            DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
        } else {
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Не запущено independent day worker');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT FROM pg_stat_activity WHERE application_name='telmanfit-day-editor-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведено day lock wait: '.$case);
        }
        DB::commit();
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($worker);
        $worker = null;
        if ($code !== 0) {
            throw new RuntimeException('Day race '.$case.' failed '.$code.': '.$out);
        }
        $actual = $item->fresh();
        $expectedMeal = in_array($case, ['double-place', 'place-before-delete'], true) ? $target : $meal;
        if (in_array($case, ['delete-before-place', 'week-before-place'], true) ? $actual !== null : $actual?->meal_id !== $expectedMeal) {
            throw new RuntimeException('Lost/partial day race: '.$case);
        }
        if ($case === 'role') {
            DB::table('users')->where('id', $actor->id)->update(['role' => 'admin']);
        } elseif ($case === 'fence') {
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
        }
    }
    echo "Day editor concurrency PASS: double drop, move/delete both orders, week deletion, ABA revision, publish, role and recovery fence; independent sessions and proven lock waits.\n";
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

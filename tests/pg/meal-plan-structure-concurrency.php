<?php

use App\Models\Meal;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealPlanStructure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/auth2-bootstrap.php';
require __DIR__.'/legacy-meal-plan-fixture.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$writer = app(MealPlanStructure::class);
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-structure-worker'");
    DB::statement("SET lock_timeout='10s'");
    $actor = User::findOrFail($data['actor']);
    $version = MealPlanVersion::findOrFail($data['version']);
    try {
        if ($data['case'] === 'stale') {
            $writer->saveMeal($actor, $version, $data['edit'], $data['meal']);
        } else {
            $writer->generate($actor, $version, $data['generate']);
        }
        exit(in_array($data['case'], ['revoke', 'fence', 'stale'], true) ? 2 : 0);
    } catch (ValidationException) {
        exit($data['case'] === 'stale' ? 0 : 3);
    } catch (HttpException $e) {
        exit(in_array($data['case'], ['revoke', 'fence'], true) && $e->getStatusCode() === 403 ? 0 : 4);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic structure concurrency', 'role' => 'admin']));
$type = DB::table('meal_types')->insertGetId(['code' => 'structure-concurrency', 'title' => 'Синтетичний тип']);
$file = tempnam(sys_get_temp_dir(), 'telmanfit-structure-concurrency-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['double', 'retry', 'edit-generate', 'stale', 'fence', 'revoke'] as $case) {
        $version = syntheticLegacyEmptyMealPlan('Конкурентна структура '.$case)->versions()->sole();
        $generate = ['start_day' => 1, 'meal_type_ids' => [$type]];
        if ($case !== 'double') {
            $writer->generate($actor, $version, $generate);
        }
        $meal = Meal::whereHas('day', fn ($q) => $q->where('meal_plan_version_id', $version->id))->first();
        $edit = $meal ? ['day_id' => $meal->meal_plan_day_id, 'meal_type_id' => $type, 'title' => 'Паралельна правка', 'sort_order' => 1, 'expected' => MealPlanStructure::token($meal)] : [];
        $data = ['case' => $case, 'actor' => $actor->id, 'version' => $version->id, 'generate' => $generate, 'edit' => $edit, 'meal' => $meal?->id];
        if ($case === 'edit-generate') {
            $data['generate']['start_day'] = 8;
        }
        file_put_contents($file, json_encode($data));
        DB::beginTransaction();
        if ($case === 'double' || $case === 'retry') {
            $writer->generate($actor, $version, $generate);
        } elseif ($case === 'edit-generate' || $case === 'stale') {
            $writer->saveMeal($actor, $version, array_replace($edit, ['title' => 'Збережена правка']), $meal->id);
        } elseif ($case === 'fence') {
            DB::table('auth_security_runtime')->where('id', 1)->lockForUpdate()->first();
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        } else {
            DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Worker не запущено');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-structure-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведено lock wait: '.$case);
        }
        if ($case === 'fence') {
            // Restore бере runtime, потім users. Writer не має тримати actor під час очікування runtime.
            DB::table('users')->where('id', $actor->id)->update(['auth_version' => DB::raw('auth_version+1')]);
        }
        DB::commit();
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($worker);
        $worker = null;
        if ($code !== 0) {
            throw new RuntimeException('Worker '.$case.' failed '.$code.': '.$out.$err);
        }
        if ($case === 'fence') {
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
        }
        $days = $case === 'edit-generate' ? 14 : 7;
        if ($version->days()->count() !== $days || Meal::whereHas('day', fn ($q) => $q->where('meal_plan_version_id', $version->id))->count() !== $days) {
            throw new RuntimeException('Partial/duplicate structure: '.$case);
        }
        if (($case === 'edit-generate' || $case === 'stale') && $meal->fresh()->title !== 'Збережена правка') {
            throw new RuntimeException('Lost edit: '.$case);
        }
    }
    echo "Meal plan structure concurrency PASS: double/retry/edit-generate/stale/recovery fence/revoked role; independent PG sessions and proven lock waits, no partial/duplicate/lost writes.\n";
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

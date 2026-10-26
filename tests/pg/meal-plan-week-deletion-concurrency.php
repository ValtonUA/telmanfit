<?php

use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\MealPlans\PlanFixture;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$writer = app(MealPlanStructure::class);
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-week-delete-worker'");
    DB::statement("SET lock_timeout='10s'");
    $actor = User::findOrFail($data['actor']);
    $version = MealPlanVersion::findOrFail($data['version']);
    try {
        match ($data['case']) {
            'delete-first' => $writer->generate($actor, $version, ['start_day' => 22, 'meal_type_ids' => [$data['type']]]),
            'delete-before-add' => DB::table('meal_items')->insert($data['item']),
            default => $writer->deleteWeek($actor, $version, $data['snapshot']),
        };
        exit($data['case'] === 'append-first' ? 0 : 2);
    } catch (ValidationException) {
        exit(in_array($data['case'], ['double', 'filled-replay', 'add-first', 'edit-first', 'delete-first'], true) ? 0 : 3);
    } catch (QueryException $e) {
        exit($data['case'] === 'delete-before-add' && ($e->errorInfo[0] ?? '') === '23503' ? 0 : 4);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic week race admin', 'role' => 'admin']));
$type = DB::table('meal_types')->insertGetId(['code' => 'synthetic-week-race', 'title' => 'Синтетичний прийом race']);
$fixture = new class
{
    use PlanFixture;

    public function make(int $actor): array
    {
        return $this->populatedPlan($actor);
    }
};
$f = DB::transaction(fn () => $fixture->make($actor->id));
$file = tempnam(sys_get_temp_dir(), 'telmanfit-week-delete-race-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['double', 'filled-replay', 'append-first', 'delete-first', 'add-first', 'delete-before-add', 'edit-first'] as $case) {
        $version = app(MealPlanFoundation::class)->create($actor, ['title' => 'Видалення '.$case])->versions()->sole();
        foreach ([1, 8, 15] as $start) {
            $writer->generate($actor, $version, ['start_day' => $start, 'meal_type_ids' => [$type]]);
        }
        $snapshot = $writer->weekDeletionSnapshots($actor, $version)[1];
        $mealId = DB::table('meals')->where('meal_plan_day_id', $snapshot['anchor'])->value('id');
        $item = ['meal_id' => $mealId, 'food_id' => $f['food'], 'quantity' => '1.00', 'uom_id' => $f['g'], 'resolved_quantity_grams' => '1.00'];
        if ($case === 'filled-replay') {
            DB::table('meal_items')->insert($item);
            $snapshot = $writer->weekDeletionSnapshots($actor, $version)[1];
            $snapshot += $writer->confirmWeekDeletion($actor, $version, $snapshot);
        }
        file_put_contents($file, json_encode(['case' => $case, 'actor' => $actor->id, 'version' => $version->id, 'type' => $type, 'snapshot' => $snapshot, 'item' => $item]));
        $keptDays = $version->days()->whereNotIn('id', $snapshot['day_ids'])->pluck('id')->all();
        DB::beginTransaction();
        if ($case === 'append-first') {
            $writer->generate($actor, $version, ['start_day' => 22, 'meal_type_ids' => [$type]]);
            $keptDays = $version->days()->whereNotIn('id', $snapshot['day_ids'])->pluck('id')->all();
        } elseif ($case === 'add-first') {
            // Прямий INSERT: FK key-share на meal, без штучного version lock.
            DB::table('meal_items')->insert($item);
        } elseif ($case === 'edit-first') {
            DB::table('meals')->where('id', $mealId)->update(['title' => 'Паралельна правка']);
        } else {
            $writer->deleteWeek($actor, $version, $snapshot);
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Worker не запущено');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-week-delete-worker' AND wait_event_type='Lock') waiting")->waiting;
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
            throw new RuntimeException('Worker '.$case.' failed '.$code.': '.$out.$err);
        }
        $expected = in_array($case, ['append-first', 'add-first', 'edit-first'], true) ? 21 : 14;
        if ($version->days()->orderBy('day_number')->pluck('day_number')->all() !== range(1, $expected)
            || $version->days()->whereIn('id', $keptDays)->count() !== count($keptDays)) {
            throw new RuntimeException('Порушено contiguous numbering / стабільні ID: '.$case);
        }
        if ($case === 'add-first' && ! DB::table('meal_items')->where('meal_id', $mealId)->exists()) {
            throw new RuntimeException('Тихо видалено нову позицію');
        }
    }
    echo "Week deletion concurrency PASS: double/filled replay/delete-vs-append both orders/delete-vs-raw-item INSERT both orders/stale edit; independent PG sessions, proven locks, stable IDs, no adjacent deletion or deadlocks.\n";
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

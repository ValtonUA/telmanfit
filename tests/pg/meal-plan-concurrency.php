<?php

use App\Models\User;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\MealPlans\MealPlanFoundation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\MealPlans\PlanFixture;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-plan-worker'");
    DB::statement("SET lock_timeout='10s'");
    try {
        DB::transaction(function () use ($data) {
            match ($data['case']) {
                'draft' => DB::table('meal_plan_versions')->insert(['meal_plan_id' => $data['plan']]),
                'immutable' => DB::table('meal_items')->where('id', $data['item'])->update(['quantity' => '1.00']),
                'overlap' => DB::table('client_meal_plan_assignments')->insert(['client_id' => $data['client'], 'meal_plan_version_id' => $data['version'], 'start_date' => '2026-03-01']),
                'create' => app(MealPlanFoundation::class)->create(User::findOrFail($data['actor']), ['request_key' => (string) Str::uuid(), 'meal_type_ids' => [$data['type']], 'title' => 'Паралельний раціон']),
            };
        });
        exit($data['case'] === 'create' ? 0 : 2);
    } catch (QueryException $e) {
        $expected = ['draft' => '23505', 'immutable' => 'P0001', 'overlap' => '23P01'];
        exit(($expected[$data['case']] ?? null) === ($e->errorInfo[0] ?? '') ? 0 : 3);
    }
}
// Runner уже застосував auth schema; цей fixture окремий від PHPUnit rollback.
if (! Schema::hasTable('auth_security_runtime')) {
    foreach (glob(database_path('migrations/2026_10_22_*.php')) as $file) {
        (require $file)->up();
    }
    (require database_path('migrations/2026_10_24_000000_revise_client_profiles.php'))->up();
}
DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL,
    'last_reconciled_at' => now(), 'issuance_not_before' => now(), 'state' => 'ready']);
(require database_path('migrations/2026_10_25_020000_add_plan_presentation.php'))->up();
(require database_path('migrations/2026_10_29_000000_create_meal_plan_creation_requests.php'))->up();
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic plan concurrency', 'role' => 'admin']));
$fixture = new class
{
    use PlanFixture;

    public function make(int $actor): array
    {
        return $this->populatedPlan($actor);
    }
};
$f = DB::transaction(fn () => $fixture->make($actor->id));
$plan = DB::table('meal_plans')->insertGetId(['title' => 'Конкурентна чернетка']);
$file = tempnam(sys_get_temp_dir(), 'telmanfit-plan-concurrency-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['draft', 'immutable', 'overlap', 'create'] as $case) {
        $data = $f + ['case' => $case, 'actor' => $actor->id];
        if ($case === 'draft') {
            $data['plan'] = $plan;
        }
        file_put_contents($file, json_encode($data));
        DB::beginTransaction();
        if ($case === 'draft') {
            DB::table('meal_plan_versions')->insert(['meal_plan_id' => $plan]);
        } elseif ($case === 'immutable') {
            DB::table('meal_plan_versions')->where('id', $f['version'])->lockForUpdate()->first();
        } elseif ($case === 'overlap') {
            DB::table('client_meal_plan_assignments')->insert(['client_id' => $f['client'], 'meal_plan_version_id' => $f['version'], 'start_date' => '2026-03-01']);
        } else {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            app(MealPlanFoundation::class)->create($actor, ['request_key' => (string) Str::uuid(), 'meal_type_ids' => [$f['type']], 'title' => 'Перший паралельний раціон']);
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Worker не запущено');
        }
        fclose($pipes[0]);
        $waiting = false;
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name='telmanfit-plan-worker' AND wait_event_type='Lock') waiting")->waiting;
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('Не доведено конкурентне очікування: '.$case);
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
        if ($case === 'draft' && DB::table('meal_plan_versions')->where('meal_plan_id', $plan)->count() !== 1) {
            throw new RuntimeException('Друга чернетка');
        }
        if ($case === 'create') {
            foreach (['Перший паралельний раціон', 'Паралельний раціон'] as $title) {
                $id = DB::table('meal_plans')->where('title', $title)->sole()->id;
                if (DB::table('meal_plan_versions')->where('meal_plan_id', $id)->where('status', 'draft')->count() !== 1) {
                    throw new RuntimeException('Lost write');
                }
            }
        }
    }
    echo "Meal plan concurrency PASS: real lock waits; duplicate draft, immutable item, overlap rejected; parallel atomic creates committed without lost write.\n";
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

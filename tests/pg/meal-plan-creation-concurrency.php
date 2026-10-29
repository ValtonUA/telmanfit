<?php

use App\Models\User;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\MealPlans\MealPlanFoundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='ux3-creation-worker'");
    DB::statement("SET lock_timeout='20s'");
    $plan = app(MealPlanFoundation::class)->create(User::findOrFail($data['actor']), $data['input']);
    echo $plan->id;
    exit;
}
DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL, 'last_reconciled_at' => now(), 'issuance_not_before' => now(), 'state' => 'ready']);
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic UX3 admin', 'role' => 'admin']));
$type = DB::table('meal_types')->insertGetId(['code' => 'synthetic-ux3-race', 'title' => 'Synthetic race']);
$input = ['request_key' => (string) Str::uuid(), 'title' => 'Один раціон', 'meal_type_ids' => [$type]];
$file = tempnam(sys_get_temp_dir(), 'telmanfit-ux3-');
chmod($file, 0600);
file_put_contents($file, json_encode(['actor' => $actor->id, 'input' => $input], JSON_THROW_ON_ERROR));
DB::unprepared('CREATE FUNCTION ux3_race_barrier() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock(37680391); RETURN NEW; END $$; CREATE TRIGGER ux3_race_barrier BEFORE INSERT ON meal_plan_creation_requests FOR EACH ROW EXECUTE FUNCTION ux3_race_barrier();');
$workers = [];
try {
    DB::beginTransaction();
    DB::select('SELECT pg_advisory_xact_lock(37680391)');
    for ($i = 0; $i < 2; $i++) {
        $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('Worker не запущено');
        }
        fclose($pipes[0]);
        $workers[] = [$proc, $pipes];
    }
    $deadline = microtime(true) + 20;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $count = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name='ux3-creation-worker' AND wait_event_type='Lock'")->n;
        if ($count == 2) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    if ($count != 2) {
        throw new RuntimeException('Не доведено конкурентне очікування обох запитів');
    }
    DB::commit();
    $ids = [];
    foreach ($workers as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new RuntimeException('Worker UX3: '.$err);
        }
        $ids[] = trim($out);
    }
    $workers = [];
    if (count(array_unique($ids)) !== 1 || DB::table('meal_plan_creation_requests')->where('request_key', $input['request_key'])->count() !== 1 || DB::table('meal_plans')->where('title', $input['title'])->count() !== 1) {
        throw new RuntimeException('Конкурентний запит створив дубль');
    }
    $plan = app(MealPlanFoundation::class)->create($actor, $input);
    if ((string) $plan->id !== $ids[0] || $plan->versions()->sole()->days()->count() !== 7) {
        throw new RuntimeException('Повтор після commit не повернув початковий результат');
    }
    echo "UX3 concurrent retry: два заблоковані PG процеси, один план/квитанція, повтор після commit PASS\n";
} finally {
    if (DB::transactionLevel()) {
        DB::rollBack();
    }
    foreach ($workers as [$proc, $pipes]) {
        proc_terminate($proc);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    @unlink($file);
}

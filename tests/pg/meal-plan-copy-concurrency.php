<?php

use App\Models\User;
use App\Services\MealPlans\MealPlanCopier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\MealPlans\AssignmentFixture;

require __DIR__.'/auth2-bootstrap.php';
$service = app(MealPlanCopier::class);
if (($argv[1] ?? '') === 'worker') {
    $d = json_decode(file_get_contents($argv[2]), true);
    DB::statement("SET application_name='telmanfit-cow-worker'");
    DB::statement("SET lock_timeout='10s'");
    try {
        $service->copy(User::findOrFail($d['actor']), $d['source'], $d['owner'], null, $d['token']);
        exit(2);
    } catch (ValidationException) {
        exit(0);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Синтетичний CoW admin', 'role' => 'admin']));
$owner = DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
$before = DB::table('meal_plans')->count();
$fixture = new class
{
    use AssignmentFixture;
};
$source = $fixture->assignmentVersion();
$token = $service->preview($actor, $source, $owner)['confirmation'];
$file = tempnam('/tmp', 'cow-race-');
chmod($file, 0600);
file_put_contents($file, json_encode(['actor' => $actor->id] + compact('owner', 'source', 'token')));
$worker = null;
try {
    DB::beginTransaction();
    $service->copy($actor, $source, $owner, null, $token);
    $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $deadline = microtime(true) + 8;
    do {
        DB::select('SELECT pg_stat_clear_snapshot()');
        $wait = DB::selectOne("SELECT EXISTS(SELECT FROM pg_stat_activity WHERE application_name='telmanfit-cow-worker' AND wait_event_type='Lock') waiting")->waiting;
        if ($wait) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if (! $wait) {
        throw new RuntimeException('Не доведено lock wait');
    }
    DB::commit();
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($worker);
    $worker = null;
    if ($code !== 0 || DB::table('meal_plans')->count() !== $before + 2) {
        throw new RuntimeException('CoW race failure '.$error);
    }
    echo "CoW concurrent double submit PASS (proven lock wait)\n";
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

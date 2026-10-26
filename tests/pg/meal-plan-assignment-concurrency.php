<?php

use App\Models\User;
use App\Services\MealPlans\MealPlanAssigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\AssignmentFixture;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$service = app(MealPlanAssigner::class);
if (($argv[1] ?? '') === 'worker') {
    $data = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET application_name='telmanfit-assignment-worker'");
    DB::statement("SET lock_timeout='10s'");
    try {
        $service->assign(User::findOrFail($data['actor']), $data['client'], $data['input'], $data['confirmation']);
        exit($data['case'] === 'independent-clients' ? 0 : 2);
    } catch (ValidationException) {
        exit(in_array($data['case'], ['double-first', 'double-replace', 'different-periods', 'raw-insert', 'stale-date', 'aba'], true) ? 0 : 3);
    } catch (HttpException $e) {
        exit(in_array($data['case'], ['role', 'fence'], true) && $e->getStatusCode() === 403 ? 0 : 4);
    }
}
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic assignment race admin', 'role' => 'admin']));
$fixture = new class
{
    use AssignmentFixture;
};
$version = $fixture->assignmentVersion(title: 'Синтетичний раціон призначення');
$file = tempnam(sys_get_temp_dir(), 'telmanfit-assignment-race-');
chmod($file, 0600);
$worker = null;
try {
    foreach (['double-first', 'double-replace', 'different-periods', 'raw-insert', 'stale-date', 'aba', 'role', 'fence', 'independent-clients'] as $case) {
        $client = DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
        $input = ['meal_plan_version_id' => $version, 'start_date' => today()->addDays(2)->toDateString(), 'end_date' => today()->addDays(10)->toDateString()];
        $old = null;
        if (in_array($case, ['double-replace', 'stale-date', 'aba'], true)) {
            $first = array_replace($input, ['start_date' => today()->toDateString(), 'expected' => $service->snapshot($actor, $client)]);
            $old = $service->assign($actor, $client, $first, $service->preview($actor, $client, $first)['confirmation']);
            $input['replace_assignment_id'] = $old->id;
        }
        $input['expected'] = $service->snapshot($actor, $client);
        $confirmation = $service->preview($actor, $client, $input)['confirmation'];
        file_put_contents($file, json_encode(compact('case', 'client', 'input', 'confirmation') + ['actor' => $actor->id], JSON_THROW_ON_ERROR));
        DB::beginTransaction();
        if (in_array($case, ['double-first', 'double-replace', 'different-periods'], true)) {
            $main = $case === 'different-periods' ? array_replace($input, ['start_date' => today()->addDays(20)->toDateString(), 'end_date' => null]) : $input;
            $service->assign($actor, $client, $main, $service->preview($actor, $client, $main)['confirmation']);
        } elseif ($case === 'raw-insert') {
            // FK key-share конкурентної raw вставки; service мусить перечитати розклад після lock.
            DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $version, 'start_date' => $input['start_date']]);
        } elseif (in_array($case, ['stale-date', 'aba'], true)) {
            DB::table('client_meal_plan_assignments')->where('id', $old->id)->update(['end_date' => today()->addDays(11)->toDateString()]);
            if ($case === 'aba') {
                DB::table('client_meal_plan_assignments')->where('id', $old->id)->update(['end_date' => $old->end_date->toDateString()]);
            }
        } elseif ($case === 'role') {
            DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
        } elseif ($case === 'fence') {
            DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        } else {
            // Інший клієнт не змінює snapshot, але writer серіалізується на shared version.
            DB::table('meal_plan_versions')->where('id', $version)->lockForUpdate()->sole();
            $other = DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
            $main = array_replace($input, ['expected' => $service->snapshot($actor, $other)]);
            $service->assign($actor, $other, $main, $service->preview($actor, $other, $main)['confirmation']);
        }
        $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Не запущено independent assignment worker');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("SELECT EXISTS(SELECT FROM pg_stat_activity WHERE application_name='telmanfit-assignment-worker' AND wait_event_type='Lock') waiting")->waiting;
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
        $count = DB::table('client_meal_plan_assignments')->where('client_id', $client)->count();
        $expected = $case === 'double-replace' ? 2 : (in_array($case, ['role', 'fence'], true) ? 0 : 1);
        if ($count !== $expected) {
            throw new RuntimeException('Неочікувана кількість призначень '.$case.': '.$count);
        }
        DB::table('users')->where('id', $actor->id)->update(['role' => 'admin']);
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'ready']);
        echo 'Assignment concurrency '.$case." PASS (proven lock wait)\n";
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

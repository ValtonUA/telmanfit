<?php

use App\Models\User;
use App\Services\MealPlans\MealPlanAssigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\MealPlans\AssignmentFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';
$actor = User::where('email', 'assignment-browser@example.invalid')->first();
if (($argv[1] ?? '') === 'seed') {
    $actor ??= User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic assignment browser admin', 'role' => 'admin', 'email' => 'assignment-browser@example.invalid', 'password' => Hash::make('Synthetic-Assignment-Only!')]));
    $client = DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
    $fixture = new class
    {
        use AssignmentFixture;
    };
    $version = $fixture->assignmentVersion(title: 'Синтетичний раціон призначення');
    echo json_encode(compact('client', 'version') + ['today' => today()->toDateString(), 'tomorrow' => today()->addDay()->toDateString()]);
} elseif (($argv[1] ?? '') === 'client') {
    echo DB::table('clients')->insertGetId(['coach_user_id' => $actor->id]);
} elseif (($argv[1] ?? '') === 'state') {
    echo json_encode(['assignments' => DB::table('client_meal_plan_assignments')->where('client_id', $argv[2])->orderBy('id')->get(), 'events' => DB::table('client_events')->where('client_id', $argv[2])->orderBy('id')->get()]);
} elseif (($argv[1] ?? '') === 'stale') {
    $service = app(MealPlanAssigner::class);
    $input = ['meal_plan_version_id' => (int) $argv[3], 'start_date' => today()->addDays(4)->toDateString(), 'expected' => $service->snapshot($actor, (int) $argv[2])];
    $service->assign($actor, (int) $argv[2], $input, $service->preview($actor, (int) $argv[2], $input)['confirmation']);
} elseif (($argv[1] ?? '') === 'revoke') {
    DB::table('users')->where('id', $actor->id)->update(['role' => 'coach']);
}

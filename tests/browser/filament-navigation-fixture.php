<?php

use App\Models\User;
use App\Services\MealPlans\MealPlanFoundation;
use App\Services\MealPlans\MealPlanStructure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Recipes\BatchFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';
$mode = $argv[1] ?? 'seed';
$email = $mode === 'revoke-user' || $mode === 'revoke' ? 'ux2-navigation-revoke@example.invalid' : 'ux2-navigation@example.invalid';
if (in_array($mode, ['revoke', 'expire'], true)) {
    $id = User::where('email', $email)->sole()->id;
    if ($mode === 'revoke') {
        DB::table('users')->where('id', $id)->increment('auth_version');
    } else {
        DB::table('sessions')->whereIn('id', DB::table('auth_session_states')->where('user_id', $id)->select('session_id'))
            ->update(['last_activity' => time() - 1800]);
    }
    exit;
}
if ($mode === 'title') {
    echo DB::table('recipe_versions')->where('id', (int) $argv[2])->value('title');
    exit;
}
if ($mode === 'count') {
    echo DB::table('recipe_versions')->count();
    exit;
}
$actor = User::create(['name' => 'UX2 синтетичний admin', 'email' => $email, 'password' => Hash::make('Synthetic-UX2-only!')]);
DB::table('users')->where('id', $actor->id)->update(['role' => 'admin']);
$actor->refresh();
if ($mode === 'revoke-user') {
    exit;
}
echo json_encode(DB::transaction(function () use ($actor): array {
    $fixture = new class
    {
        use BatchFixture;

        public function make(): array
        {
            return $this->batch();
        }
    };
    $batch = $fixture->make();
    $type = DB::table('meal_types')->insertGetId(['code' => 'synthetic-ux2-navigation', 'title' => 'Синтетичний прийом UX2']);
    $plan = app(MealPlanFoundation::class)->create($actor, ['request_key' => (string) Str::uuid(), 'meal_type_ids' => [$type], 'title' => 'UX2 синтетичний раціон']);
    $version = $plan->versions()->sole();
    app(MealPlanStructure::class)->generate($actor, $version, ['start_day' => 1, 'meal_type_ids' => [$type]]);

    return ['plan' => $plan->id, 'version' => $version->id, 'day' => $version->days()->orderBy('day_number')->first()->id, 'recipe' => $batch['version']];
}));

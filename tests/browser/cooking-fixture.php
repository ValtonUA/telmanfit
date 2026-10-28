<?php

namespace Tests\Browser;

use App\Models\User;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Cooking\CookingEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Recipes\CookingBrowserFixture;
use Throwable;

require __DIR__.'/../pg/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$actor = User::where('email', 'cooking-browser@example.invalid')->first();

if (($argv[1] ?? '') === 'touch') {
    $v = DB::table('cooking_template_versions')->where('id', $argv[2])->firstOrFail();
    $session = DB::table('cooking_sessions')->where('version_id', $v->id)->firstOrFail();
    $equipmentItems = DB::table('cooking_session_equipment')->where('session_id', $session->id)->orderBy('position')
        ->get()->map(fn ($row) => ['equipment_id' => (int) $row->equipment_id])->all();
    app(CookingEditor::class)->session($actor, $v->id, $v->revision, ['day' => 1, 'position' => 1, 'estimated_seconds' => 60,
        'introduction' => $session->introduction, 'equipment' => $session->equipment, 'equipment_items' => $equipmentItems,
        'content' => ['schema_version' => 1, 'stages' => [['id' => 'concurrent', 'title' => 'Змінено іншим автором', 'blocks' => [
            ['id' => 'a', 'type' => 'action', 'parts' => ['Перевірте нову редакцію.']],
            ['id' => 'timer', 'type' => 'timer', 'label' => 'Синтетичний таймер', 'seconds' => 120, 'message' => 'Демо час автора.'],
            ['id' => 'image', 'type' => 'image', 'url' => 'https://images.example.com/step.jpg', 'alt' => 'Синтетичне фото'],
        ]]]]], $session->id);
    exit;
}
if (($argv[1] ?? '') === 'client') {
    DB::transaction(function () use ($argv): void {
        $cookingVersion = DB::table('cooking_template_versions')->where('id', (int) ($argv[2] ?? 0))->firstOrFail();
        $plan = DB::table('meal_plan_versions as v')->join('meal_plans as p', 'p.id', '=', 'v.meal_plan_id')
            ->where('v.id', $cookingVersion->source_plan_version_id)->first(['v.id', 'v.created_by', 'p.week_start_weekday']);
        if ($plan === null) {
            throw new RuntimeException('Synthetic cooking source plan missing.');
        }
        $email = 'cooking-client@example.invalid';
        $user = User::where('email_normalized', $email)->first() ?? User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Синтетичний клієнт кухні', 'role' => 'client', 'email' => $email,
            'email_normalized' => $email, 'email_verified_at' => now(),
        ]));
        $client = DB::table('clients')->where('user_id', $user->id)->value('id')
            ?? DB::table('clients')->insertGetId(['user_id' => $user->id, 'coach_user_id' => $cookingVersion->created_by]);
        $offset = (now()->isoWeekday() - (int) $plan->week_start_weekday + 7) % 7;
        $start = now()->subDays($offset)->toDateString();
        DB::table('client_meal_plan_assignments')->insert(['client_id' => $client,
            'meal_plan_version_id' => $plan->id, 'created_by' => $cookingVersion->created_by,
            'start_date' => $start, 'end_date' => now()->subDays($offset)->addDays(6)->toDateString()]);
    });
    echo 'Synthetic client assignment ready';
    exit;
}

DB::transaction(function () {
    DB::statement("SET LOCAL statement_timeout = '45s'");
    DB::statement("SET LOCAL lock_timeout = '5s'");
    fwrite(STDERR, "Cooking fixture: migrations\n");
    if (Schema::hasColumn('kitchen_equipment', 'image_url') === false) {
        (require database_path('migrations/2026_10_26_000100_add_kitchen_equipment_image_url.php'))->up();
    }
    if (Schema::hasTable('cooking_templates') === false) {
        (require database_path('migrations/2026_10_28_000000_create_cooking_templates.php'))->up();
        (require database_path('migrations/2026_10_28_010000_add_cooking_selection.php'))->up();
    }
    if (Schema::hasTable('cooking_session_equipment') === false) {
        (require database_path('migrations/2026_10_28_020000_add_cooking_session_equipment.php'))->up();
    }
    DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL, 'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()'), 'state' => 'ready']);
    $actor = User::where('email', 'cooking-browser@example.invalid')->first() ?? User::findOrFail(DB::table('users')->insertGetId(['name' => 'Синтетичний автор кухні', 'role' => 'admin', 'email' => 'cooking-browser@example.invalid', 'password' => Hash::make('Synthetic-Cooking-Only!')]));
    $fixture = new CookingBrowserFixture;
    fwrite(STDERR, "Cooking fixture: synthetic plan\n");
    $plan = $fixture->make();
    fwrite(STDERR, "Cooking fixture: template\n");
    $editor = app(CookingEditor::class);
    $v = $editor->create($actor, ['title' => 'Синтетична кухня', 'source_plan_version_id' => $plan['version'], 'source_week' => 1, 'mode' => 1]);
    fwrite(STDERR, "Cooking fixture: session\n");
    $equipment = DB::table('kitchen_equipment')->insertGetId(['name' => 'Каструля · синтетичний fixture', 'image_url' => 'https://images.example.com/pot.jpg']);
    $s = $editor->session($actor, $v, 0, ['day' => 1, 'position' => 1, 'estimated_seconds' => 60,
        'introduction' => 'Синтетичний вступ автора.', 'equipment' => 'Додаткове авторське пояснення.', 'equipment_items' => [['equipment_id' => $equipment]],
        'content' => ['schema_version' => 1, 'stages' => [['id' => 'prepare', 'title' => 'Приготування', 'blocks' => [['id' => 'a', 'type' => 'action', 'parts' => ['Вода 700 мл — авторський параметр.']]]]]]]);
    $revision = fn () => DB::table('cooking_template_versions')->where('id', $v)->value('revision');
    fwrite(STDERR, "Cooking fixture: outputs\n");
    foreach (json_decode(DB::table('cooking_template_versions')->where('id', $v)->value('menu_pattern'), true)['items'] as $slot) {
        if ($slot['recipe_version_id'] === null) {
            continue;
        }
        fwrite(STDERR, "Cooking fixture: output begin\n");
        $selector = array_key_first($editor->selectors($v));
        $o = $editor->output($actor, $v, $revision(), $s, $selector);
        fwrite(STDERR, "Cooking fixture: output saved\n");
        $editor->allocate($actor, $v, $revision(), $slot['day'], $slot['meal_position'], $slot['item_position'], $o);
        fwrite(STDERR, "Cooking fixture: allocation saved\n");
    }
    fwrite(STDERR, "Cooking fixture: done\n");
    echo $v;
});

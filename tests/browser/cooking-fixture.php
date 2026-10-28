<?php

use App\Models\User;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Cooking\CookingEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Recipes\BatchFixture;
use Throwable;

require __DIR__.'/../pg/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$actor = User::where('email', 'cooking-browser@example.invalid')->first();

final class CookingBrowserFixture
{
    use BatchFixture;

    public function make(): array
    {
        $batch = $this->batch();
        $this->publish($batch['version']);
        $serving = DB::table('uoms')->where('code', 'serving')->value('id');
        if ($serving === null) {
            $serving = DB::table('uoms')->insertGetId(['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
        }
        $weekday = (int) now()->isoWeekday();
        $plan = DB::table('meal_plans')->insertGetId(['title' => 'Синтетичний reusable cooking plan', 'week_start_weekday' => $weekday]);
        $version = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
        $type = DB::table('meal_types')->insertGetId(['code' => 'cook-browser-'.bin2hex(random_bytes(4)), 'title' => 'Синтетичний прийом']);
        for ($day = 1; $day <= 7; $day++) {
            $dayId = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $version, 'day_number' => $day]);
            $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $dayId, 'meal_type_id' => $type, 'title' => 'Синтетична страва']);
            DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => DB::table('recipe_versions')->where('id', $batch['version'])->value('food_id'),
                'quantity' => '1.00', 'uom_id' => $serving, 'recipe_version_id' => $batch['version'],
                'recipe_batch_format_id' => $batch['formats']['single'], 'recipe_portion_divisor' => '1.00']);
        }
        DB::table('meal_plan_versions')->where('id', $version)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return ['version' => $version, 'recipe_version' => $batch['version'], 'format' => $batch['formats']['single']];
    }
}
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
            ->where('v.id', $cookingVersion->source_plan_version_id)->first(['v.id', 'p.week_start_weekday']);
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
    if (Schema::hasTable('cooking_templates') === false) {
        (require database_path('migrations/2026_10_28_000000_create_cooking_templates.php'))->up();
        (require database_path('migrations/2026_10_28_010000_add_cooking_selection.php'))->up();
    }
    DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL, 'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()'), 'state' => 'ready']);
    $actor = User::where('email', 'cooking-browser@example.invalid')->first() ?? User::findOrFail(DB::table('users')->insertGetId(['name' => 'Синтетичний автор кухні', 'role' => 'admin', 'email' => 'cooking-browser@example.invalid', 'password' => Hash::make('Synthetic-Cooking-Only!')]));
    $fixture = new CookingBrowserFixture;
    $plan = $fixture->make();
    $editor = app(CookingEditor::class);
    $v = $editor->create($actor, ['title' => 'Синтетична кухня', 'source_plan_version_id' => $plan['version'], 'source_week' => 1, 'mode' => 1]);
    $equipment = DB::table('kitchen_equipment')->insertGetId(['name' => 'Каструля · синтетичний fixture', 'image_url' => 'https://images.example.com/pot.jpg']);
    $s = $editor->session($actor, $v, 0, ['day' => 1, 'position' => 1, 'estimated_seconds' => 60,
        'introduction' => 'Синтетичний вступ автора.', 'equipment' => 'Додаткове авторське пояснення.', 'equipment_items' => [['equipment_id' => $equipment]],
        'content' => ['schema_version' => 1, 'stages' => [['id' => 'prepare', 'title' => 'Приготування', 'blocks' => [['id' => 'a', 'type' => 'action', 'parts' => ['Вода 700 мл — авторський параметр.']]]]]]]);
    $revision = fn () => DB::table('cooking_template_versions')->where('id', $v)->value('revision');
    foreach (json_decode(DB::table('cooking_template_versions')->where('id', $v)->value('menu_pattern'), true)['items'] as $slot) {
        if ($slot['recipe_version_id'] === null) {
            continue;
        }
        $o = $editor->output($actor, $v, $revision(), $s, $plan['recipe_version'].':'.$plan['format'].':1.00');
        $editor->allocate($actor, $v, $revision(), $slot['day'], $slot['meal_position'], $slot['item_position'], $o);
    }
    echo $v;
});

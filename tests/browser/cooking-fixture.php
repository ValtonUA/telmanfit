<?php

use App\Models\User;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Cooking\CookingEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\MealPlans\PlanFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$actor = User::where('email', 'cooking-browser@example.invalid')->first();
if (($argv[1] ?? '') === 'touch') {
    $v = DB::table('cooking_template_versions')->where('id', $argv[2])->firstOrFail();
    app(CookingEditor::class)->session($actor, $v->id, $v->revision, ['day' => 1, 'position' => 1, 'estimated_seconds' => 60,
        'content' => ['schema_version' => 1, 'stages' => [['id' => 'concurrent', 'title' => 'Змінено іншим автором', 'blocks' => [['id' => 'a', 'type' => 'action', 'parts' => ['Перевірте нову редакцію.']]]]]]],
        DB::table('cooking_sessions')->where('version_id', $v->id)->value('id'));
    exit;
}
DB::transaction(function () {
    if (! Schema::hasTable('cooking_templates')) {
        (require database_path('migrations/2026_10_28_000000_create_cooking_templates.php'))->up();
        (require database_path('migrations/2026_10_28_010000_add_cooking_selection.php'))->up();
    }
    DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL, 'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()'), 'state' => 'ready']);
    $actor = User::where('email', 'cooking-browser@example.invalid')->first() ?? User::findOrFail(DB::table('users')->insertGetId(['name' => 'Синтетичний автор кухні', 'role' => 'admin', 'email' => 'cooking-browser@example.invalid', 'password' => Hash::make('Synthetic-Cooking-Only!')]));
    $fixture = new class
    {
        use PlanFixture;

        public function make(int $actor): array
        {
            return $this->populatedPlan($actor);
        }
    };
    $plan = $fixture->make($actor->id);
    $editor = app(CookingEditor::class);
    $v = $editor->create($actor, ['title' => 'Синтетична кухня', 'source_plan_version_id' => $plan['version'], 'source_week' => 1, 'mode' => 1]);
    $s = $editor->session($actor, $v, 0, ['day' => 1, 'position' => 1, 'estimated_seconds' => 60,
        'content' => ['schema_version' => 1, 'stages' => [['id' => 'prepare', 'title' => 'Приготування', 'blocks' => [['id' => 'a', 'type' => 'action', 'parts' => ['Вода 700 мл — авторський параметр.']]]]]]]);
    $revision = fn () => DB::table('cooking_template_versions')->where('id', $v)->value('revision');
    foreach (json_decode(DB::table('cooking_template_versions')->where('id', $v)->value('menu_pattern'), true)['items'] as $slot) {
        if ($slot['recipe_version_id'] === null) {
            continue;
        }
        $o = $editor->output($actor, $v, $revision(), $s, array_key_first($editor->selectors($v)));
        $editor->allocate($actor, $v, $revision(), $slot['day'], $slot['meal_position'], $slot['item_position'], $o);
    }
    echo $v;
});

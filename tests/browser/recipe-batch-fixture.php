<?php

use App\Models\User;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Recipes\BatchEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Recipes\BatchFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
if (($argv[1] ?? '') === 'catalog-change') {
    $ingredient = DB::table('recipe_ingredients')->where('recipe_version_id', getenv('BATCH_BROWSER_VERSION'))->value('ingredient_id');
    DB::table('ingredients')->where('id', $ingredient)->update(['kcal_per_100g' => '124.00']);
    exit;
}
DB::transaction(function () {
    if (! Schema::hasTable('auth_security_runtime')) {
        foreach (glob(database_path('migrations/2026_10_22_*.php')) as $file) {
            (require $file)->up();
        }
        (require database_path('migrations/2026_10_24_000000_revise_client_profiles.php'))->up();
    }
    DB::table('auth_security_runtime')->insertOrIgnore(['id' => 1, 'restore_epoch' => SyntheticEpoch::INITIAL, 'last_reconciled_at' => DB::raw('clock_timestamp()'), 'issuance_not_before' => DB::raw('clock_timestamp()'), 'state' => 'ready']);
    DB::table('users')->insert(['name' => 'Synthetic recipe author', 'role' => 'admin', 'email' => 'recipe-browser@example.invalid', 'password' => Hash::make('Synthetic-Recipe-Only!')]);
    $fixture = new class
    {
        use BatchFixture;

        public function make(): array
        {
            return $this->batch();
        }
    };
    $b = $fixture->make();
    $unit = DB::table('uoms')->insertGetId(['code' => 'test-pan', 'label' => 'шт.', 'kind' => 'count']);
    $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Synthetic browser pan']);
    config(['recipes.batch_editor_enabled' => true]);
    $editor = app(BatchEditor::class);
    $actor = User::where('email', 'recipe-browser@example.invalid')->firstOrFail();
    $state = $editor->open($actor, $b['version']);
    $state['payload']['equipment'] = [['id' => 'new-browser-pan', 'kitchen_equipment_id' => (string) $tool, 'min_value' => '1.00', 'uom_id' => (string) $unit, 'scaling_mode' => 'proportional', 'tip' => 'Перевірити місткість']];
    $state['payload']['directions'] = ['Використати {{equipment:new-browser-pan:quantity}}, готувати 20 хв при 180°C.'];
    $preview = $editor->preview($actor, $b['version'], $state['revision'], $state['payload']);
    $editor->save($actor, $b['version'], $state['revision'], $state['payload'], $preview['token'], false, true);
    echo $b['version'];
});

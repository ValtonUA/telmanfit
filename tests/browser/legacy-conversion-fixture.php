<?php

use Illuminate\Support\Facades\DB;
use Tests\Recipes\LegacyFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';
if (($argv[1] ?? '') === 'hash') {
    $id = (int) $argv[2];
    $state = [];
    foreach (['recipe_versions', 'recipe_ingredients', 'recipe_components', 'recipe_directions', 'recipe_kitchen_equipment'] as $table) {
        $state[$table] = DB::table($table)->where($table === 'recipe_versions' ? 'id' : 'recipe_version_id', $id)->orderBy('id')->get()->all();
    }
    foreach (['foods', 'meal_items', 'client_meal_plan_assignments'] as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    echo hash('sha256', json_encode($state));
    exit;
}
$fixture = new class
{
    use LegacyFixture;

    public function make(bool $published): array
    {
        return $this->legacy($published);
    }
};
DB::transaction(function () use ($fixture, $argv) {
    $b = $fixture->make(($argv[1] ?? '') === 'published');
    if (($argv[1] ?? '') === 'invalid') {
        DB::table('recipe_directions')->where('recipe_version_id', $b['version'])->update(['direction_text' => '{{ingredient:999999:grams}}']);
    }
    echo $b['version'];
});

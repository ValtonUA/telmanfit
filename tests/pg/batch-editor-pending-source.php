<?php

use App\Models\User;
use App\Services\Recipes\BatchEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Recipes\BatchFixture;

require __DIR__.'/auth2-bootstrap.php';
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
config(['recipes.batch_editor_enabled' => true]);
$fixture = new class
{
    use BatchFixture;

    public function create(): array
    {
        return DB::transaction(fn () => $this->batch());
    }
};
$editor = app(BatchEditor::class);
$actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic pending provenance author', 'role' => 'admin']));
$catalog = pg_connect('host='.getenv('DB_HOST').' port=5432 dbname=telmanfit_stage1_test user=telmanfit_test_runner', PGSQL_CONNECT_FORCE_NEW);
if (! $catalog) {
    throw new RuntimeException('Потрібне незалежне synthetic PG з’єднання.');
}
function checkPendingSource(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function savePendingSource(BatchEditor $editor, User $actor, int $id, array $state, bool $refresh = false): void
{
    $p = $editor->preview($actor, $id, $state['revision'], $state['payload']);
    $editor->save($actor, $id, $state['revision'], $state['payload'], $p['token'], $refresh);
    checkPendingSource(DB::transactionLevel() === 0, 'Команда не виконала справжній COMMIT.');
}
function reopenPendingSource(BatchEditor $editor, User $actor, int $id): array
{
    DB::purge(); // Новий PDO/transaction snapshot, не reload у тій самій транзакції.

    return $editor->open($actor, $id);
}
foreach (['nutrition-auto', 'conversion-auto', 'conversion-manual', 'unchanged-auto'] as $case) {
    $b = $fixture->create();
    $id = $b['version'];
    $state = $editor->open($actor, $id);
    if (str_starts_with($case, 'conversion')) {
        $code = 'pending-source-'.$id;
        $unit = DB::table('uoms')->insertGetId(['code' => $code, 'label' => 'Synthetic piece', 'kind' => 'count']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $b['ingredient'], 'uom_id' => $unit, 'grams_per_uom' => '100.00']);
        $state['payload']['rows'][0]['week'] = ['mode' => $case === 'conversion-manual' ? 'manual' : 'auto', 'quantity' => '3.00', 'uom_id' => (string) $unit, 'rounding' => 'ceil', 'step' => '1.00', 'confirmed' => true];
        $state['payload']['rows'][0]['auto_policies'] = [$code => ['rounding' => 'ceil', 'step' => '1.00', 'confirmed' => true]];
    }
    savePendingSource($editor, $actor, $id, $state, true);
    $state = reopenPendingSource($editor, $actor, $id);
    $hash = DB::selectOne('SELECT recipe_batch_source_hash(?) hash', [$b['ingredient']])->hash;
    $beforeIngredient = DB::table('recipe_ingredients')->where('recipe_version_id', $id)->value('ingredient_snapshot');
    $modes = array_map(fn ($code) => $state['payload']['rows'][0][$code]['mode'], ['week', 'half_week']);
    $state['payload']['rows'][0]['quantity'] = '';
    savePendingSource($editor, $actor, $id, $state);
    $state = reopenPendingSource($editor, $actor, $id);
    checkPendingSource(array_map(fn ($code) => $state['payload']['rows'][0][$code]['mode'], ['week', 'half_week']) === $modes, 'Reload змінив AUTO/MANUAL mode.');
    $saved = DB::table('recipe_versions')->where('id', $id)->value('draft_pending_rows');
    $pending = json_decode($saved, true)[0];
    foreach (['week', 'half_week'] as $format) {
        checkPendingSource($pending['overrides'][$format]['input_snapshot']['source_hash'] === $hash, 'Втрачено accepted source_hash.');
        checkPendingSource($pending['overrides'][$format]['input_snapshot']['ingredient_snapshot'] == json_decode($beforeIngredient, true), 'Втрачено ingredient snapshot.');
    }
    checkPendingSource(DB::table('recipe_ingredients')->where('recipe_version_id', $id)->count() === 0, 'Pending залишив normalized копію.');
    $p = $editor->preview($actor, $id, $state['revision'], $state['payload']);
    checkPendingSource($p['evaluation']['incomplete'] && $p['evaluation']['totals']['week']['kcal'] === '0.000000', 'Pending потрапив у КБЖВ.');
    checkPendingSource(DB::table('recipe_versions')->where('id', $id)->value('draft_pending_rows') === $saved, 'GET/Preview змінив provenance.');

    if ($case === 'nutrition-auto') {
        $changed = pg_query_params($catalog, 'UPDATE ingredients SET kcal_per_100g=124.00 WHERE id=$1', [$b['ingredient']]);
    } elseif (str_starts_with($case, 'conversion')) {
        $changed = pg_query_params($catalog, 'UPDATE ingredient_uoms SET grams_per_uom=80.00 WHERE ingredient_id=$1 AND uom_id=$2', [$b['ingredient'], $unit]);
    } else {
        $changed = true;
    }
    checkPendingSource((bool) $changed, 'Джерельна зміна не закомічена.');
    // Повторний pending Save після зміни джерел не може замінити accepted provenance.
    savePendingSource($editor, $actor, $id, $state);
    $state = reopenPendingSource($editor, $actor, $id);
    $again = json_decode(DB::table('recipe_versions')->where('id', $id)->value('draft_pending_rows'), true)[0];
    checkPendingSource($again['overrides']['week']['input_snapshot']['source_hash'] === $hash, 'Повторний pending Save прийняв новий source без згоди.');
    $state['payload']['rows'][0]['quantity'] = '150.00';
    // Фальшивий client snapshot ігнорується; сервер читає provenance тільки з JSONB.
    $state['payload']['rows'][0]['week']['input_snapshot'] = ['source_hash' => 'forged'];
    $p = $editor->preview($actor, $id, $state['revision'], $state['payload']);
    checkPendingSource(($p['diff'] !== []) === ($case !== 'unchanged-auto'), 'Неправильна наявність source diff.');
    if ($case !== 'unchanged-auto') {
        checkPendingSource($p['diff'][$state['payload']['rows'][0]['id']]['week']['ingredient_before'] == json_decode($beforeIngredient, true), 'Diff не містить попереднього ingredient snapshot.');
        $rootBefore = DB::table('recipe_versions')->where('id', $id)->first();
        $foodCount = DB::table('foods')->count();
        foreach (['save', 'publish'] as $operation) {
            try {
                if ($operation === 'save') {
                    $editor->save($actor, $id, $state['revision'], $state['payload'], $p['token']);
                } else {
                    $editor->publish($actor, $id, $state['revision'], $p['token']);
                }
                throw new RuntimeException('Stale source пройшов '.$operation.' без Refresh.');
            } catch (ValidationException) {
                checkPendingSource(DB::table('recipe_versions')->where('id', $id)->first() == $rootBefore, 'Відмова змінила draft.');
                checkPendingSource(DB::table('foods')->count() === $foodCount, 'Відмова створила food.');
            }
        }
    }
    savePendingSource($editor, $actor, $id, $state, $case !== 'unchanged-auto');
    $state = reopenPendingSource($editor, $actor, $id);
    checkPendingSource(array_map(fn ($code) => $state['payload']['rows'][0][$code]['mode'], ['week', 'half_week']) === $modes, 'Refresh змінив режим.');
    if ($case === 'conversion-manual') {
        checkPendingSource($state['payload']['rows'][0]['week']['quantity'] === '3.00' && $state['payload']['rows'][0]['week']['uom_id'] === (string) $unit, 'Refresh переписав manual pair.');
    }
    if ($case === 'conversion-auto') {
        checkPendingSource($state['payload']['rows'][0]['week']['quantity'] === '14.00' && $state['payload']['rows'][0]['half_week']['quantity'] === '7.00', 'AUTO не відновив accepted UOM policy.');
    }
    checkPendingSource(DB::table('recipe_versions')->where('id', $id)->value('draft_pending_rows') === '[]', 'Повний рядок залишив pending копію.');
}
pg_close($catalog);
echo "Pending source COMMIT/reload PASS: AUTO nutrition/conversion/unchanged + MANUAL conversion; explicit Refresh, rollback and server-only provenance.\n";

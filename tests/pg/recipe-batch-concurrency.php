<?php

use Illuminate\Support\Facades\DB;
use Tests\Recipes\BatchFixture;

require __DIR__.'/auth2-bootstrap.php';
(require database_path('migrations/2026_10_24_010000_create_recipe_batches.php'))->up();
$fixture = new class
{
    use BatchFixture;

    public function make(): array
    {
        return DB::transaction(fn () => $this->batch());
    }
};
$b = $fixture->make();
$dsn = 'host='.getenv('DB_HOST').' port=5432 dbname=telmanfit_stage1_test user=telmanfit_test_runner';
$a = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
$c = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
function checkBatch(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function waitBatchLock($a, int $pid): void
{
    $deadline = microtime(true) + 5;
    do {
        pg_query($a, 'SELECT pg_stat_clear_snapshot()');
        if (pg_fetch_result(pg_query_params($a, "SELECT coalesce(wait_event_type='Lock',false) FROM pg_stat_activity WHERE pid=$1", [$pid]), 0, 0) === 't') {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Не доведено очікування row lock.');
}
foreach ([$a, $c] as $connection) {
    checkBatch($connection !== false, 'Потрібні незалежні PG connections.');
    pg_query($connection, "SET statement_timeout='10s'");
}
$pid = (int) pg_fetch_result(pg_query($c, 'SELECT pg_backend_pid()'), 0, 0);
$version = $b['version'];
$revision = DB::table('recipe_versions')->where('id', $version)->value('lock_version');
foreach ([false, true] as $rollback) {
    pg_query($a, 'BEGIN');
    pg_query($c, 'BEGIN');
    pg_query($a, "SELECT recipe_batch_assert_revision($version,$revision)");
    pg_query($a, "UPDATE recipe_versions SET title='first',lock_version=lock_version+1 WHERE id=$version");
    pg_send_query($c, "SELECT recipe_batch_assert_revision($version,$revision)");
    waitBatchLock($a, $pid);
    pg_query($a, $rollback ? 'ROLLBACK' : 'COMMIT');
    $result = pg_get_result($c);
    checkBatch($rollback ? pg_result_status($result) === PGSQL_TUPLES_OK : pg_result_error_field($result, PGSQL_DIAG_SQLSTATE) === '40001', 'Некоректний CAS після конкуренції.');
    while (pg_get_result($c) !== false) {
    }
    pg_query($c, 'ROLLBACK');
    $revision = DB::table('recipe_versions')->where('id', $version)->value('lock_version');
}
// Publish/refresh locks виключають UPDATE та INSERT phantom у довіднику.
foreach (["UPDATE uoms SET label='changed' WHERE id={$b['g']}",
    "UPDATE ingredients SET kcal_per_100g=124 WHERE id={$b['ingredient']}",
    "INSERT INTO ingredient_uoms(ingredient_id,uom_id,grams_per_uom) VALUES({$b['ingredient']},{$b['g']},1)"] as $sql) {
    pg_query($a, 'BEGIN');
    pg_query($a, "SELECT recipe_batch_validate($version,true)");
    pg_query($c, 'BEGIN');
    pg_send_query($c, $sql);
    waitBatchLock($a, $pid);
    pg_query($a, 'ROLLBACK');
    checkBatch(pg_result_status(pg_get_result($c)) === PGSQL_COMMAND_OK, 'Довідниковий запис не завершився.');
    while (pg_get_result($c) !== false) {
    }
    pg_query($c, 'ROLLBACK');
}
pg_close($a);
pg_close($c);
echo "Batch concurrency: PASS; stale editor 40001, rollback retry, nutrition/UOM UPDATE and conversion INSERT waited for real PG locks.\n";

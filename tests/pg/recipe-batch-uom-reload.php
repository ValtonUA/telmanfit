<?php

use App\Domain\Recipes\BatchEvaluator;
use Illuminate\Support\Facades\DB;
use Tests\Recipes\BatchFixture;
use Tests\Recipes\InheritedUomCases;

require __DIR__.'/auth2-bootstrap.php';
// Runner викликає після concurrency: forward DDL уже закомічений у synthetic DB.
$fixture = new class
{
    use BatchFixture;

    public function create(array $row): array
    {
        return DB::transaction(function () use ($row): array {
            $b = $this->batch();
            foreach ($row['factors'] as $code => $factor) {
                $id = DB::table('uoms')->where('code', $code)->value('id') ?? DB::table('uoms')->insertGetId(['code' => $code, 'label' => $code, 'kind' => 'count']);
                DB::table('ingredient_uoms')->insert(['ingredient_id' => $b['ingredient'], 'uom_id' => $id, 'grams_per_uom' => $factor]);
            }
            $out = $this->storeEvaluated($b, $row);

            return [$b, $out];
        });
    }
};
foreach (InheritedUomCases::cases() as [$uom, $quantity, $factor, $step]) {
    foreach (['auto', 'manual'] as $mode) {
        $row = InheritedUomCases::row($uom, $quantity, $factor, $step);
        if ($mode === 'manual') {
            $row['half_week'] = ['mode' => 'manual', 'uom' => 'g', 'quantity' => '123.45', 'policy' => ['rounding' => 'exact', 'step' => '0.01']];
        }
        [$b, $expected] = $fixture->create($row);
        // Нове незалежне з'єднання читає лише COMMIT, без форми/попереднього PHP state.
        $read = pg_connect('host='.getenv('DB_HOST').' port=5432 dbname=telmanfit_stage1_test user=telmanfit_test_runner', PGSQL_CONNECT_FORCE_NEW);
        if ($read === false) {
            throw new RuntimeException('Не вдалося повторно відкрити synthetic DB.');
        }
        $records = pg_fetch_all(pg_query_params($read, 'SELECT q.*,u.code FROM recipe_batch_quantities q JOIN uoms u ON u.id=q.uom_id WHERE recipe_ingredient_id=$1 ORDER BY format_code', [$b['row']]));
        $base = pg_fetch_assoc(pg_query_params($read, 'SELECT quantity,ingredient_snapshot FROM recipe_ingredients WHERE id=$1', [$b['row']]));
        $snapshot = json_decode($base['ingredient_snapshot'], true);
        $restored = ['quantity' => $base['quantity'], 'nutrients' => array_intersect_key($snapshot, array_flip(BatchEvaluator::NUTRIENTS)), 'factors' => [],
            'ingredient_fingerprint' => 'reloaded', 'conversion_fingerprint' => 'reloaded'];
        foreach ($records as $record) {
            $input = json_decode($record['input_snapshot'], true);
            $policy = array_intersect_key($input, array_flip(['rounding', 'step', 'confirmed']));
            $restored[$record['format_code']] = ['mode' => $record['mode'], 'uom' => $record['code'], 'quantity' => $record['quantity'], 'policy' => $policy];
            $restored['factors'][$record['code']] = $input['factor'];
            if ($record['format_code'] === 'half_week' && $mode === 'auto') {
                $restored['auto_policies'] = $input['auto_policies'];
            }
            foreach (['mode', 'quantity', 'grams' => 'quantity_grams', 'uom' => 'code'] as $key => $column) {
                $key = is_int($key) ? $column : $key;
                if ($expected['rows'][$b['row']][$record['format_code']][$key] !== $record[$column]) {
                    throw new RuntimeException('COMMIT/reopen змінив quantity/UOM/mode/grams.');
                }
            }
        }
        $actual = (new BatchEvaluator)->evaluate([$b['row'] => $restored]);
        foreach (['week', 'half_week'] as $code) {
            foreach (['mode', 'quantity', 'uom', 'grams'] as $key) {
                if ($actual['rows'][$b['row']][$code][$key] !== $expected['rows'][$b['row']][$code][$key]) {
                    throw new RuntimeException('Reload evaluator змінив збережену пару/режим.');
                }
            }
        }
        if ($actual['totals'] !== $expected['totals']) {
            throw new RuntimeException('Reload evaluator змінив totals.');
        }
        pg_close($read);
    }
}
echo "Batch UOM COMMIT/reopen: PASS; 6 auto/manual cases, independent PG connections and PHP reevaluation.\n";

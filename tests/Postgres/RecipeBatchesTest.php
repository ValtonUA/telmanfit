<?php

namespace Tests\Postgres;

use App\Domain\Recipes\BatchEvaluator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Recipes\BatchFixture;
use Tests\Recipes\InheritedUomCases;

class RecipeBatchesTest extends PostgresTestCase
{
    use BatchFixture;

    private function install(): void
    {
        (require database_path('migrations/2026_10_24_010000_create_recipe_batches.php'))->up();
    }

    private function valid(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function rejected(callable $action, string $message): void
    {
        DB::statement('SAVEPOINT batch_negative');
        try {
            $action();
            $this->valid();
            $this->fail('Очікувана відмова: '.$message);
        } catch (QueryException $e) {
            DB::statement('ROLLBACK TO SAVEPOINT batch_negative');
            $this->assertStringContainsString($message, $e->getMessage());
        } finally {
            DB::statement('RELEASE SAVEPOINT batch_negative');
        }
    }

    public function test_full_partial_and_empty_draft_round_trip(): void
    {
        $this->install();
        $b = $this->batch();
        $empty = $this->batch(false);
        $pending = '[{"id":"pending:row-1","ingredient_id":"1","quantity":""}]';
        DB::update('UPDATE recipe_versions SET draft_pending_rows=?::jsonb,lock_version=lock_version+1 WHERE id=?', [$pending, $b['version']]);
        $this->valid();
        $this->assertEquals(json_decode($pending, true), json_decode(DB::table('recipe_versions')->where('id', $b['version'])->value('draft_pending_rows'), true));
        $before = DB::table('recipe_batch_quantities')->where('recipe_version_id', $b['version'])->get()->toJson();
        DB::select('SELECT recipe_batch_validate(?)', [$b['version']]);
        $this->assertSame($before, DB::table('recipe_batch_quantities')->where('recipe_version_id', $b['version'])->get()->toJson());
        $this->assertSame(0, DB::table('recipe_ingredients')->where('recipe_version_id', $empty['version'])->count());
        $this->rejected(fn () => $this->publish($b['version']), 'Incomplete batch');
        $this->rejected(fn () => DB::update("UPDATE recipe_versions SET draft_pending_rows='[{\"id\":\"pending:x\",\"ingredient_id\":\"1\",\"quantity\":\"1\",\"uom_id\":\"1\"}]',lock_version=lock_version+1 WHERE id=?", [$b['version']]), 'check constraint');
    }

    public function test_publish_atomic_immutability_old_and_new_reparent_and_foreign_keys(): void
    {
        $this->install();
        $b = $this->batch();
        $other = $this->batch();
        $this->valid();
        $this->publish($b['version']);
        $this->valid();
        foreach (['recipe_batch_formats', 'recipe_batch_quantities'] as $table) {
            $this->rejected(fn () => DB::table($table)->where('recipe_version_id', $b['version'])->delete(), 'immutable');
            $copy = (array) DB::table($table)->where('recipe_version_id', $b['version'])->first();
            unset($copy['id']);
            $this->rejected(fn () => DB::table($table)->insert($copy), 'immutable');
            $this->rejected(fn () => DB::table($table)->where('recipe_version_id', $b['version'])->update(['recipe_version_id' => $other['version']]), 'immutable');
            $this->rejected(fn () => DB::table($table)->where('recipe_version_id', $other['version'])->update(['recipe_version_id' => $b['version']]), 'immutable');
        }
        $this->rejected(fn () => DB::table('recipe_versions')->where('id', $b['version'])->update(['title' => 'changed', 'lock_version' => 999]), 'immutable');
        $this->rejected(fn () => DB::table('recipe_ingredients')->where('id', $b['row'])->update(['quantity' => 1]), 'immutable');
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_version_id', $other['version'])->update(['recipe_ingredient_id' => $b['row']]), 'foreign key');
        $this->assertSame('published', DB::table('recipe_versions')->where('id', $b['version'])->value('status'));
    }

    public function test_manual_review_cascade_ack_reset_and_failed_publication_rollback(): void
    {
        $this->install();
        $b = $this->batch();
        DB::update("UPDATE recipe_batch_quantities SET mode='manual',quantity=1000,quantity_grams=1000,manual_context='{}' WHERE recipe_ingredient_id=? AND format_code='week'", [$b['row']]);
        DB::update("UPDATE recipe_batch_quantities SET quantity=500,quantity_grams=500 WHERE recipe_ingredient_id=? AND format_code='half_week'", [$b['row']]);
        $this->syncTotals($b['version']);
        $this->valid();
        $foods = DB::table('foods')->count();
        $this->rejected(fn () => $this->publish($b['version']), 'Manual needs review');
        $this->assertSame($foods, DB::table('foods')->count());
        $this->assertSame('draft', DB::table('recipe_versions')->where('id', $b['version'])->value('status'));
        DB::update("UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_ingredient_id=? AND mode='manual'", [$b['row']]);
        $this->publish($b['version']);
        $this->valid();
    }

    public function test_every_parent_change_marks_manual_even_below_one_percent(): void
    {
        $this->install();
        $b = $this->batch();
        DB::update("UPDATE recipe_batch_quantities SET mode='manual',manual_context='{}' WHERE recipe_ingredient_id=?", [$b['row']]);
        DB::update('UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_ingredient_id=?', [$b['row']]);
        DB::table('recipe_ingredients')->where('id', $b['row'])->update(['quantity' => '150.01', 'quantity_grams' => '150.01']);
        $this->syncTotals($b['version']);
        $this->valid();
        $this->assertSame(2, (int) DB::selectOne('SELECT count(*) n FROM recipe_batch_quantities WHERE recipe_ingredient_id=? AND manual_context IS DISTINCT FROM recipe_batch_context(recipe_ingredient_id,format_code)', [$b['row']])->n);
        $this->assertSame(['1050.00', '525.00'], DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->orderByDesc('format_code')->pluck('quantity')->all());
        $this->rejected(fn () => $this->publish($b['version']), 'Manual needs review');
        DB::update("UPDATE recipe_batch_quantities SET mode='auto',manual_context=NULL,review_ack_hash=NULL,quantity=1050.07,quantity_grams=1050.07 WHERE recipe_ingredient_id=? AND format_code='week'", [$b['row']]);
        $this->syncTotals($b['version']);
        $this->valid();
        $this->assertSame('525.00', DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'half_week')->value('quantity'));
    }

    public function test_integrity_totals_auto_conversion_pending_revision_and_stale_inputs(): void
    {
        $this->install();
        $b = $this->batch();
        $this->valid();
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'week')->delete(), 'A row needs B/C');
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->update(['quantity' => 1]), 'Frozen grams mismatch');
        $this->rejected(fn () => DB::table('recipe_batch_formats')->where('id', $b['formats']['week'])->update(['kcal_total' => 1]), 'Totals/hash mismatch');
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->update(['quantity' => 1, 'quantity_grams' => 1]), 'Auto graph mismatch');
        $this->rejected(fn () => DB::table('recipe_versions')->where('id', $b['version'])->update(['title' => 'stale']), 'advance revision');
        $this->rejected(fn () => DB::select('SELECT recipe_batch_assert_revision(?,0)', [$b['version']]), 'Stale batch revision');
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'half_week')->update(['input_snapshot' => DB::raw("jsonb_set(input_snapshot,'{auto_policies}','{\"g\":null}')")]), 'Auto C needs accepted policy');
        DB::table('ingredients')->where('id', $b['ingredient'])->update(['kcal_per_100g' => '123.46']);
        $this->rejected(fn () => $this->publish($b['version']), 'Stale ingredient snapshot');
    }

    public function test_equipment_coverage_nullable_values_and_published_guards(): void
    {
        $this->install();
        $b = $this->batch();
        $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Synthetic pot']);
        $base = DB::table('recipe_kitchen_equipment')->insertGetId(['recipe_version_id' => $b['version'], 'kitchen_equipment_id' => $tool]);
        $this->rejected(fn () => $this->valid(), 'Missing equipment snapshot');
        foreach ($b['formats'] as $format) {
            DB::table('recipe_batch_equipment')->insert(['recipe_version_id' => $b['version'], 'format_id' => $format, 'recipe_kitchen_equipment_id' => $base, 'mode' => 'auto', 'input_snapshot' => '{"schema_version":1}']);
        }
        $this->valid();
        $this->publish($b['version']);
        $this->valid();
        $this->rejected(fn () => DB::table('recipe_batch_equipment')->where('recipe_version_id', $b['version'])->update(['tip' => 'changed']), 'immutable');
        $this->rejected(fn () => DB::table('recipe_batch_equipment')->where('recipe_version_id', $b['version'])->delete(), 'immutable');
        $this->rejected(fn () => DB::table('recipe_batch_equipment')->insert(['recipe_version_id' => $b['version'], 'format_id' => $b['formats']['single'], 'recipe_kitchen_equipment_id' => $base, 'mode' => 'auto', 'input_snapshot' => '{"schema_version":1}']), 'immutable');
    }

    public function test_b2_forward_functions_preserve_published_b1_equipment_without_backfill(): void
    {
        $this->install();
        $b = $this->batch();
        $unit = DB::table('uoms')->insertGetId(['code' => 'test-count-upgrade', 'label' => 'Synthetic count', 'kind' => 'count']);
        $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Synthetic upgrade pan']);
        $base = DB::table('recipe_kitchen_equipment')->insertGetId(['recipe_version_id' => $b['version'], 'kitchen_equipment_id' => $tool,
            'min_value' => '1.00', 'uom_id' => $unit, 'scaling_mode' => 'proportional']);
        foreach (['single' => '1.00', 'week' => '7.00', 'half_week' => '3.50'] as $code => $value) {
            DB::table('recipe_batch_equipment')->insert(['recipe_version_id' => $b['version'], 'format_id' => $b['formats'][$code],
                'recipe_kitchen_equipment_id' => $base, 'mode' => 'auto', 'min_value' => $value, 'uom_id' => $unit, 'input_snapshot' => '{"schema_version":1}']);
        }
        $this->publish($b['version']);
        $this->valid();
        $before = DB::table('recipe_batch_equipment')->where('recipe_version_id', $b['version'])->orderBy('format_id')->get()->toJson();
        $version = DB::table('recipe_versions')->find($b['version']);
        (require database_path('migrations/2026_10_24_020000_complete_recipe_equipment.php'))->up();
        $this->valid();
        $this->assertSame($before, DB::table('recipe_batch_equipment')->where('recipe_version_id', $b['version'])->orderBy('format_id')->get()->toJson());
        $this->assertEquals($version, DB::table('recipe_versions')->find($b['version']));
        $this->assertSame('4', DB::selectOne("SELECT recipe_batch_equipment_auto(1,?::smallint,'proportional','half_week') value", [$unit])->value);
        $this->rejected(fn () => DB::table('recipe_batch_equipment')->where('format_id', $b['formats']['half_week'])->update(['min_value' => '4.00']), 'immutable');
    }

    public function test_decimal_conversion_and_pending_override_storage(): void
    {
        $this->install();
        $b = $this->batch();
        $uom = DB::table('uoms')->insertGetId(['code' => 'synthetic-can', 'label' => 'Test can', 'kind' => 'count']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $b['ingredient'], 'uom_id' => $uom, 'grams_per_uom' => '33.33']);
        $input = json_encode(['schema_version' => 1, 'factor' => '33.33', 'uom_label' => 'Test can', 'rounding' => 'exact', 'step' => '0.01',
            'source_hash' => DB::selectOne('SELECT recipe_batch_source_hash(?) hash', [$b['ingredient']])->hash]);
        DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'week')->update(['mode' => 'manual', 'quantity' => '0.25', 'uom_id' => $uom, 'quantity_grams' => '8.33', 'input_snapshot' => $input, 'manual_context' => '{}']);
        DB::update("UPDATE recipe_batch_quantities SET mode='manual',manual_context='{}',quantity=4.17,quantity_grams=4.17,input_snapshot=jsonb_set(input_snapshot,'{source_hash}',to_jsonb(recipe_batch_source_hash(?))) WHERE recipe_ingredient_id=? AND format_code='half_week'", [$b['ingredient'], $b['row']]);
        $this->syncTotals($b['version']);
        $this->valid();
        $this->assertSame('5.147865', DB::table('recipe_batch_formats')->where('id', $b['formats']['half_week'])->value('kcal_total'));
        $pending = json_encode([['id' => 'pending:cleared-parent', 'quantity' => '', 'overrides' => ['week' => ['mode' => 'manual', 'quantity' => '0.25', 'uom_id' => (string) $uom, 'input_snapshot' => json_decode($input, true)]]]]);
        DB::update('UPDATE recipe_versions SET draft_pending_rows=?::jsonb,lock_version=lock_version+1 WHERE id=?', [$pending, $b['version']]);
        $this->valid();
        $this->assertEquals(json_decode($pending, true), json_decode(DB::table('recipe_versions')->where('id', $b['version'])->value('draft_pending_rows'), true));
        $unfinished = json_encode([['id' => 'pending:manual-incomplete', 'ingredient_id' => (string) $b['ingredient'], 'quantity' => '150.00', 'uom_id' => (string) $b['g'], 'overrides' => ['week' => ['mode' => 'manual', 'quantity' => '', 'uom_id' => (string) $uom]]]]);
        DB::update('UPDATE recipe_versions SET draft_pending_rows=?::jsonb,lock_version=lock_version+1 WHERE id=?', [$unfinished, $b['version']]);
        $this->valid();
        $this->assertEquals(json_decode($unfinished, true), json_decode(DB::table('recipe_versions')->where('id', $b['version'])->value('draft_pending_rows'), true));
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->update(['quantity_grams' => 'NaN']), 'check constraint');
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->update(['quantity' => '100000']), 'numeric field overflow');
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->update(['input_snapshot' => '{}']), 'check constraint');
    }

    #[DataProvider('inheritedCases')]
    public function test_inherited_uom_matches_php_storage_totals_and_publication(string $uom, string $quantity, string $factor, string $step, string $expected, string $grams): void
    {
        $this->install();
        $b = $this->batch();
        $this->addUom($b, $uom, $factor);
        $row = InheritedUomCases::row($uom, $quantity, $factor, $step);
        $out = $this->storeEvaluated($b, $row);
        $this->valid();
        $saved = DB::table('recipe_batch_quantities as q')->join('uoms as u', 'u.id', '=', 'q.uom_id')->where('q.recipe_ingredient_id', $b['row'])->where('format_code', 'half_week')->first(['q.*', 'u.code']);
        $this->assertSame($uom, $saved->code);
        $this->assertSame($expected, $saved->quantity);
        $this->assertSame($grams, $saved->quantity_grams);
        $row['half_week'] = ['mode' => $saved->mode, 'quantity' => $saved->quantity, 'uom' => $saved->code, 'policy' => json_decode($saved->input_snapshot, true)];
        $row['auto_policies'] = $row['half_week']['policy']['auto_policies'];
        $this->assertSame($out, (new BatchEvaluator)->evaluate([$b['row'] => json_decode(json_encode($row), true)]));
        foreach ($out['totals'] as $code => $totals) {
            $this->assertEquals($totals, json_decode(DB::selectOne('SELECT recipe_batch_totals(?,?) totals', [$b['version'], $code])->totals, true));
        }
        DB::update("UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_ingredient_id=? AND mode='manual'", [$b['row']]);
        $this->publish($b['version']);
        $this->valid();
    }

    public static function inheritedCases(): array
    {
        return InheritedUomCases::cases();
    }

    private function addUom(array $b, string $code, string $factor): int
    {
        $id = DB::table('uoms')->where('code', $code)->value('id') ?? DB::table('uoms')->insertGetId(['code' => $code, 'label' => $code, 'kind' => 'count']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $b['ingredient'], 'uom_id' => $id, 'grams_per_uom' => $factor]);

        return $id;
    }

    public function test_sql_rejects_stale_c_unit_factor_and_policy_even_when_grams_and_totals_match(): void
    {
        $this->install();
        $b = $this->batch();
        $this->addUom($b, 'piece', '100.00');
        $row = InheritedUomCases::row('piece', '3.00', '100.00', '1.00');
        $this->storeEvaluated($b, $row);
        $this->valid();
        DB::update("UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_ingredient_id=? AND mode='manual'", [$b['row']]);
        $c = DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'half_week');
        $this->rejected(function () use ($c, $b): void {
            // 150 г — математична половина B, але auto C має бути 2 шт./200 г.
            $c->update(['uom_id' => $b['g'], 'quantity' => '150.00', 'quantity_grams' => '150.00', 'input_snapshot' => DB::raw("input_snapshot || '{\"factor\":\"1.00\",\"uom_label\":\"gram\",\"rounding\":\"exact\",\"step\":\"0.01\"}'::jsonb")]);
            $this->syncTotals($b['version']);
            $this->publish($b['version']);
        }, 'Auto C must inherit B UOM and factor');
        $this->rejected(function () use ($c, $b): void {
            $c->update(['quantity' => '1.50', 'quantity_grams' => '150.00', 'input_snapshot' => DB::raw("jsonb_set(input_snapshot,'{step}','\"0.50\"')")]);
            $this->syncTotals($b['version']);
        }, 'Auto C inherited policy mismatch');
        $this->rejected(function () use ($c, $b): void {
            $c->update(['quantity_grams' => '400.00', 'input_snapshot' => DB::raw("jsonb_set(input_snapshot,'{factor}','\"200.00\"')")]);
            $this->syncTotals($b['version']);
        }, 'Auto C must inherit B UOM and factor');
        $this->rejected(fn () => $c->update(['input_snapshot' => DB::raw("input_snapshot-'auto_policies'")]), 'Auto C needs accepted policy');
        $this->rejected(fn () => $c->update(['input_snapshot' => DB::raw("jsonb_set(input_snapshot,'{auto_policies}','[]')")]), 'Invalid accepted auto policies');
        $this->rejected(fn () => $c->update(['quantity' => '1.00', 'quantity_grams' => '100.00']), 'Auto graph mismatch');
        $this->rejected(fn () => $c->update(['input_snapshot' => DB::raw("jsonb_set(jsonb_set(input_snapshot,'{confirmed}','false'),'{auto_policies,piece,confirmed}','false')")]), 'Unconfirmed rounding');
        $this->rejected(fn () => $c->update(['input_snapshot' => DB::raw("jsonb_set(jsonb_set(input_snapshot,'{step}','\"0.00\"'),'{auto_policies,piece,step}','\"0.00\"')")]), 'Invalid rounding');
    }

    public function test_b_uom_edit_requires_c_rebuild_and_manual_c_survives_reload_and_reset(): void
    {
        $this->install();
        $b = $this->batch();
        $this->addUom($b, 'piece', '100.00');
        $can = $this->addUom($b, 'test-can', '420.00');
        $row = InheritedUomCases::row('piece', '3.00', '100.00', '1.00');
        $this->storeEvaluated($b, $row);
        $this->valid();
        $this->rejected(fn () => DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'week')->update(['uom_id' => $can, 'quantity' => '1.00', 'quantity_grams' => '420.00', 'input_snapshot' => DB::raw("jsonb_set(input_snapshot,'{factor}','\"420.00\"')")]), 'Auto C must inherit B UOM and factor');
        $row['week']['uom'] = 'test-can';
        $row['week']['quantity'] = '1.00';
        $row['factors']['test-can'] = '420.00';
        $row['auto_policies']['test-can'] = ['rounding' => 'ceil', 'step' => '0.50', 'confirmed' => true];
        $out = $this->storeEvaluated($b, $row);
        $this->valid();
        $row['half_week'] = $out['rows'][$b['row']]['half_week'];
        $row['half_week']['mode'] = 'manual';
        $this->storeEvaluated($b, $row);
        DB::update("UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_ingredient_id=? AND format_code='half_week'", [$b['row']]);
        $before = DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'half_week')->first();
        $row['week']['uom'] = 'g';
        $row['week']['quantity'] = '1000.00';
        // Змінюється тільки B: жодної перезаписувальної команди для manual C.
        DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'week')->update(['uom_id' => $b['g'], 'quantity' => '1000.00', 'quantity_grams' => '1000.00', 'input_snapshot' => json_encode(['schema_version' => 1, 'factor' => '1.00', 'uom_label' => 'gram', 'rounding' => 'exact', 'step' => '0.01'])]);
        $this->syncTotals($b['version']);
        $this->valid();
        $after = DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $b['row'])->where('format_code', 'half_week')->first();
        $this->assertEquals($before, $after);
        $this->assertTrue(DB::selectOne("SELECT manual_context IS DISTINCT FROM recipe_batch_context(recipe_ingredient_id,format_code) review FROM recipe_batch_quantities WHERE recipe_ingredient_id=? AND format_code='half_week'", [$b['row']])->review);
        $row['half_week'] = (new BatchEvaluator)->reset($row['half_week'], ['uom' => 'stale-unit', 'policy' => ['rounding' => 'exact', 'step' => '0.01']]);
        $out = $this->storeEvaluated($b, $row);
        $this->valid();
        $this->assertSame('g', $out['rows'][$b['row']]['half_week']['uom']);
        $this->assertSame('500.00', $out['rows'][$b['row']]['half_week']['quantity']);
    }

    public function test_populated_historical_upgrade_preserves_recipes_plans_and_assignments(): void
    {
        // Legacy recipe fixture з baseline тесту, published plan та assignment.
        $fixture = file_get_contents(base_path('tests/pg/t04_assignments.sql'));
        $start = strpos($fixture, 'INSERT INTO uoms');
        $fixture = substr($fixture, $start, strpos($fixture, '-- T4.1') - $start);
        DB::unprepared('CREATE TEMP TABLE _ids (k text PRIMARY KEY,val integer) ON COMMIT DROP;'.$fixture);
        DB::unprepared("INSERT INTO client_meal_plan_assignments(client_id,meal_plan_version_id,start_date) SELECT (SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='ver_pub'),'2026-01-01';
            WITH r AS (INSERT INTO recipes(title) VALUES('Historical') RETURNING id) INSERT INTO recipe_versions(recipe_id,title) SELECT id,'Historical' FROM r;
            INSERT INTO recipe_ingredients(recipe_version_id,ingredient_id,quantity,uom_id,quantity_grams) SELECT (SELECT max(id) FROM recipe_versions),(SELECT max(id) FROM ingredients),100,(SELECT id FROM uoms WHERE code='g'),100;
            WITH f AS (INSERT INTO foods(food_type) VALUES('recipe_version') RETURNING id) UPDATE recipe_versions SET food_id=f.id,status='published',version_number=1,published_at=now(),kcal_per_serving=130,protein_per_serving=2.7,fat_per_serving=0.3,carbs_per_serving=28 FROM f WHERE recipe_versions.id=(SELECT max(id) FROM recipe_versions);");
        $this->valid();
        $tables = ['foods', 'recipe_versions', 'recipe_ingredients', 'meal_items', 'meal_plan_versions', 'client_meal_plan_assignments'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = DB::select('SELECT * FROM '.$t.' ORDER BY id');
        }
        $this->install();
        foreach ($tables as $t) {
            $columns = implode(',', array_keys((array) $before[$t][0]));
            $this->assertEquals($before[$t], DB::select('SELECT '.$columns.' FROM '.$t.' ORDER BY id'), $t);
        }
        $b = $this->batch();
        $this->publish($b['version']);
        $this->valid();
        $this->assertSame(0, DB::table('recipe_versions')->where('editor_contract', 'legacy_v1')->where('lock_version', '<>', 0)->count());
    }
}

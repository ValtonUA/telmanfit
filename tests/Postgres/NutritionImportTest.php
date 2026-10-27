<?php

namespace Tests\Postgres;

use App\Models\User;
use App\Services\Imports\LocalNutritionImporter;
use App\Services\Recipes\BatchEditor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Recipes\NutritionWorkbookFixture;

class NutritionImportTest extends PostgresTestCase
{
    use NutritionWorkbookFixture;

    private string $work;

    private string $file;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        foreach (['2026_10_24_010000_create_recipe_batches', '2026_10_24_020000_complete_recipe_equipment', '2026_10_24_030000_allow_explicit_legacy_conversion', '2026_10_25_010000_add_meal_item_recipe_selectors', '2026_10_25_020000_add_plan_presentation', '2026_10_26_000100_add_kitchen_equipment_image_url'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        config(['recipes.batch_editor_enabled' => true]);
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic import admin', 'role' => 'admin']));
        $this->work = sys_get_temp_dir().'/telmanfit-import-'.bin2hex(random_bytes(8));
        mkdir($this->work, 0700);
        $this->app->useStoragePath($this->work);
        $this->file = $this->work.'/source.xlsx';
        $this->writeWorkbook($this->file, $this->workbookRows());
    }

    protected function tearDown(): void
    {
        if (isset($this->work)) {
            File::deleteDirectory($this->work);
        }
        parent::tearDown();
    }

    public function test_dry_run_is_read_only_then_apply_uses_real_ids_and_server_snapshots_and_repeat_is_noop(): void
    {
        $importer = app(LocalNutritionImporter::class);
        $before = DB::table('users')->get()->toJson();
        $plan = $importer->inspect($this->file, 'v');
        $this->assertSame(0, DB::table('ingredients')->count());
        $this->assertSame($before, DB::table('users')->get()->toJson());
        $this->assertFileDoesNotExist($this->work.'/app/private/nutrition-import-v1');
        DB::select("SELECT setval(pg_get_serial_sequence('ingredients','id'),100,true)");
        $receipt = $importer->apply($this->file, 'v', $plan['sha256'], $this->actor);
        $this->assertSame(101, DB::table('ingredients')->value('id'));
        $id = $receipt['recipe_version_id'];
        $version = DB::table('recipe_versions')->find($id);
        $this->assertSame('draft', $version->status);
        $this->assertNull($version->food_id);
        $this->assertNull($version->publication_review);
        $this->assertSame(3, DB::table('recipe_batch_formats')->count());
        $this->assertSame(2, DB::table('recipe_batch_quantities')->count());
        $this->assertSame('10.00', DB::table('recipe_ingredients')->value('quantity_grams'));
        $this->assertNotSame('FORGED', DB::table('recipe_ingredients')->value('ingredient_snapshot'));
        $this->assertSame('100.00', DB::table('recipe_batch_quantities')->where('format_code', 'week')->value('quantity_grams'));
        $this->assertSame('50.00', DB::table('recipe_batch_quantities')->where('format_code', 'half_week')->value('quantity_grams'));
        $this->assertSame(0, DB::table('recipe_batch_quantities')->whereNotNull('review_ack_hash')->count());
        $text = DB::table('recipe_directions')->value('direction_text');
        $this->assertStringNotContainsString('ingredient:ri:', $text);
        $this->assertStringNotContainsString('equipment:base:', $text);
        $state = app(BatchEditor::class)->open($this->actor, $id);
        $preview = app(BatchEditor::class)->preview($this->actor, $id, $state['revision'], $state['payload']);
        $this->assertFalse($preview['evaluation']['incomplete']);
        $again = $importer->apply($this->file, 'v', $plan['sha256'], $this->actor);
        $this->assertTrue($again['already_imported']);
        $this->assertSame(1, DB::table('recipes')->count());
        $this->assertSame(1, DB::table('ingredients')->count());
    }

    public function test_late_database_constraint_failure_rolls_back_catalogs_and_recipe(): void
    {
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT synthetic_import_rollback CHECK(title <> 'Новий рецепт')");
        $importer = app(LocalNutritionImporter::class);
        try {
            $importer->apply($this->file, 'v', hash_file('sha256', $this->file), $this->actor);
            $this->fail('Expected DB guard');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(0, DB::table('ingredients')->count());
        $this->assertSame(0, DB::table('foods')->count());
        $this->assertSame(0, DB::table('recipes')->count());
        $this->assertSame([], glob($this->work.'/app/private/nutrition-import-v1/*.json'));
    }

    public function test_changed_file_hash_and_pending_ledger_refuse_without_writes(): void
    {
        $importer = app(LocalNutritionImporter::class);
        try {
            $importer->apply($this->file, 'v', str_repeat('0', 64), $this->actor);
            $this->fail('Expected hash refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SHA256', $e->getMessage());
        }
        $dir = $this->work.'/app/private/nutrition-import-v1';
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/'.hash_file('sha256', $this->file).'.json', json_encode(['status' => 'pending']));
        try {
            $importer->inspect($this->file, 'v');
            $this->fail('Expected pending refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Незавершений', $e->getMessage());
        }
        $this->assertSame(0, DB::table('recipes')->count());
    }

    public function test_cross_version_component_is_rejected_before_writes(): void
    {
        $rows = $this->workbookRows();
        $rows['recipe_components'][0]['recipe_version_id'] = 'other';
        $rows['recipe_versions'][] = array_replace($rows['recipe_versions'][0], ['id' => 'other']);
        $this->writeWorkbook($this->file, $rows);
        $this->expectExceptionMessage('Компонент належить іншій версії');
        app(LocalNutritionImporter::class)->inspect($this->file, 'v');
    }

    public function test_production_and_non_admin_actor_are_rejected(): void
    {
        $this->actor->role = 'client';
        $this->actor->save();
        try {
            app(LocalNutritionImporter::class)->apply($this->file, 'v', hash_file('sha256', $this->file), $this->actor);
            $this->fail('Expected actor refusal');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('ingredients')->count());
        $this->app->instance('env', 'production');
        $this->expectExceptionMessage('лише в дозволеному local');
        app(LocalNutritionImporter::class)->inspect($this->file, 'v');
    }

    public function test_foreign_batch_format_is_rejected_before_writes(): void
    {
        $rows = $this->workbookRows();
        $rows['recipe_versions'][] = array_replace($rows['recipe_versions'][0], ['id' => 'other']);
        $rows['recipe_batch_formats'][] = ['id' => 'foreign', 'recipe_version_id' => 'other', 'code' => 'week'];
        $rows['recipe_batch_quantities'][0]['format_id'] = 'foreign';
        $this->writeWorkbook($this->file, $rows);
        $this->expectExceptionMessage('Складене посилання');
        app(LocalNutritionImporter::class)->inspect($this->file, 'v');
    }

    public function test_two_ingredients_cannot_share_a_local_food_alias(): void
    {
        $rows = $this->workbookRows();
        $rows['ingredients'][] = array_replace($rows['ingredients'][0], ['id' => 'other']);
        $this->writeWorkbook($this->file, $rows);
        $this->expectExceptionMessage('рівно одного дочірнього');
        app(LocalNutritionImporter::class)->inspect($this->file, 'v');
    }
}

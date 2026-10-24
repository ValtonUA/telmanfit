<?php

namespace Tests\Recipes;

use App\Domain\Recipes\BatchEvaluator;
use Illuminate\Support\Facades\DB;
use Tests\Unit\BatchEvaluatorTest;

/** Лише synthetic fixture; жодних продуктових seed. */
trait BatchFixture
{
    private function batch(bool $withRow = true): array
    {
        $g = DB::table('uoms')->where('code', 'g')->value('id') ?? DB::table('uoms')->insertGetId(['code' => 'g', 'label' => 'gram', 'kind' => 'mass']);
        $recipe = DB::table('recipes')->insertGetId(['title' => 'Synthetic batch']);
        $version = DB::table('recipe_versions')->insertGetId(['recipe_id' => $recipe, 'title' => 'Synthetic batch', 'editor_contract' => 'batch_v1']);
        $formats = [];
        foreach (['single', 'week', 'half_week'] as $code) {
            $formats[$code] = DB::table('recipe_batch_formats')->insertGetId(['recipe_version_id' => $version, 'code' => $code, 'policy_snapshot' => '{"schema_version":1}', 'kcal_total' => 0, 'protein_total' => 0, 'fat_total' => 0, 'carbs_total' => 0, 'calculation_hash' => 'pending']);
        }
        $row = null;
        $ingredient = null;
        if ($withRow) {
            $food = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
            $ingredient = DB::table('ingredients')->insertGetId(['food_id' => $food, 'name' => 'Synthetic rice', 'kcal_per_100g' => '123.45', 'protein_per_100g' => '20.00', 'fat_per_100g' => '3.00', 'carbs_per_100g' => '4.00']);
            $snapshot = json_encode(['schema_version' => 1, 'name' => 'Synthetic rice', 'kcal' => '123.45', 'protein' => '20.00', 'fat' => '3.00', 'carbs' => '4.00']);
            $row = DB::table('recipe_ingredients')->insertGetId(['recipe_version_id' => $version, 'ingredient_id' => $ingredient, 'quantity' => '150.00', 'quantity_grams' => '150.00', 'uom_id' => $g, 'ingredient_snapshot' => $snapshot]);
            $evaluated = (new BatchEvaluator)->evaluate([1 => BatchEvaluatorTest::row()]);
            foreach (['week', 'half_week'] as $code) {
                $q = $evaluated['rows'][1][$code];
                DB::table('recipe_batch_quantities')->insert(['recipe_version_id' => $version, 'format_id' => $formats[$code], 'format_code' => $code, 'recipe_ingredient_id' => $row, 'mode' => 'auto', 'quantity' => $q['quantity'], 'quantity_grams' => $q['grams'], 'uom_id' => $g,
                    'input_snapshot' => json_encode(['schema_version' => 1, 'source_hash' => DB::selectOne('SELECT recipe_batch_source_hash(?) hash', [$ingredient])->hash, 'factor' => '1.00', 'uom_label' => 'gram', 'rounding' => 'exact', 'step' => '0.01'])]);
            }
        }
        $this->syncTotals($version);

        return compact('version', 'row', 'ingredient', 'g', 'formats');
    }

    private function syncTotals(int $version): void
    {
        DB::update("UPDATE recipe_batch_formats f SET (kcal_total,protein_total,fat_total,carbs_total,calculation_hash)=
            (SELECT (t->>'kcal')::numeric,(t->>'protein')::numeric,(t->>'fat')::numeric,(t->>'carbs')::numeric,md5(t::text)
            FROM (SELECT recipe_batch_totals(f.recipe_version_id,f.code) t) s) WHERE recipe_version_id=?", [$version]);
    }

    private function publish(int $version): void
    {
        $food = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
        DB::update("UPDATE recipe_versions v SET status='published',food_id=?,version_number=1,published_at=now(),lock_version=lock_version+1,
            publication_review=jsonb_build_object('complete',true,'lock_version',lock_version::text,'actor','synthetic-author','at',now()::text),
            (kcal_per_serving,protein_per_serving,fat_per_serving,carbs_per_serving)=
            (SELECT round(kcal_total,2),round(protein_total,2),round(fat_total,2),round(carbs_total,2) FROM recipe_batch_formats WHERE recipe_version_id=v.id AND code='single') WHERE v.id=?", [$food, $version]);
    }
}

<?php

namespace Tests\Recipes;

use ZipArchive;

/** Synthetic XLSX, жодних користувацьких файлів або каталогів. */
trait NutritionWorkbookFixture
{
    private function workbookRows(): array
    {
        $all = array_fill_keys(array_keys(json_decode(file_get_contents(resource_path('import/nutrition-v1.json')), true)), []);
        $all['ingredient_categories'] = [['id' => 'cat', 'name' => 'Synthetic category']];
        $all['recipe_categories'] = [['id' => 'rc', 'name' => 'Synthetic dish']];
        $all['uoms'] = [['id' => 'g', 'code' => 'g', 'label' => 'г', 'kind' => 'mass'], ['id' => 's', 'code' => 'serving', 'label' => 'порція', 'kind' => 'serving'], ['id' => 'pc', 'code' => 'piece', 'label' => 'шт', 'kind' => 'count'], ['id' => 'l', 'code' => 'litre', 'label' => 'л', 'kind' => 'volume']];
        $all['foods'] = [['id' => 'f', 'food_type' => 'ingredient']];
        $all['ingredients'] = [['id' => 'i', 'food_id' => 'f', 'food_type' => 'ingredient', 'name' => 'Synthetic food', 'ingredient_category_id' => 'cat', 'kcal_per_100g' => '100', 'protein_per_100g' => '10', 'fat_per_100g' => '0', 'carbs_per_100g' => '12']];
        $all['ingredient_uoms'] = [['ingredient_id' => 'i', 'uom_id' => 'pc', 'grams_per_uom' => '100', 'is_default' => 'FALSE']];
        $all['kitchen_equipment_categories'] = [['id' => 'ec', 'name' => 'Synthetic cookware']];
        $all['kitchen_equipment'] = [['id' => 'eq', 'name' => 'Synthetic pot', 'kitchen_equipment_category_id' => 'ec']];
        $all['recipes'] = [['id' => 'r', 'title' => 'Synthetic recipe', 'recipe_category_id' => 'rc']];
        $all['recipe_versions'] = [['id' => 'v', 'recipe_id' => 'r', 'status' => 'draft', 'food_type' => 'recipe_version', 'title' => 'Synthetic recipe', 'servings_count' => '1', 'editor_contract' => 'batch_v1']];
        $all['recipe_components'] = [['id' => 'cp', 'recipe_version_id' => 'v', 'title' => 'Base', 'sort_order' => '1']];
        $all['recipe_ingredients'] = [['id' => 'ri', 'recipe_version_id' => 'v', 'ingredient_id' => 'i', 'component_id' => 'cp', 'quantity' => '10', 'uom_id' => 'g', 'quantity_grams' => '999', 'sort_order' => '1', 'ingredient_snapshot' => 'FORGED']];
        $all['recipe_directions'] = [['id' => 'd', 'recipe_version_id' => 'v', 'direction_number' => '1', 'direction_text' => 'Взяти {{ingredient:ri:quantity}} та {{equipment:base:quantity}}.']];
        $all['recipe_kitchen_equipment'] = [['id' => 'base', 'recipe_version_id' => 'v', 'kitchen_equipment_id' => 'eq', 'min_value' => '0.5', 'uom_id' => 'l', 'scaling_mode' => 'proportional']];
        foreach (['single', 'week', 'half_week'] as $code) {
            $all['recipe_batch_formats'][] = ['id' => $code, 'recipe_version_id' => 'v', 'code' => $code, 'calculation_hash' => 'FORGED', 'kcal_total' => '999'];
            $all['recipe_batch_equipment'][] = ['recipe_version_id' => 'v', 'format_id' => $code, 'recipe_kitchen_equipment_id' => 'base', 'mode' => $code === 'single' ? 'auto' : 'manual', 'min_value' => $code === 'single' ? null : ($code === 'week' ? '2' : '1'), 'uom_id' => $code === 'single' ? null : 'l'];
        }
        foreach (['week' => '1', 'half_week' => '0.5'] as $code => $qty) {
            $all['recipe_batch_quantities'][] = ['recipe_version_id' => 'v', 'format_id' => $code, 'format_code' => $code, 'recipe_ingredient_id' => 'ri', 'mode' => 'manual', 'quantity' => $qty, 'uom_id' => 'pc', 'quantity_grams' => '999', 'input_snapshot' => 'FORGED', 'review_ack_hash' => 'FORGED'];
        }

        return $all;
    }

    private function writeWorkbook(string $file, array $rows): void
    {
        $schema = json_decode(file_get_contents(resource_path('import/nutrition-v1.json')), true);
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $sheets = $rels = '';
        $escape = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $column = function (int $i): string {
            $s = '';
            for ($i++; $i; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26).$s;
            }

            return $s;
        };
        $n = 0;
        foreach ($schema as $table => $spec) {
            $n++;
            $sheets .= '<sheet name="'.$table.'" sheetId="'.$n.'" r:id="r'.$n.'"/>';
            $rels .= '<Relationship Id="r'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            $matrix = [$spec['columns']];
            foreach ($rows[$table] ?? [] as $row) {
                $matrix[] = array_map(fn ($c) => $row[$c] ?? null, $spec['columns']);
            }
            foreach ($matrix as $r => $values) {
                $xml .= '<row r="'.($r + 1).'">';
                foreach ($values as $c => $value) {
                    if ($value !== null) {
                        $xml .= '<c r="'.$column($c).($r + 1).'" t="inlineStr"><is><t>'.$escape($value).'</t></is></c>';
                    }
                }
                $xml .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $xml.'</sheetData></worksheet>');
        }
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->close();
    }
}

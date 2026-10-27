<?php

namespace App\Services\Imports;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class NutritionWorkbook
{
    public function __construct(public readonly string $sha256, public readonly array $sheets, public readonly array $schema) {}

    public static function load(string $path): self
    {
        $schema = json_decode(file_get_contents(resource_path('import/nutrition-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($schema as $table => $spec) {
            if (Schema::getColumnListing($table) !== $spec['columns']) {
                throw new RuntimeException("Схема БД не відповідає XLSX-v1: $table.");
            }
        }
        $source = app(NutritionXlsxReader::class)->read($path, array_map(fn ($t) => $t['columns'], $schema));
        $book = new self($source['sha256'], $source['sheets'], $schema);
        $book->validate();

        return $book;
    }

    public function rows(string $table): array
    {
        return $this->sheets[$table] ?? [];
    }

    public function find(string $table, string $id): array
    {
        foreach ($this->rows($table) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        throw new RuntimeException("Невідомий локальний ID $table:$id");
    }

    public function versionRows(string $table, string $version): array
    {
        $rows = array_values(array_filter($this->rows($table), fn ($r) => $r['recipe_version_id'] === $version));
        $order = match ($table) {
            'recipe_directions' => 'direction_number',
            'recipe_ingredients', 'recipe_components' => 'sort_order',
            default => '_row',
        };
        usort($rows, fn ($a, $b) => (int) ($a[$order] ?? 0) <=> (int) ($b[$order] ?? 0));

        return $rows;
    }

    public function quantity(string|int|float|null $value, bool $zero = false): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new RuntimeException('Потрібне звичайне десяткове число до 99999.99, без ком і формул.');
        }
        $n = BigDecimal::of($value)->toScale(2, RoundingMode::Unnecessary);
        if ($zero ? $n->isNegative() : ! $n->isGreaterThan(0)) {
            throw new RuntimeException('Неприпустиме нульове або від’ємне значення.');
        }

        return (string) $n;
    }

    public function batch(string $table, string $version, string $rowId, string $code): array
    {
        $key = $table === 'recipe_batch_quantities' ? 'recipe_ingredient_id' : 'recipe_kitchen_equipment_id';
        foreach ($this->versionRows($table, $version) as $row) {
            $format = $this->find('recipe_batch_formats', $row['format_id']);
            if ($row[$key] === $rowId && $format['code'] === $code) {
                return $row;
            }
        }
        throw new RuntimeException("Немає $table $version/$rowId/$code");
    }

    private function validate(): void
    {
        if ($this->rows('_external_refs')) {
            throw new RuntimeException('v1 не імпортує зовнішні посилання або клієнтські призначення.');
        }
        $ids = [];
        foreach ($this->schema as $table => $spec) {
            $seen = [];
            foreach ($this->rows($table) as $r) {
                $parts = [];
                foreach ($spec['primary'] as $key) {
                    if ($r[$key] === null || mb_strlen($r[$key]) > 128 || preg_match('/[\x00-\x1f\x7f]/', $r[$key])) {
                        throw new RuntimeException("$table:{$r['_row']}: відсутній/неприпустимий ключ $key.");
                    }
                    $parts[] = $r[$key];
                }
                $key = json_encode($parts);
                if (isset($seen[$key])) {
                    throw new RuntimeException("$table: повторний ключ.");
                }
                $seen[$key] = true;
                if (array_key_exists('id', $r)) {
                    $ids[$table][$r['id']] = true;
                }
            }
        }
        foreach (['recipe_batch_quantities' => 'recipe_ingredients', 'recipe_batch_equipment' => 'recipe_kitchen_equipment'] as $table => $base) {
            $column = $table === 'recipe_batch_quantities' ? 'recipe_ingredient_id' : 'recipe_kitchen_equipment_id';
            foreach ($this->rows($table) as $r) {
                $format = $this->find('recipe_batch_formats', $r['format_id']);
                if ($format['recipe_version_id'] !== $r['recipe_version_id'] || $this->find($base, $r[$column])['recipe_version_id'] !== $r['recipe_version_id']
                    || ($table === 'recipe_batch_quantities' && $r['format_code'] !== $format['code'])) {
                    throw new RuntimeException('Складене посилання формату/рядка належить іншій версії або code.');
                }
            }
        }
        foreach ($this->schema as $table => $spec) {
            foreach ($this->rows($table) as $r) {
                foreach ($spec['references'] as $column => $target) {
                    if ($r[$column] !== null && ! isset($ids[$target][$r[$column]])) {
                        throw new RuntimeException("$table:{$r['_row']}: невідоме посилання $column={$r[$column]}.");
                    }
                }
            }
        }
        foreach (['ingredient_categories' => 'name', 'recipe_categories' => 'name', 'uoms' => 'code', 'kitchen_equipment_categories' => 'name', 'kitchen_equipment' => 'name', 'meal_types' => 'code'] as $t => $column) {
            $values = array_column($this->rows($t), $column);
            if (in_array(null, $values, true) || count($values) !== count(array_unique($values))) {
                throw new RuntimeException("$t: порожнє або повторне $column.");
            }
        }
        foreach (['recipe_batch_formats' => ['recipe_version_id', 'code'], 'recipe_kitchen_equipment' => ['recipe_version_id', 'kitchen_equipment_id'],
            'recipe_directions' => ['recipe_version_id', 'direction_number'], 'meal_plan_days' => ['meal_plan_version_id', 'day_number'],
            'meals' => ['meal_plan_day_id', 'meal_type_id']] as $t => $columns) {
            $seen = [];
            foreach ($this->rows($t) as $r) {
                $key = json_encode(array_map(fn ($c) => $r[$c], $columns));
                if (isset($seen[$key])) {
                    throw new RuntimeException("$t: повторна унікальна комбінація.");
                }
                $seen[$key] = true;
            }
        }
        $children = [];
        foreach (['ingredients' => 'ingredient', 'recipe_versions' => 'recipe_version'] as $t => $type) {
            foreach ($this->rows($t) as $r) {
                if ($r['food_id'] === null) {
                    continue;
                }
                if (isset($children[$r['food_id']]) || $this->find('foods', $r['food_id'])['food_type'] !== $type) {
                    throw new RuntimeException('Food alias потребує рівно одного дочірнього запису правильного типу.');
                }
                $children[$r['food_id']] = true;
            }
        }
        foreach ($this->rows('foods') as $r) {
            if (! isset($children[$r['id']])) {
                throw new RuntimeException('Food alias без дочірнього запису.');
            }
        }
    }

    public function validateVersion(string $version): void
    {
        $v = $this->find('recipe_versions', $version);
        $card = $this->find('recipes', $v['recipe_id']);
        if ($v['status'] !== 'draft' || $v['editor_contract'] !== 'batch_v1' || $this->quantity($v['servings_count']) !== '1.00'
            || $v['source_recipe_version_id'] !== null || $card['owner_client_id'] !== null || $card['parent_recipe_id'] !== null
            || ! $card['recipe_category_id'] || $card['title'] !== $v['title']) {
            throw new RuntimeException('v1 створює лише новий спільний batch_v1 draft з A/1 та однаковими назвами картки/версії.');
        }
        $formats = $this->versionRows('recipe_batch_formats', $version);
        $codes = array_column($formats, 'code');
        sort($codes);
        if ($codes !== ['half_week', 'single', 'week']) {
            throw new RuntimeException('Потрібно рівно три формати single/week/half_week.');
        }
        $rows = $this->versionRows('recipe_ingredients', $version);
        if (! $rows || count($this->versionRows('recipe_batch_quantities', $version)) !== count($rows) * 2) {
            throw new RuntimeException('Кожен інгредієнт A потребує рівно B та C.');
        }
        $tokens = ['ingredient' => [], 'equipment' => []];
        foreach ($rows as $r) {
            $tokens['ingredient'][$r['id']] = true;
            if ($this->find('uoms', $r['uom_id'])['code'] !== 'g' || ! $this->find('ingredients', $r['ingredient_id'])['ingredient_category_id']) {
                throw new RuntimeException('A потребує g та категорії інгредієнта.');
            }
            $this->quantity($r['quantity']);
            if ($r['component_id'] !== null && $this->find('recipe_components', $r['component_id'])['recipe_version_id'] !== $version) {
                throw new RuntimeException('Компонент належить іншій версії.');
            }
            foreach (['week', 'half_week'] as $code) {
                $q = $this->batch('recipe_batch_quantities', $version, $r['id'], $code);
                if ($q['format_code'] !== $code || ! in_array($q['mode'], ['auto', 'manual'], true)) {
                    throw new RuntimeException('Невідповідний формат або mode.');
                }
                if ($q['mode'] === 'manual') {
                    $this->quantity($q['quantity']);
                    $unit = $this->find('uoms', (string) $q['uom_id']);
                    if ($unit['code'] !== 'g' && ! array_filter($this->rows('ingredient_uoms'), fn ($x) => $x['ingredient_id'] === $r['ingredient_id'] && $x['uom_id'] === $q['uom_id'])) {
                        throw new RuntimeException('Немає конверсії для manual пари.');
                    }
                } elseif ($q['uom_id'] !== null && $this->find('uoms', $q['uom_id'])['code'] !== 'g') {
                    throw new RuntimeException('v1 auto підтримує лише exact g; інші auto policies потрібно погодити окремо.');
                }
            }
            $b = $this->batch('recipe_batch_quantities', $version, $r['id'], 'week');
            $c = $this->batch('recipe_batch_quantities', $version, $r['id'], 'half_week');
            if ($c['mode'] === 'auto' && $b['mode'] === 'manual' && $this->find('uoms', $b['uom_id'])['code'] !== 'g') {
                throw new RuntimeException('Auto C після non-g B потребує окремої погодженої policy; v1 її не вигадує.');
            }
        }
        $equipment = $this->versionRows('recipe_kitchen_equipment', $version);
        if (count($this->versionRows('recipe_batch_equipment', $version)) !== count($equipment) * 3) {
            throw new RuntimeException('Кожна вимога обладнання потребує трьох форматів.');
        }
        foreach ($equipment as $r) {
            $tokens['equipment'][$r['id']] = true;
            if (! in_array($r['scaling_mode'], ['fixed', 'proportional'], true) || mb_strlen($r['tip'] ?? '') > 500) {
                throw new RuntimeException('Неприпустимий scaling_mode або завелика порада обладнання.');
            }
            if ($r['min_value'] !== null) {
                $this->quantity($r['min_value']);
            }
            foreach (['single', 'week', 'half_week'] as $code) {
                $q = $this->batch('recipe_batch_equipment', $version, $r['id'], $code);
                if (($code === 'single' && $q['mode'] !== 'auto') || ! in_array($q['mode'], ['auto', 'manual'], true)) {
                    throw new RuntimeException('Обладнання A має використовувати baseline auto; B/C — auto/manual.');
                }
                if ($q['mode'] === 'manual' && $q['min_value'] !== null) {
                    $this->quantity($q['min_value']);
                }
            }
        }
        $directions = $this->versionRows('recipe_directions', $version);
        if (! $directions || array_map(fn ($r) => (int) $r['direction_number'], $directions) !== range(1, count($directions))) {
            throw new RuntimeException('Кроки повинні мати суцільні номери 1..N.');
        }
        foreach ($directions as $r) {
            preg_match_all('/\{\{([^}]+)\}\}/', $r['direction_text'] ?? '', $matches);
            foreach ($matches[1] as $token) {
                $parts = explode(':', $token);
                $field = array_pop($parts);
                $type = array_shift($parts);
                $id = implode(':', $parts);
                $fields = $type === 'ingredient' ? ['quantity', 'grams'] : ['quantity', 'min_value', 'uom', 'tip', 'name'];
                if (! isset($tokens[$type][$id]) || ! in_array($field, $fields, true)) {
                    throw new RuntimeException('Непідтримуваний/невідомий token: '.$token);
                }
            }
        }
    }
}

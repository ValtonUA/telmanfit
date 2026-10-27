<?php

namespace App\Services\Imports;

use App\Models\Catalog\Ingredient;
use App\Models\Catalog\IngredientCategory;
use App\Models\Catalog\KitchenEquipment;
use App\Models\Catalog\KitchenEquipmentCategory;
use App\Models\Catalog\RecipeCategory;
use App\Models\Catalog\Uom;
use App\Models\User;
use App\Services\Auth\Restore\RestoreFence;
use App\Services\Catalog\CatalogWriter;
use App\Services\Recipes\BatchEditor;
use App\Services\Recipes\RecipeTokens;
use App\Support\Presentation\PublicImageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Local-only, нові каталоги + одна нова чернетка за запуск. Без publish/ack. */
final class LocalNutritionImporter
{
    private const CATALOGS = [
        'ingredient_categories', 'recipe_categories', 'uoms', 'kitchen_equipment_categories',
        'kitchen_equipment', 'ingredients', 'meal_types',
    ];

    public function __construct(private CatalogWriter $catalog, private BatchEditor $editor) {}

    public function inspect(string $path, string $version): array
    {
        $this->target();
        $book = NutritionWorkbook::load($path);
        $book->validateVersion($version);
        $this->validateCatalogs($book);
        $ledger = $this->readLedger($book);
        if ($ledger) {
            $this->validateLedger($ledger);
        } else {
            $this->emptyTarget();
        }

        return [
            'mode' => 'dry-run', 'sha256' => $book->sha256, 'local_version' => $version,
            'title' => $book->find('recipe_versions', $version)['title'],
            'catalogs' => array_combine(self::CATALOGS, array_map(fn ($t) => count($book->rows($t)), self::CATALOGS)),
            'ingredients_in_recipe' => count($book->versionRows('recipe_ingredients', $version)),
            'directions' => count($book->versionRows('recipe_directions', $version)),
            'equipment' => count($book->versionRows('recipe_kitchen_equipment', $version)),
            'already_imported' => isset($ledger['versions'][$version]),
            'excluded' => 'Інші рецепти, раціони, призначення та зовнішні посилання не записуються. Службові значення Excel не копіюються.',
        ];
    }

    public function apply(string $path, string $version, string $expectedHash, User $actor): array
    {
        $report = $this->inspect($path, $version);
        if (! hash_equals($report['sha256'], $expectedHash)) {
            throw new RuntimeException('SHA256 не відповідає попередньому dry-run.');
        }
        $book = NutritionWorkbook::load($path);
        if (! hash_equals($book->sha256, $expectedHash)) {
            throw new RuntimeException('Файл змінився після dry-run.');
        }
        $this->editor->authorize($actor);
        if (! CatalogWriter::allowed($actor)) {
            throw new RuntimeException('Потрібний дозволений локальний admin.');
        }
        $dir = $this->directory();
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Не вдалося створити приватний журнал імпорту.');
        }
        $lock = fopen($dir.'/writer.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Інший імпорт уже працює.');
        }
        try {
            $result = DB::transaction(function () use ($book, $version, $actor) {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('telmanfit-nutrition-import-v1'))");
                $this->target();
                $ledger = $this->readLedger($book);
                if ($ledger) {
                    $this->validateLedger($ledger);
                    if (isset($ledger['versions'][$version])) {
                        return $ledger + ['already_imported' => true];
                    }
                    foreach (self::CATALOGS as $table) {
                        DB::table($table)->orderBy('id')->sharedLock()->get();
                    }
                    if (! hash_equals($ledger['catalog_fingerprint'], $this->fingerprint())) {
                        throw new RuntimeException('Каталоги після імпорту змінилися. Потрібне окреме зіставлення, перезапис заборонений.');
                    }
                } else {
                    $this->emptyTarget();
                    $ledger = ['status' => 'complete', 'database' => $this->identity(), 'sha256' => $book->sha256,
                        'maps' => $this->catalogs($book, $actor), 'versions' => []];
                    $ledger['catalog_fingerprint'] = $this->fingerprint();
                }
                $ledger['versions'][$version] = $this->recipe($book, $version, $ledger['maps'], $actor);
                // Constraint errors roll back the complete aggregate, including catalogs.
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
                $ledger['status'] = 'pending';
                $this->writeLedger($book, $ledger);

                return $ledger;
            });
            if ($result['already_imported'] ?? false) {
                return ['already_imported' => true] + $result['versions'][$version];
            }
            // If COMMIT or this final rename is interrupted, pending fails closed
            // on the next run. It must never cause a blind duplicate insertion.
            $result['status'] = 'complete';
            $this->writeLedger($book, $result);

            return ['already_imported' => false] + $result['versions'][$version];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function target(): void
    {
        $test = app()->environment('testing') && getenv('TELMANFIT_PG_TEST') === '1';
        if ((! app()->environment('local') || config('local_compose.enabled') !== true) && ! $test) {
            throw new RuntimeException('Імпортер доступний лише в дозволеному local Compose.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Потрібна PostgreSQL 18.');
        }
        $identity = DB::selectOne("SELECT current_database() db, current_user role, current_setting('server_version_num')::int version,
            (SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname=current_user) privileged");
        $valid = $test
            ? $identity->db === 'telmanfit_stage1_test' && $identity->role === 'telmanfit_test_runner'
            : str_starts_with($identity->db, 'telmanfit_local_') && $identity->role === 'telmanfit_local_runner';
        if (! $valid || $identity->privileged || intdiv($identity->version, 10000) !== 18) {
            throw new RuntimeException('Ідентичність або права цільової БД не відповідають local/test контракту.');
        }
        app(RestoreFence::class)->assert();
    }

    private function identity(): string
    {
        return DB::selectOne('SELECT current_database() name')->name;
    }

    private function emptyTarget(): void
    {
        foreach (array_merge(self::CATALOGS, ['foods', 'recipes', 'recipe_versions']) as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Харчова БД уже має дані без журналу цього файлу. Автоматичний merge/зіставлення за назвою заборонений.');
            }
        }
    }

    private function validateCatalogs(NutritionWorkbook $b): void
    {
        foreach ($b->rows('uoms') as $r) {
            if (! $r['label'] || ! in_array($r['kind'], ['mass', 'volume', 'count', 'serving', 'other'], true)
                || ($r['code'] === 'g' && $r['kind'] !== 'mass') || ($r['code'] === 'serving' && $r['kind'] !== 'serving')) {
                throw new RuntimeException('Невалідна одиниця вимірювання.');
            }
        }
        foreach (['g', 'serving'] as $code) {
            if (! in_array($code, array_column($b->rows('uoms'), 'code'), true)) {
                throw new RuntimeException("Немає системної одиниці $code.");
            }
        }
        $defaults = [];
        foreach ($b->rows('ingredient_uoms') as $r) {
            $b->quantity($r['grams_per_uom']);
            if ($b->find('uoms', $r['uom_id'])['code'] === 'g' || ! in_array($r['is_default'], [null, 'TRUE', 'FALSE'], true)) {
                throw new RuntimeException('g — неявна базова одиниця; is_default має бути TRUE/FALSE.');
            }
            if ($r['is_default'] === 'TRUE' && isset($defaults[$r['ingredient_id']])) {
                throw new RuntimeException('Більше одного default для інгредієнта.');
            }
            if ($r['is_default'] === 'TRUE') {
                $defaults[$r['ingredient_id']] = true;
            }
        }
        foreach ($b->rows('ingredients') as $r) {
            if (! $r['name'] || $r['food_type'] !== 'ingredient' || $b->find('foods', $r['food_id'])['food_type'] !== 'ingredient') {
                throw new RuntimeException('Інгредієнт потребує назви й типізованого food.');
            }
            foreach (['kcal', 'protein', 'fat', 'carbs'] as $n) {
                $b->quantity($r[$n.'_per_100g'], true);
            }
        }
        foreach (['ingredients', 'recipes', 'kitchen_equipment', 'recipe_directions'] as $t) {
            foreach ($b->rows($t) as $r) {
                Validator::make(['image_url' => $r['image_url']], ['image_url' => ['nullable', new PublicImageUrl]])->validate();
            }
        }
    }

    private function catalogs(NutritionWorkbook $b, User $actor): array
    {
        $map = [];
        foreach (['ingredient_categories' => IngredientCategory::class, 'recipe_categories' => RecipeCategory::class,
            'uoms' => Uom::class, 'kitchen_equipment_categories' => KitchenEquipmentCategory::class,
            'kitchen_equipment' => KitchenEquipment::class, 'ingredients' => Ingredient::class] as $table => $class) {
            foreach ($b->rows($table) as $r) {
                $model = new $class;
                $input = array_intersect_key($r, array_flip($model->getFillable()));
                foreach (['ingredient_category_id' => 'ingredient_categories', 'kitchen_equipment_category_id' => 'kitchen_equipment_categories'] as $fk => $target) {
                    if (array_key_exists($fk, $input)) {
                        $input[$fk] = $input[$fk] === null ? null : $map[$target][$input[$fk]];
                    }
                }
                if ($table === 'ingredients') {
                    foreach (['kcal', 'protein', 'fat', 'carbs'] as $n) {
                        $input[$n.'_per_100g'] = $b->quantity($r[$n.'_per_100g'], true);
                    }
                    $input['conversions'] = [];
                    foreach ($b->rows('ingredient_uoms') as $c) {
                        if ($c['ingredient_id'] === $r['id']) {
                            $input['conversions'][] = ['uom_id' => $map['uoms'][$c['uom_id']], 'grams_per_uom' => $b->quantity($c['grams_per_uom']), 'is_default' => $c['is_default'] === 'TRUE'];
                        }
                    }
                }
                $record = $this->catalog->save($actor, $model, $input);
                $map[$table][$r['id']] = $record->id;
                if ($table === 'ingredients') {
                    $map['foods'][$r['food_id']] = $record->food_id;
                }
            }
        }
        foreach ($b->rows('meal_types') as $r) {
            $map['meal_types'][$r['id']] = DB::table('meal_types')->insertGetId(['code' => $r['code'], 'title' => $r['title'], 'sort_order' => $r['sort_order'] ?? 0]);
        }

        return $map;
    }

    private function recipe(NutritionWorkbook $b, string $version, array $map, User $actor): array
    {
        $source = $b->find('recipe_versions', $version);
        $card = $b->find('recipes', $source['recipe_id']);
        $id = $this->editor->create($actor);
        $state = $this->editor->open($actor, $id);
        $payload = ['title' => $source['title'], 'category_id' => (string) $map['recipe_categories'][$card['recipe_category_id']], 'components' => [], 'rows' => [], 'equipment' => [], 'directions' => []];
        $rowIds = $toolIds = $componentIds = [];
        foreach ($b->versionRows('recipe_components', $version) as $r) {
            $componentIds[$r['id']] = 'new-import-'.substr(hash('sha256', $r['id']), 0, 24);
            $payload['components'][] = ['id' => $componentIds[$r['id']], 'title' => $r['title']];
        }
        foreach ($b->versionRows('recipe_ingredients', $version) as $r) {
            $key = $rowIds[$r['id']] = 'pending:import-'.substr(hash('sha256', $r['id']), 0, 24);
            $row = ['id' => $key, 'ingredient_id' => (string) $map['ingredients'][$r['ingredient_id']], 'quantity' => $b->quantity($r['quantity']),
                'component_id' => $componentIds[$r['component_id']] ?? '', 'auto_policies' => []];
            foreach (['week', 'half_week'] as $code) {
                $q = $b->batch('recipe_batch_quantities', $version, $r['id'], $code);
                $row[$code] = ['mode' => $q['mode'], 'quantity' => $q['mode'] === 'manual' ? $b->quantity($q['quantity']) : '',
                    'uom_id' => $q['mode'] === 'manual' ? (string) $map['uoms'][$q['uom_id']] : '', 'rounding' => 'exact', 'step' => '0.01'];
            }
            $payload['rows'][] = $row;
        }
        foreach ($b->versionRows('recipe_kitchen_equipment', $version) as $r) {
            $key = $toolIds[$r['id']] = 'new-import-'.substr(hash('sha256', $r['id']), 0, 24);
            $tool = ['id' => $key, 'kitchen_equipment_id' => (string) $map['kitchen_equipment'][$r['kitchen_equipment_id']],
                'min_value' => $r['min_value'] === null ? '' : $b->quantity($r['min_value']), 'uom_id' => $r['uom_id'] === null ? '' : (string) $map['uoms'][$r['uom_id']],
                'scaling_mode' => $r['scaling_mode'], 'tip' => $r['tip'] ?? ''];
            foreach (['week', 'half_week'] as $code) {
                $q = $b->batch('recipe_batch_equipment', $version, $r['id'], $code);
                $tool[$code] = ['mode' => $q['mode'], 'min_value' => $q['min_value'] === null ? '' : $b->quantity($q['min_value']),
                    'uom_id' => $q['uom_id'] === null ? '' : (string) $map['uoms'][$q['uom_id']], 'tip' => $q['tip'] ?? ''];
            }
            $payload['equipment'][] = $tool;
        }
        foreach ($b->versionRows('recipe_directions', $version) as $r) {
            $payload['directions'][] = RecipeTokens::remap($r['direction_text'], $rowIds, $toolIds);
        }
        $preview = $this->editor->preview($actor, $id, $state['revision'], $payload);
        $saved = $this->editor->save($actor, $id, $state['revision'], $payload, $preview['token'], acceptDiff: true, acknowledge: false);
        foreach ($b->versionRows('recipe_ingredients', $version) as $r) {
            DB::table('recipe_ingredients')->where('id', $saved['row_map'][$rowIds[$r['id']]])->update(['sort_order' => $r['sort_order'] ?? 0]);
        }
        $recipeId = DB::table('recipe_versions')->where('id', $id)->value('recipe_id');
        if ($card['image_url'] !== null) {
            DB::table('recipes')->where('id', $recipeId)->update(['image_url' => $card['image_url']]);
        }
        foreach ($b->versionRows('recipe_directions', $version) as $r) {
            if ($r['image_url'] !== null) {
                DB::table('recipe_directions')->where('recipe_version_id', $id)->where('direction_number', $r['direction_number'])->update(['image_url' => $r['image_url']]);
            }
        }
        DB::select('SELECT recipe_batch_validate(?)', [$id]);

        return ['recipe_id' => $recipeId, 'recipe_version_id' => $id, 'status' => 'draft',
            'row_map' => array_combine(array_keys($rowIds), array_map(fn ($pending) => $saved['row_map'][$pending], array_values($rowIds))),
            'editor' => '/admin/recipe-batches?version='.$id];
    }

    private function directory(): string
    {
        return storage_path('app/private/nutrition-import-v1');
    }

    private function readLedger(NutritionWorkbook $book): ?array
    {
        $file = $this->directory().'/'.$book->sha256.'.json';
        if (! is_file($file)) {
            return null;
        }

        return json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    private function validateLedger(array $ledger): void
    {
        if (($ledger['status'] ?? null) !== 'complete' || ($ledger['database'] ?? null) !== $this->identity()) {
            throw new RuntimeException('Незавершений або чужий журнал імпорту. Потрібна перевірка COMMIT; повторний запис заборонено.');
        }
        foreach ($ledger['versions'] as $receipt) {
            if (! DB::table('recipe_versions')->where('id', $receipt['recipe_version_id'])->where('recipe_id', $receipt['recipe_id'])->exists()) {
                throw new RuntimeException('Журнал не відповідає БД. Автоматичний повтор заборонено.');
            }
        }
    }

    private function writeLedger(NutritionWorkbook $book, array $ledger): void
    {
        $file = $this->directory().'/'.$book->sha256.'.json';
        $tmp = $file.'.tmp';
        if (file_put_contents($tmp, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Не вдалося записати журнал імпорту.');
        }
        chmod($tmp, 0600);
        if (! rename($tmp, $file)) {
            throw new RuntimeException('Не вдалося завершити журнал. Перевірте стан імпорту перед повтором.');
        }
    }

    private function fingerprint(): string
    {
        $data = [];
        foreach (array_merge(self::CATALOGS, ['ingredient_uoms']) as $t) {
            $query = DB::table($t);
            $query = $t === 'ingredient_uoms' ? $query->orderBy('ingredient_id')->orderBy('uom_id') : $query->orderBy('id');
            $data[$t] = $query->get()->all();
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}

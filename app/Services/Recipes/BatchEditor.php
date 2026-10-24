<?php

namespace App\Services\Recipes;

use App\Domain\Recipes\BatchEvaluator;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Єдиний writer агрегату; browser ніколи не постачає totals або snapshots. */
final class BatchEditor
{
    private const CODES = ['single', 'week', 'half_week'];

    public function authorize(?User $actor): void
    {
        abort_unless(config('recipes.batch_editor_enabled') && $actor?->exists
            && User::query()->whereKey($actor->id)->value('role') === User::ROLE_ADMIN, 403);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['batch' => $message]);
    }

    public function create(User $actor): int
    {
        $this->authorize($actor);

        return DB::transaction(function () {
            $recipe = DB::table('recipes')->insertGetId(['title' => 'Новий рецепт']);
            $version = DB::table('recipe_versions')->insertGetId(['recipe_id' => $recipe, 'title' => 'Новий рецепт', 'editor_contract' => 'batch_v1']);
            foreach (self::CODES as $code) {
                DB::table('recipe_batch_formats')->insert(['recipe_version_id' => $version, 'code' => $code, 'policy_snapshot' => '{"schema_version":1}',
                    'kcal_total' => 0, 'protein_total' => 0, 'fat_total' => 0, 'carbs_total' => 0, 'calculation_hash' => 'pending']);
            }
            $this->totals($version);
            DB::select('SELECT recipe_batch_validate(?)', [$version]);

            return $version;
        });
    }

    /** Копіює accepted aggregate без обчислень за поточними довідниками. */
    public function createVersion(User $actor, int $sourceId, string $revision): int
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($sourceId, $revision) {
            $recipeId = DB::table('recipe_versions')->where('id', $sourceId)->value('recipe_id');
            abort_unless($recipeId, 404);
            // Той самий порядок, що й Save/Publish; серіалізує конкурентні clone.
            DB::table('recipes')->where('id', $recipeId)->lockForUpdate()->first();
            $source = DB::table('recipe_versions')->where('id', $sourceId)->lockForUpdate()->first();
            abort_unless($source, 404);
            if ($source->status !== 'published' || $source->editor_contract !== 'batch_v1'
                || (string) $source->lock_version !== $revision) {
                $this->fail('Оберіть опубліковану A/B/C версію з актуальною ревізією. Legacy conversion недоступна.');
            }
            if (DB::table('recipe_versions')->where('recipe_id', $recipeId)->where('status', 'draft')->exists()) {
                $this->fail('Цей рецепт уже має чернетку. Відкрийте її для редагування.');
            }
            $id = DB::table('recipe_versions')->insertGetId([
                'recipe_id' => $recipeId, 'title' => $source->title, 'editor_contract' => 'batch_v1',
                'source_recipe_version_id' => $sourceId, 'servings_count' => $source->servings_count,
            ]);
            // Maps мають окремі простори ID; усі FK й тексти пишуться атомарно.
            $formats = $this->cloneChildren('recipe_batch_formats', $sourceId, $id);
            $components = $this->cloneChildren('recipe_components', $sourceId, $id);
            $rows = $this->cloneChildren('recipe_ingredients', $sourceId, $id, fn ($r) => [
                'component_id' => $r['component_id'] === null ? null : $components[$r['component_id']],
            ]);
            $equipment = $this->cloneChildren('recipe_kitchen_equipment', $sourceId, $id);
            foreach (['recipe_batch_quantities', 'recipe_batch_equipment'] as $table) {
                foreach (DB::table($table)->where('recipe_version_id', $sourceId)->get() as $record) {
                    $r = (array) $record;
                    $r['recipe_version_id'] = $id;
                    $r['format_id'] = $formats[$r['format_id']];
                    $r['manual_context'] = $r['mode'] === 'manual' ? '{}' : null;
                    $r['review_ack_hash'] = null;
                    if ($table === 'recipe_batch_quantities') {
                        $r['recipe_ingredient_id'] = $rows[$r['recipe_ingredient_id']];
                    } else {
                        $r['recipe_kitchen_equipment_id'] = $equipment[$r['recipe_kitchen_equipment_id']];
                        // JSONB SQL зберігає типи/порожні objects без PHP round-trip.
                        $r['input_snapshot'] = DB::selectOne("SELECT ?::jsonb - 'adequacy_ack' AS snapshot", [$r['input_snapshot']])->snapshot;
                    }
                    DB::table($table)->insert($r);
                }
            }
            $this->cloneChildren('recipe_directions', $sourceId, $id, fn ($r) => [
                'direction_text' => RecipeTokens::remap($r['direction_text'], $rows, $equipment),
            ]);
            DB::select('SELECT recipe_batch_validate(?)', [$id]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $id;
        });
    }

    private function cloneChildren(string $table, int $sourceId, int $id, ?callable $replace = null): array
    {
        $map = [];
        foreach (DB::table($table)->where('recipe_version_id', $sourceId)->orderBy('id')->get() as $record) {
            $r = (array) $record;
            $oldId = $r['id'];
            unset($r['id']);
            $r['recipe_version_id'] = $id;
            $map[$oldId] = DB::table($table)->insertGetId(array_replace($r, $replace ? $replace($r) : []));
        }

        return $map;
    }

    public function open(User $actor, int $id): array
    {
        $this->authorize($actor);

        // Один MVCC snapshot для root/details; GET не бере write locks і нічого не пише.
        return DB::transaction(function () use ($id) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $v = DB::table('recipe_versions')->find($id);
            abort_unless($v, 404);
            if ($v->editor_contract !== 'batch_v1') {
                return ['boundary' => 'Старий формат: конвертація цієї чернетки ще недоступна.'];
            }
            $recipe = DB::table('recipes')->find($v->recipe_id);
            $rows = [];
            foreach (DB::table('recipe_ingredients')->where('recipe_version_id', $id)->orderBy('id')->get() as $r) {
                $row = ['id' => (string) $r->id, 'ingredient_id' => (string) $r->ingredient_id, 'quantity' => $r->quantity,
                    'component_id' => $r->component_id ? (string) $r->component_id : '', 'week' => [], 'half_week' => []];
                foreach (DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $r->id)->get() as $q) {
                    $s = json_decode($q->input_snapshot, true);
                    $row[$q->format_code] = ['mode' => $q->mode, 'quantity' => $q->quantity, 'uom_id' => (string) $q->uom_id,
                        'rounding' => $s['rounding'], 'step' => $s['step'], 'confirmed' => $s['confirmed'] ?? false];
                    if ($q->format_code === 'half_week') {
                        $row['auto_policies'] = $s['auto_policies'] ?? [];
                    }
                }
                $rows[] = $row;
            }
            foreach (json_decode($v->draft_pending_rows, true) as $r) {
                $row = ['id' => $r['id'], 'ingredient_id' => $r['ingredient_id'] ?? '', 'quantity' => $r['quantity'] ?? '', 'component_id' => $r['component_id'] ?? ''];
                foreach (['week', 'half_week'] as $code) {
                    $q = $r['overrides'][$code] ?? [];
                    $s = $q['input_snapshot'] ?? [];
                    if (isset($s['pending_state']) && (($s['pending_state']['schema_version'] ?? null) !== 1 || ! in_array($s['pending_state']['mode'] ?? null, ['auto', 'manual'], true))) {
                        $this->fail('Невідома версія pending snapshot.');
                    }
                    $row[$code] = ['mode' => $s['pending_state']['mode'] ?? $q['mode'] ?? 'auto', 'quantity' => $q['quantity'] ?? '', 'uom_id' => $q['uom_id'] ?? '',
                        'rounding' => $s['rounding'] ?? 'exact', 'step' => $s['step'] ?? '0.01', 'confirmed' => $s['confirmed'] ?? false];
                }
                $row['auto_policies'] = $r['overrides']['half_week']['input_snapshot']['auto_policies'] ?? $r['overrides']['week']['input_snapshot']['auto_policies'] ?? [];
                $rows[] = $row;
            }
            $equipment = [];
            foreach (DB::table('recipe_kitchen_equipment')->where('recipe_version_id', $id)->orderBy('id')->get() as $r) {
                $tool = ['id' => (string) $r->id, 'kitchen_equipment_id' => (string) $r->kitchen_equipment_id,
                    'min_value' => $r->min_value ?? '', 'uom_id' => $r->uom_id ? (string) $r->uom_id : '',
                    'scaling_mode' => $r->scaling_mode, 'tip' => $r->tip ?? ''];
                foreach (DB::table('recipe_batch_equipment as e')->join('recipe_batch_formats as f', 'f.id', '=', 'e.format_id')->where('e.recipe_kitchen_equipment_id', $r->id)->get(['e.*', 'f.code']) as $q) {
                    $tool[$q->code] = ['mode' => $q->mode, 'min_value' => $q->min_value ?? '',
                        'uom_id' => $q->uom_id ? (string) $q->uom_id : '', 'tip' => $q->tip ?? ''];
                }
                $equipment[] = $tool;
            }

            return ['revision' => (string) $v->lock_version, 'status' => $v->status, 'payload' => [
                'title' => $v->title, 'category_id' => $recipe->recipe_category_id ? (string) $recipe->recipe_category_id : '', 'rows' => $rows,
                'components' => DB::table('recipe_components')->where('recipe_version_id', $id)->orderBy('id')->get()->map(fn ($c) => ['id' => (string) $c->id, 'title' => $c->title])->all(),
                'directions' => DB::table('recipe_directions')->where('recipe_version_id', $id)->orderBy('direction_number')->pluck('direction_text')->all(), 'equipment' => $equipment,
            ]];
        });
    }

    public function preview(User $actor, int $id, string $revision, array $payload): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $id, $revision, $payload) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $v = $this->version($id, $revision);
            $p = $this->prepare($id, $payload);

            return $p + ['token' => $this->token($actor, $v, $payload, $p), 'revision' => $revision];
        });
    }

    public function save(User $actor, int $id, string $revision, array $payload, string $token, bool $acceptDiff = false, bool $acknowledge = false): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $id, $revision, $payload, $token, $acceptDiff, $acknowledge) {
            $v = $this->lock($id, $revision, $payload);
            $p = $this->prepare($id, $payload);
            if (! hash_equals($this->token($actor, $v, $payload, $p), $token)) {
                $this->fail('Preview застарів: відкрийте новий diff.');
            }
            if (($p['diff'] || $p['equipment_diff']) && ! $acceptDiff) {
                $this->fail('Довідник змінився: явно прийміть diff через Refresh.');
            }
            $componentMap = $this->syncComponents($id, $payload['components']);
            $oldRows = DB::table('recipe_ingredients')->where('recipe_version_id', $id)->pluck('id')->all();
            $map = [];
            foreach ($p['inputs'] as $key => $input) {
                $raw = collect($payload['rows'])->firstWhere('id', (string) $key);
                $values = ['ingredient_id' => $raw['ingredient_id'], 'quantity' => $p['evaluation']['rows'][$key]['single']['quantity'],
                    'quantity_grams' => $p['evaluation']['rows'][$key]['single']['grams'], 'uom_id' => $p['gram_id'],
                    'component_id' => $componentMap[$raw['component_id'] ?? ''] ?? null, 'ingredient_snapshot' => json_encode($p['snapshots'][$key])];
                if (ctype_digit((string) $key)) {
                    $rowId = (int) $key;
                    DB::table('recipe_ingredients')->where('id', $rowId)->update($values);
                } else {
                    $rowId = DB::table('recipe_ingredients')->insertGetId(['recipe_version_id' => $id] + $values);
                }
                $map[(string) $key] = $rowId;
                foreach (['week', 'half_week'] as $code) {
                    $q = $p['evaluation']['rows'][$key][$code];
                    $uom = $p['units'][$q['uom']];
                    $snapshot = ['schema_version' => 1, 'factor' => $q['factor'], 'uom_label' => $uom->label, 'source_hash' => $p['hashes'][$raw['ingredient_id']]] + $q['policy'];
                    if ($code === 'half_week') {
                        $snapshot['auto_policies'] = (object) ($input['auto_policies'] ?? []);
                    }
                    $old = DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $rowId)->where('format_code', $code)->first();
                    $samePair = $old && $old->mode === 'manual' && $old->quantity === $q['quantity'] && $old->uom_id === $uom->id;
                    DB::table('recipe_batch_quantities')->updateOrInsert(['recipe_ingredient_id' => $rowId, 'format_code' => $code], [
                        'recipe_version_id' => $id, 'format_id' => $p['formats'][$code], 'mode' => $q['mode'], 'quantity' => $q['quantity'], 'quantity_grams' => $q['grams'],
                        'uom_id' => $uom->id, 'input_snapshot' => json_encode($snapshot),
                        'manual_context' => $q['mode'] === 'manual' ? ($samePair ? $old->manual_context : '{}') : null,
                        'review_ack_hash' => $q['mode'] === 'manual' && $samePair ? $old->review_ack_hash : null,
                    ]);
                }
            }
            $removed = array_diff($oldRows, array_values($map));
            DB::table('recipe_batch_quantities')->whereIn('recipe_ingredient_id', $removed)->delete();
            DB::table('recipe_ingredients')->whereIn('id', $removed)->delete();
            $pending = $p['pending'];
            foreach ($pending as &$r) {
                if (ctype_digit($r['id'])) {
                    $r['id'] = 'pending:row-'.$r['id'];
                }
                $r['component_id'] = isset($componentMap[$r['component_id'] ?? '']) ? (string) $componentMap[$r['component_id']] : '';
            }
            unset($r);
            // Відновлений pending рядок отримує новий PK; токени remap атомарно.
            foreach ($map as $key => $rowId) {
                if (preg_match('/^pending:row-(\d+)$/D', $key, $m)) {
                    $map[$m[1]] = $rowId;
                }
            }
            $equipmentMap = $this->syncEquipment($id, $payload['equipment'], $p['equipment'], $p['formats']);
            $directions = array_map(fn ($text) => RecipeTokens::remap($text, $map, $equipmentMap), $payload['directions']);
            // Editor передає тексти: незмінні кроки зберігають власні image_url,
            // зокрема після clone, зміни порядку або remap pending tokens.
            $stepImages = [];
            foreach (DB::table('recipe_directions')->where('recipe_version_id', $id)->orderBy('direction_number')->get() as $step) {
                $stepImages[$step->direction_text][] = $step->image_url;
            }
            DB::table('recipe_directions')->where('recipe_version_id', $id)->delete();
            foreach ($directions as $n => $text) {
                $originalText = $payload['directions'][$n];
                $image = empty($stepImages[$originalText]) ? null : array_shift($stepImages[$originalText]);
                DB::table('recipe_directions')->insert(['recipe_version_id' => $id, 'direction_number' => $n + 1, 'direction_text' => $text, 'image_url' => $image]);
            }
            DB::table('recipe_components')->where('recipe_version_id', $id)->whereNotIn('id', array_values($componentMap))->delete();
            DB::table('recipes')->where('id', $v->recipe_id)->update(['title' => $payload['title'], 'recipe_category_id' => $payload['category_id'] ?: null]);
            DB::table('recipe_versions')->where('id', $id)->update(['title' => $payload['title'], 'draft_pending_rows' => json_encode($pending), 'lock_version' => DB::raw('lock_version+1'), 'publication_review' => null]);
            $this->totals($id, $p['evaluation']['totals']);
            if ($acknowledge) {
                DB::update("UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_version_id=? AND mode='manual'", [$id]);
            }
            if ($acknowledge) {
                DB::update("UPDATE recipe_batch_equipment SET input_snapshot=jsonb_set(input_snapshot,'{adequacy_ack}',to_jsonb(md5(recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id)::text))), manual_context=CASE WHEN mode='manual' THEN recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id) END, review_ack_hash=CASE WHEN mode='manual' THEN md5(recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id)::text) END WHERE recipe_version_id=?", [$id]);
            }
            DB::select('SELECT recipe_batch_validate(?)', [$id]);
            // Перевіряє deferred guards до повернення успіху, зокрема FK у nested tests.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return ['revision' => (string) DB::table('recipe_versions')->where('id', $id)->value('lock_version'), 'row_map' => $map];
        });
    }

    public function publish(User $actor, int $id, string $revision, string $token): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $id, $revision, $token) {
            $opened = $this->open($actor, $id);
            $v = $this->lock($id, $revision, $opened['payload'] ?? []);
            $payload = $this->open($actor, $id)['payload'];
            $p = $this->prepare($id, $payload);
            if (! hash_equals($this->token($actor, $v, $payload, $p), $token) || $p['diff'] || $p['equipment_diff']) {
                $this->fail('Preview або джерела застаріли: спочатку Refresh/Save.');
            }
            if ($p['evaluation']['incomplete'] || trim($payload['title']) === '' || ! $payload['category_id'] || ! $payload['directions']) {
                $this->fail('Для публікації завершіть склад, назву, категорію та кроки.');
            }
            foreach ($payload['directions'] as $text) {
                foreach (self::CODES as $code) {
                    RecipeTokens::render($text, $p['evaluation']['rows'], $code, $p['equipment']);
                }
                if (trim($text) === '') {
                    $this->fail('Порожній крок приготування.');
                }
            }
            foreach ($p['equipment'] as $tool) {
                foreach ($tool as $q) {
                    if ($q['review']) {
                        $this->fail('Підтвердіть достатність обладнання A/B/C для поточного складу та параметрів.');
                    }
                }
            }
            DB::select('SELECT recipe_batch_validate(?,true)', [$id]);
            if (DB::table('ingredients')->whereIn('id', array_keys($p['hashes']))->whereNull('ingredient_category_id')->exists()) {
                $this->fail('Перед публікацією заповніть категорії інгредієнтів.');
            }
            $food = DB::table('foods')->insertGetId(['food_type' => 'recipe_version']);
            $number = (int) DB::table('recipe_versions')->where('recipe_id', $v->recipe_id)->max('version_number') + 1;
            $values = ['food_id' => $food, 'status' => 'published', 'version_number' => $number, 'published_at' => now(),
                'lock_version' => DB::raw('lock_version+1'), 'publication_review' => json_encode(['complete' => true, 'lock_version' => (string) $v->lock_version, 'actor' => (string) $actor->id, 'at' => now()->toIso8601String()])];
            foreach (BatchEvaluator::NUTRIENTS as $key) {
                $values[$key.'_per_serving'] = $p['evaluation']['portions']['A/1'][$key];
            }
            DB::table('recipe_versions')->where('id', $id)->update($values);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
    }

    private function version(int $id, string $revision): object
    {
        $v = DB::table('recipe_versions')->find($id);
        abort_unless($v, 404);
        if ($v->editor_contract !== 'batch_v1' || $v->status !== 'draft' || (string) $v->lock_version !== $revision) {
            $this->fail('Конфлікт ревізій або версія недоступна для редагування. Перезавантажте сторінку.');
        }

        return $v;
    }

    private function lock(int $id, string $revision, array $payload): object
    {
        $recipe = DB::table('recipe_versions')->where('id', $id)->value('recipe_id');
        abort_unless($recipe, 404);
        DB::table('recipes')->where('id', $recipe)->lockForUpdate()->first();
        DB::table('recipe_versions')->where('id', $id)->lockForUpdate()->first();
        $v = $this->version($id, $revision);
        $ids = array_unique(array_filter(array_merge(array_column($payload['rows'] ?? [], 'ingredient_id'), DB::table('recipe_ingredients')->where('recipe_version_id', $id)->pluck('ingredient_id')->all()), fn ($i) => ctype_digit((string) $i)));
        DB::table('ingredients')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get();
        DB::table('ingredient_uoms')->whereIn('ingredient_id', $ids)->orderBy('ingredient_id')->orderBy('uom_id')->sharedLock()->get();
        // Усі UOM: включає одиниці обладнання; однаковий порядок у всіх командах.
        DB::table('uoms')->orderBy('id')->sharedLock()->get();
        DB::table('recipe_categories')->orderBy('id')->sharedLock()->get();
        DB::table('kitchen_equipment')->orderBy('id')->sharedLock()->get();

        return $v;
    }

    private function token(User $actor, object $v, array $payload, array $p): string
    {
        return hash_hmac('sha256', json_encode($this->canonical([$actor->id, $v->id, (string) $v->lock_version, $payload, $p['hashes'], $p['catalog_hash'], $p['evaluation']])), config('app.key'));
    }

    private function canonical(array $a): array
    {
        foreach ($a as &$v) {
            if (is_array($v)) {
                $v = $this->canonical($v);
            }
        }
        if (! array_is_list($a)) {
            ksort($a);
        }

        return $a;
    }

    private function prepare(int $id, array $payload): array
    {
        Validator::make($payload, [
            'title' => 'present|string|max:500', 'category_id' => 'present|nullable|integer|exists:recipe_categories,id',
            'rows' => 'present|array|max:1000', 'rows.*.id' => ['required', 'string', 'distinct', 'regex:/^(?:[1-9]\d*|pending:[a-zA-Z0-9-]{1,64})$/D'],
            'rows.*.ingredient_id' => 'present|nullable|string|max:500', 'rows.*.quantity' => 'present|nullable|string|max:500', 'rows.*.component_id' => 'present|nullable|string|max:100',
            'rows.*.week.mode' => 'required|in:auto,manual', 'rows.*.half_week.mode' => 'required|in:auto,manual',
            'rows.*.week.quantity' => 'nullable|string|max:500', 'rows.*.half_week.quantity' => 'nullable|string|max:500',
            'rows.*.week.uom_id' => 'nullable|string|max:500', 'rows.*.half_week.uom_id' => 'nullable|string|max:500',
            'components' => 'present|array|max:100', 'components.*.id' => 'required|string|distinct|max:100', 'components.*.title' => 'required|string|max:500',
            'directions' => 'present|array|max:100', 'directions.*' => 'string|max:10000',
            'equipment' => 'present|array|max:100', 'equipment.*.id' => ['required', 'string', 'distinct', 'regex:/^(?:[1-9]\d*|new-[a-zA-Z0-9-]{1,64})$/D'],
            'equipment.*.kitchen_equipment_id' => 'required|integer|exists:kitchen_equipment,id', 'equipment.*.min_value' => 'nullable|decimal:0,2|gt:0|lte:99999.99',
            'equipment.*.uom_id' => 'nullable|integer|exists:uoms,id', 'equipment.*.scaling_mode' => 'required|in:fixed,proportional', 'equipment.*.tip' => 'nullable|string|max:500',
            'equipment.*.week.mode' => 'sometimes|required|in:auto,manual', 'equipment.*.half_week.mode' => 'sometimes|required|in:auto,manual',
            'equipment.*.*.min_value' => 'nullable|decimal:0,2|gt:0|lte:99999.99',
            'equipment.*.*.uom_id' => 'nullable|integer|exists:uoms,id', 'equipment.*.*.tip' => 'nullable|string|max:500',
        ])->validate();
        $owned = DB::table('recipe_ingredients')->where('recipe_version_id', $id)->get()->keyBy('id');
        $storedPending = collect(json_decode(DB::table('recipe_versions')->where('id', $id)->value('draft_pending_rows'), true))->keyBy('id');
        $units = DB::table('uoms')->get()->keyBy('code')->all();
        $unitIds = collect($units)->keyBy('id');
        $gram_id = $units['g']->id ?? null;
        if (! $gram_id) {
            $this->fail('У довіднику відсутня одиниця g. Потрібне погоджене наповнення довідника.');
        }
        $formats = DB::table('recipe_batch_formats')->where('recipe_version_id', $id)->pluck('id', 'code')->all();
        $inputs = $pending = $snapshots = $hashes = $diff = [];
        foreach ($payload['rows'] as $r) {
            $key = $r['id'];
            if (ctype_digit($key) && ! isset($owned[$key])) {
                $this->fail('Чужий або видалений ingredient row.');
            }
            if (($r['component_id'] ?? '') !== '' && ! in_array($r['component_id'], array_column($payload['components'], 'id'), true)) {
                $this->fail('Чужий компонент.');
            }
            $incomplete = ! ($r['ingredient_id'] ?? '') || ! $this->completeNumber($r['quantity'] ?? '');
            foreach (['week', 'half_week'] as $code) {
                if ($r[$code]['mode'] === 'manual' && (! $this->completeNumber($r[$code]['quantity'] ?? '') || empty($r[$code]['uom_id']))) {
                    $incomplete = true;
                }
            }
            if ($incomplete) {
                $entry = ['id' => $key, 'ingredient_id' => $r['ingredient_id'], 'quantity' => $r['quantity'], 'uom_id' => (string) $gram_id, 'component_id' => $r['component_id'], 'overrides' => (object) []];
                $overrides = [];
                foreach (['week', 'half_week'] as $code) {
                    $old = ctype_digit($key) ? DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $key)->where('format_code', $code)->first() : null;
                    $previous = $storedPending[$key]['overrides'][$code] ?? [];
                    // SQL 11 допускає лише manual-shaped envelope. Реальний режим — у
                    // версованому pending_state; provenance береться тільки з БД, не payload.
                    $snapshot = $old ? json_decode($old->input_snapshot, true) : ($previous['input_snapshot'] ?? []);
                    $snapshot += ['schema_version' => 1, 'rounding' => $r[$code]['rounding'] ?? 'exact', 'step' => $r[$code]['step'] ?? '0.01'];
                    if ($old) {
                        $snapshot['ingredient_snapshot'] = json_decode($owned[$key]->ingredient_snapshot, true);
                    }
                    $snapshot['auto_policies'] = (object) ($r['auto_policies'] ?? []);
                    $snapshot['pending_state'] = ['schema_version' => 1, 'mode' => $r[$code]['mode'], 'quantity_grams' => $old->quantity_grams ?? $snapshot['pending_state']['quantity_grams'] ?? null];
                    if (strlen(json_encode($snapshot)) > 8192) {
                        $this->fail('Pending snapshot перевищує 8 KiB.');
                    }
                    $overrides[$code] = ['mode' => 'manual', 'quantity' => $r[$code]['quantity'] ?? '', 'uom_id' => $r[$code]['uom_id'] ?? '',
                        'input_snapshot' => $snapshot, 'manual_context' => $old ? json_decode($old->manual_context ?? '{}', false) : (isset($previous['manual_context']) ? (object) $previous['manual_context'] : null),
                        'review_ack_hash' => $old->review_ack_hash ?? $previous['review_ack_hash'] ?? null];
                }
                $entry['overrides'] = (object) $overrides;
                $pending[] = $entry;

                continue;
            }
            $ingredient = DB::table('ingredients')->find($r['ingredient_id']);
            if (! $ingredient) {
                $this->fail('Інгредієнт не існує.');
            }
            $hash = DB::selectOne('SELECT recipe_batch_source_hash(?) hash', [$ingredient->id])->hash;
            $hashes[$ingredient->id] = $hash;
            $snapshots[$key] = ['schema_version' => 1, 'name' => $ingredient->name];
            $nutrients = [];
            foreach (BatchEvaluator::NUTRIENTS as $n) {
                $nutrients[$n] = $snapshots[$key][$n] = $ingredient->{$n.'_per_100g'};
            }
            $factors = ['g' => '1.00'];
            foreach (DB::table('ingredient_uoms')->where('ingredient_id', $ingredient->id)->get() as $conversion) {
                $factors[$unitIds[$conversion->uom_id]->code] = $conversion->grams_per_uom;
            }
            $input = ['quantity' => $r['quantity'], 'nutrients' => $nutrients, 'factors' => $factors, 'ingredient_fingerprint' => $hash,
                'conversion_fingerprint' => $hash, 'auto_policies' => $r['auto_policies'] ?? []];
            foreach (['week', 'half_week'] as $code) {
                $state = $r[$code];
                $uom = $unitIds[$state['uom_id'] ?: $gram_id] ?? null;
                if (! $uom || ! isset($factors[$uom->code])) {
                    $this->fail('Немає дозволеної конверсії UOM. Потрібний Refresh/виправлення.');
                }
                $input[$code] = ['mode' => $state['mode'], 'uom' => $uom->code, 'quantity' => $state['quantity'] ?? '', 'policy' => [
                    'rounding' => $state['rounding'] ?? 'exact', 'step' => $state['step'] ?? '0.01',
                ]];
                if ($state['confirmed'] ?? false) {
                    $input[$code]['policy']['confirmed'] = true;
                }
                if ($code === 'week' && $state['mode'] === 'auto') {
                    $policy = $input[$code]['policy'];
                    $allowed = $uom->code === 'g'
                        ? (($policy['rounding'] === 'exact' && $policy['step'] === '0.01') || ($policy['rounding'] === 'nearest' && $policy['step'] === '100.00'))
                        : ($uom->kind === 'count' && $policy['rounding'] === 'ceil' && in_array($policy['step'], ['1.00', '0.50', '0.25'], true));
                    if (! $allowed) {
                        $this->fail('Непогоджений пресет B: точні g, свідомий nearest 100 g або підтверджений ceil 1/0.50/0.25 дозволеної UOM.');
                    }
                }
                $old = ctype_digit($key) ? DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $key)->where('format_code', $code)->first() : null;
                $previous = $storedPending[$key]['overrides'][$code] ?? [];
                $previousInput = $old ? json_decode($old->input_snapshot, true) : ($previous['input_snapshot'] ?? []);
                if (isset($previousInput['source_hash']) && $previousInput['source_hash'] !== $hash) {
                    $diff[$key][$code] = ['before' => ['quantity' => $old->quantity ?? ($previous['quantity'] ?? ''), 'grams' => $old->quantity_grams ?? $previousInput['pending_state']['quantity_grams'] ?? 'pending', 'snapshot' => $previousInput], 'source_now' => $hash,
                        'ingredient_before' => $old ? json_decode($owned[$key]->ingredient_snapshot, true) : ($previousInput['ingredient_snapshot'] ?? $snapshots[$key]), 'ingredient_after' => $snapshots[$key]];
                }
            }
            $inputs[$key] = $input;
        }
        try {
            $evaluation = app(BatchEvaluator::class)->evaluate($inputs, $pending);
        } catch (\InvalidArgumentException|\TypeError $e) {
            $this->fail($e->getMessage());
        }
        foreach ($diff as $key => &$changes) {
            foreach ($changes as $code => &$change) {
                $change['after'] = $evaluation['rows'][$key][$code];
            }
        }
        unset($changes, $change);
        foreach ($evaluation['rows'] as $key => &$row) {
            foreach (['week', 'half_week'] as $code) {
                // Показ persisted review незалежно від PHP representation DB context.
                $old = ctype_digit((string) $key) ? DB::selectOne('SELECT q.*,manual_context=recipe_batch_context(recipe_ingredient_id,format_code) AND review_ack_hash=md5(manual_context::text) accepted FROM recipe_batch_quantities q WHERE recipe_ingredient_id=? AND format_code=?', [$key, $code]) : null;
                $oldB = $code === 'half_week' && ctype_digit((string) $key) ? DB::table('recipe_batch_quantities')->where('recipe_ingredient_id', $key)->where('format_code', 'week')->first() : null;
                $parentPolicyChanged = $oldB && array_intersect_key(json_decode($oldB->input_snapshot, true), array_flip(['rounding', 'step', 'confirmed'])) != $row['week']['policy'];
                $ownPolicyChanged = $old && array_intersect_key(json_decode($old->input_snapshot, true), array_flip(['rounding', 'step', 'confirmed'])) != $row[$code]['policy'];
                $parentChanged = $parentPolicyChanged || ($oldB && ($oldB->quantity !== $row['week']['quantity'] || $oldB->uom_id !== $units[$row['week']['uom']]->id || $oldB->quantity_grams !== $row['week']['grams']));
                $row[$code]['review'] = $row[$code]['mode'] === 'manual' && (! $old || ! $old->accepted || $ownPolicyChanged || $parentChanged || isset($diff[$key])
                    || $old->quantity !== $row[$code]['quantity'] || $old->uom_id !== ($units[$row[$code]['uom']]->id)
                    || $owned[$key]->quantity !== $row['single']['quantity'] || json_decode($owned[$key]->ingredient_snapshot, true) != $snapshots[$key]);
            }
        }
        unset($row);
        // Валідація чужих IDs до будь-якого запису; клієнт не може reparent.
        foreach (['components' => 'recipe_components', 'equipment' => 'recipe_kitchen_equipment'] as $field => $table) {
            foreach ($payload[$field] as $r) {
                if (ctype_digit($r['id']) && ! DB::table($table)->where('recipe_version_id', $id)->where('id', $r['id'])->exists()) {
                    $this->fail('Чужий '.$field.' row.');
                }
            }
        }

        $catalog_hash = hash('sha256', json_encode([DB::table('recipe_categories')->orderBy('id')->get()->all(), DB::table('kitchen_equipment')->orderBy('id')->get()->all(), DB::table('uoms')->orderBy('id')->get()->all()]));

        $equipment = $this->equipmentPreview($id, $payload['equipment'], $inputs, $pending, $units);

        $equipment_diff = [];
        foreach ($equipment as $key => $tool) {
            foreach ($tool as $code => $q) {
                if ($q['source_changed']) {
                    $equipment_diff[$key][$code] = $q['name'].' · '.$q['uom'];
                }
            }
        }

        return compact('equipment_diff', 'equipment', 'catalog_hash', 'inputs', 'pending', 'snapshots', 'hashes', 'diff', 'evaluation', 'units', 'gram_id', 'formats');
    }

    private function completeNumber(string $value): bool
    {
        return (bool) preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', $value) && BigDecimal::of($value)->isGreaterThan(0);
    }

    private function syncComponents(int $id, array $rows): array
    {
        $map = [];
        foreach ($rows as $n => $row) {
            $values = ['title' => $row['title'], 'sort_order' => $n];
            if (ctype_digit($row['id'])) {
                DB::table('recipe_components')->where('id', $row['id'])->update($values);
                $map[$row['id']] = (int) $row['id'];
            } else {
                $map[$row['id']] = DB::table('recipe_components')->insertGetId(['recipe_version_id' => $id] + $values);
            }
        }

        return $map;
    }

    private function equipmentPreview(int $id, array $rows, array $inputs, array $pending, array $units): array
    {
        $result = [];
        $unitIds = collect($units)->keyBy('id');
        $normalized = app(BatchEvaluator::class)->evaluate($inputs, $pending)['rows'];
        foreach ($normalized as &$r) {
            foreach (['week', 'half_week'] as $code) {
                unset($r[$code]['review'], $r[$code]['context'], $r[$code]['target_grams']);
            }
        }
        unset($r);
        $dependencies = hash('sha256', json_encode($this->canonical([array_values($normalized), array_values(array_map(fn ($r) => [$r['nutrients'], $r['ingredient_fingerprint']], $inputs)), $pending])));
        foreach ($rows as $row) {
            $base = ctype_digit($row['id']) ? DB::table('recipe_kitchen_equipment')->where('recipe_version_id', $id)->find($row['id']) : null;
            $baseChanged = ! $base || (string) $base->kitchen_equipment_id !== $row['kitchen_equipment_id']
                || $base->min_value !== (($row['min_value'] ?? '') === '' ? null : (string) BigDecimal::of($row['min_value'])->toScale(2))
                || (string) ($base->uom_id ?? '') !== $row['uom_id'] || $base->tip !== ($row['tip'] ?? '') || $base->scaling_mode !== $row['scaling_mode'];
            foreach (self::CODES as $code) {
                $state = $row[$code] ?? ['mode' => 'auto'];
                $mode = $code === 'single' ? 'manual' : $state['mode'];
                $unit = $unitIds[$row['uom_id']] ?? null;
                $automatic = ($row['min_value'] ?? '') === '' || $row['min_value'] === null ? null : BigDecimal::of($row['min_value'])->multipliedBy($row['scaling_mode'] === 'fixed' ? '1' : ['single' => '1', 'week' => '7', 'half_week' => '3.5'][$code]);
                if ($automatic !== null) {
                    $automatic = (string) $automatic->toScale($unit?->kind === 'count' ? 0 : 2, $unit?->kind === 'count' ? RoundingMode::Ceiling : RoundingMode::HalfUp)->toScale(2);
                    if (BigDecimal::of($automatic)->isGreaterThan('99999.99')) {
                        $this->fail('Кількість обладнання поза діапазоном.');
                    }
                }
                if ($code === 'single') {
                    $state = $row;
                }
                $value = $mode === 'manual' ? (($state['min_value'] ?? '') === '' ? null : (string) BigDecimal::of($state['min_value'])->toScale(2)) : $automatic;
                $uom = $mode === 'manual' ? ($state['uom_id'] ?: null) : ($row['uom_id'] ?: null);
                $tip = $mode === 'manual' ? ($state['tip'] ?? '') : ($row['tip'] ?? '');
                $old = $base ? DB::selectOne("SELECT be.*,be.input_snapshot->>'adequacy_ack'=md5(recipe_batch_equipment_context(be.recipe_kitchen_equipment_id,be.format_id)::text) accepted FROM recipe_batch_equipment be JOIN recipe_batch_formats f ON f.id=be.format_id WHERE be.recipe_kitchen_equipment_id=? AND f.code=?", [$base->id, $code]) : null;
                $snapshot = $old ? json_decode($old->input_snapshot, true) : [];
                $source = hash('sha256', json_encode($this->canonical([DB::table('kitchen_equipment')->find($row['kitchen_equipment_id']), $unitIds[$uom] ?? null])));
                $sourceChanged = $old && isset($snapshot['source_hash']) && $snapshot['source_hash'] !== $source
                    && (string) ($old->uom_id ?? '') === (string) ($uom ?? '') && (string) $base->kitchen_equipment_id === $row['kitchen_equipment_id'];
                $review = $sourceChanged || ! $old || ! $old->accepted || $baseChanged || ($snapshot['dependencies'] ?? null) !== $dependencies
                    || $old->mode !== $mode || $old->min_value !== $value || (string) ($old->uom_id ?? '') !== (string) ($uom ?? '') || $old->tip !== $tip;
                $result[$row['id']][$code] = ['mode' => $mode, 'min_value' => $value, 'uom_id' => $uom, 'tip' => $tip,
                    'uom' => $unitIds[$uom]->label ?? '', 'name' => DB::table('kitchen_equipment')->where('id', $row['kitchen_equipment_id'])->value('name'),
                    'source_changed' => $sourceChanged, 'automatic_uom' => $unit?->label ?? '', 'automatic' => $automatic, 'review' => $review, 'reason' => $review ? 'Змінилися склад, базові параметри або ручне значення; перевірте достатність обладнання.' : '',
                    'snapshot' => ['schema_version' => 1, 'dependencies' => $dependencies, 'source_hash' => $source] + (isset($snapshot['adequacy_ack']) ? ['adequacy_ack' => $snapshot['adequacy_ack']] : [])];
            }
        }

        return $result;
    }

    private function syncEquipment(int $id, array $rows, array $resolved, array $formats): array
    {
        $map = [];
        foreach ($rows as $row) {
            $values = ['kitchen_equipment_id' => $row['kitchen_equipment_id'], 'min_value' => $row['min_value'] ?: null,
                'uom_id' => $row['uom_id'] ?: null, 'scaling_mode' => $row['scaling_mode'], 'tip' => $row['tip'] ?? ''];
            if (ctype_digit($row['id'])) {
                $base = (int) $row['id'];
                DB::table('recipe_kitchen_equipment')->where('recipe_version_id', $id)->where('id', $base)->update($values);
            } else {
                $base = DB::table('recipe_kitchen_equipment')->insertGetId(['recipe_version_id' => $id] + $values);
            }
            $map[$row['id']] = $base;
            foreach ($formats as $code => $format) {
                $q = $resolved[$row['id']][$code];
                $old = DB::table('recipe_batch_equipment')->where('recipe_kitchen_equipment_id', $base)->where('format_id', $format)->first();
                $same = $old && $old->mode === 'manual' && $q['mode'] === 'manual' && $old->min_value === $q['min_value'] && (string) ($old->uom_id ?? '') === (string) ($q['uom_id'] ?? '') && $old->tip === $q['tip'];
                DB::table('recipe_batch_equipment')->updateOrInsert(['format_id' => $format, 'recipe_kitchen_equipment_id' => $base], [
                    'recipe_version_id' => $id, 'mode' => $q['mode'], 'min_value' => $q['min_value'], 'uom_id' => $q['uom_id'], 'tip' => $q['tip'], 'input_snapshot' => json_encode($q['snapshot']),
                    'manual_context' => $q['mode'] === 'manual' ? ($same ? $old->manual_context : '{}') : null,
                    'review_ack_hash' => $same ? $old->review_ack_hash : null,
                ]);
            }
        }
        $removed = DB::table('recipe_kitchen_equipment')->where('recipe_version_id', $id)->whereNotIn('id', array_values($map))->pluck('id');
        DB::table('recipe_batch_equipment')->whereIn('recipe_kitchen_equipment_id', $removed)->delete();
        DB::table('recipe_kitchen_equipment')->whereIn('id', $removed)->delete();

        return $map;
    }

    private function totals(int $id, ?array $totals = null): void
    {
        $totals ??= app(BatchEvaluator::class)->evaluate([])['totals'];
        foreach ($totals as $code => $values) {
            // Значення дає лише evaluator; SQL канонізує hash та незалежно звіряє guards.
            DB::update('UPDATE recipe_batch_formats SET kcal_total=?,protein_total=?,fat_total=?,carbs_total=?,calculation_hash=md5(?::jsonb::text) WHERE recipe_version_id=? AND code=?',
                [$values['kcal'], $values['protein'], $values['fat'], $values['carbs'], json_encode($values), $id, $code]);
        }
    }
}

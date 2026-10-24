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
            $equipment = DB::table('recipe_kitchen_equipment')->where('recipe_version_id', $id)->orderBy('id')->get()->map(fn ($r) => [
                'id' => (string) $r->id, 'kitchen_equipment_id' => (string) $r->kitchen_equipment_id, 'min_value' => $r->min_value ?? '',
                'uom_id' => $r->uom_id ? (string) $r->uom_id : '', 'scaling_mode' => $r->scaling_mode, 'tip' => $r->tip ?? '',
            ])->all();

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
            if ($p['diff'] && ! $acceptDiff) {
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
            $directions = array_map(fn ($text) => RecipeTokens::remap($text, $map), $payload['directions']);
            DB::table('recipe_directions')->where('recipe_version_id', $id)->delete();
            foreach ($directions as $n => $text) {
                DB::table('recipe_directions')->insert(['recipe_version_id' => $id, 'direction_number' => $n + 1, 'direction_text' => $text]);
            }
            DB::table('recipe_components')->where('recipe_version_id', $id)->whereNotIn('id', array_values($componentMap))->delete();
            $this->syncEquipment($id, $payload['equipment'], $p['formats']);
            DB::table('recipes')->where('id', $v->recipe_id)->update(['title' => $payload['title'], 'recipe_category_id' => $payload['category_id'] ?: null]);
            DB::table('recipe_versions')->where('id', $id)->update(['title' => $payload['title'], 'draft_pending_rows' => json_encode($pending), 'lock_version' => DB::raw('lock_version+1'), 'publication_review' => null]);
            $this->totals($id, $p['evaluation']['totals']);
            if ($acknowledge) {
                DB::update("UPDATE recipe_batch_quantities SET manual_context=recipe_batch_context(recipe_ingredient_id,format_code),review_ack_hash=md5(recipe_batch_context(recipe_ingredient_id,format_code)::text) WHERE recipe_version_id=? AND mode='manual'", [$id]);
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
            if (! hash_equals($this->token($actor, $v, $payload, $p), $token) || $p['diff']) {
                $this->fail('Preview або джерела застаріли: спочатку Refresh/Save.');
            }
            if ($p['evaluation']['incomplete'] || trim($payload['title']) === '' || ! $payload['category_id'] || ! $payload['directions']) {
                $this->fail('Для публікації завершіть склад, назву, категорію та кроки.');
            }
            foreach ($payload['directions'] as $text) {
                RecipeTokens::render($text, $p['evaluation']['rows'], 'single');
                if (trim($text) === '') {
                    $this->fail('Порожній крок приготування.');
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
            'equipment' => 'present|array|max:100', 'equipment.*.id' => 'required|string|distinct|max:100',
            'equipment.*.kitchen_equipment_id' => 'required|integer|exists:kitchen_equipment,id', 'equipment.*.min_value' => 'nullable|decimal:0,2|gt:0|lte:99999.99',
            'equipment.*.uom_id' => 'nullable|integer|exists:uoms,id', 'equipment.*.scaling_mode' => 'required|in:fixed,proportional', 'equipment.*.tip' => 'nullable|string|max:500',
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

        return compact('catalog_hash', 'inputs', 'pending', 'snapshots', 'hashes', 'diff', 'evaluation', 'units', 'gram_id', 'formats');
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

    private function syncEquipment(int $id, array $rows, array $formats): void
    {
        // Базовий slice: fixed/proportional auto. Існуючі manual snapshots не переписуємо.
        if (DB::table('recipe_batch_equipment')->where('recipe_version_id', $id)->where('mode', 'manual')->exists()) {
            $this->fail('Manual equipment: ручне коригування обладнання ще недоступне; збережені значення захищено.');
        }
        DB::table('recipe_batch_equipment')->where('recipe_version_id', $id)->delete();
        DB::table('recipe_kitchen_equipment')->where('recipe_version_id', $id)->delete();
        foreach ($rows as $row) {
            $values = ['recipe_version_id' => $id, 'kitchen_equipment_id' => $row['kitchen_equipment_id'], 'min_value' => $row['min_value'] ?: null,
                'uom_id' => $row['uom_id'] ?: null, 'scaling_mode' => $row['scaling_mode'], 'tip' => $row['tip'] ?? ''];
            $base = DB::table('recipe_kitchen_equipment')->insertGetId($values);
            foreach ($formats as $code => $format) {
                $value = $values['min_value'] === null ? null : (string) BigDecimal::of($values['min_value'])->multipliedBy($values['scaling_mode'] === 'fixed' ? '1' : ['single' => '1', 'week' => '7', 'half_week' => '3.5'][$code])->toScale(2, RoundingMode::HalfUp);
                DB::table('recipe_batch_equipment')->insert(['recipe_version_id' => $id, 'format_id' => $format, 'recipe_kitchen_equipment_id' => $base,
                    'mode' => 'auto', 'min_value' => $value, 'uom_id' => $values['uom_id'], 'tip' => $values['tip'], 'input_snapshot' => '{"schema_version":1}']);
            }
        }
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

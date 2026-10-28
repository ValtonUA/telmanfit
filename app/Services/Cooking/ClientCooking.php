<?php

namespace App\Services\Cooking;

use App\Support\Presentation\PublicImageUrl;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only client projection; callers keep every read in one PG18 snapshot. */
final class ClientCooking
{
    public function choices(int $client, int $week, string $today): array
    {
        $assignment = $this->currentAssignment($client, $today);
        if ($assignment === null) {
            return ['assignment' => null, 'week' => $week, 'modes' => $this->emptyModes()];
        }

        $weekStart = $this->weekStart($assignment, $week);
        $modes = [];
        foreach ([1, 2, 7] as $mode) {
            $candidates = DB::table('cooking_template_versions as v')
                ->join('cooking_selections as s', function ($join) {
                    $join->on('s.version_id', '=', 'v.id')->on('s.template_id', '=', 'v.template_id')
                        ->on('s.mode', '=', 'v.mode')->on('s.menu_fingerprint', '=', 'v.menu_fingerprint');
                })->join('cooking_templates as t', 't.id', '=', 'v.template_id')
                ->where('v.status', 'published')->where('v.mode', $mode)
                ->orderBy('t.title')->orderBy('v.version_number')->orderBy('v.id')
                ->get(['v.id', 'v.template_id', 'v.version_number', 'v.mode', 'v.menu_pattern', 't.title']);
            $available = [];
            foreach ($candidates as $version) {
                if (! $this->available($client, (int) $assignment->id, (int) $version->id, $week, $mode, $today)) {
                    continue;
                }
                $sessions = DB::table('cooking_sessions')->where('version_id', $version->id)->orderBy('position')->get();
                if ($sessions->count() !== $mode) {
                    throw new DomainException('Cooking session coverage mismatch.');
                }
                $pattern = json_decode($version->menu_pattern, true, flags: JSON_THROW_ON_ERROR);
                $available[] = ['version_id' => (string) $version->id, 'template_id' => (string) $version->template_id,
                    'title' => $version->title, 'version_number' => (int) $version->version_number,
                    'mode' => $mode, 'sessions' => $sessions->map(fn ($session) => $this->sessionSummary($version, $session, $pattern, $weekStart))->all()];
            }
            $modes[] = ['mode' => $mode, 'available' => $available !== [], 'templates' => $available];
        }

        return ['assignment' => $this->assignmentPayload($assignment), 'week' => $week, 'week_start' => $weekStart->toDateString(),
            'modes' => $modes];
    }

    public function session(int $client, int $week, int $mode, int $versionId, int $sessionId, string $today): array
    {
        $assignment = $this->currentAssignment($client, $today);
        if ($assignment === null || ! in_array($mode, [1, 2, 7], true)
            || ! $this->available($client, (int) $assignment->id, $versionId, $week, $mode, $today)) {
            throw new DomainException('Cooking session unavailable.');
        }
        $version = DB::table('cooking_template_versions as v')
            ->join('cooking_selections as s', function ($join) {
                $join->on('s.version_id', '=', 'v.id')->on('s.template_id', '=', 'v.template_id')
                    ->on('s.mode', '=', 'v.mode')->on('s.menu_fingerprint', '=', 'v.menu_fingerprint');
            })->join('cooking_templates as t', 't.id', '=', 'v.template_id')
            ->where('v.id', $versionId)->where('v.mode', $mode)->where('v.status', 'published')
            ->first(['v.id', 'v.template_id', 'v.version_number', 'v.mode', 'v.menu_pattern', 't.title']);
        $session = DB::table('cooking_sessions')->where('version_id', $versionId)->where('id', $sessionId)->first();
        if (! $version || ! $session) {
            throw new DomainException('Cooking session unavailable.');
        }

        $weekStart = $this->weekStart($assignment, $week);
        $pattern = json_decode($version->menu_pattern, true, flags: JSON_THROW_ON_ERROR);
        $summary = $this->sessionSummary($version, $session, $pattern, $weekStart);
        $content = json_decode($session->content, true, flags: JSON_THROW_ON_ERROR);
        if (! DB::selectOne('SELECT cooking_content_valid(?::jsonb) AS valid', [$session->content])->valid) {
            throw new DomainException('Invalid published cooking content.');
        }
        $stages = $this->resolveStages((int) $session->id, $content['stages']);
        $equipment = DB::table('cooking_session_equipment')->where('version_id', $versionId)->where('session_id', $sessionId)->orderBy('position')->get()
            ->map(fn ($item) => ['id' => (string) $item->equipment_id, 'name' => $item->name_snapshot,
                'image_url' => PublicImageUrl::safe($item->image_url_snapshot)])->all();
        $ingredients = $this->ingredientsForSession((int) $versionId, (int) $sessionId);
        $date = $weekStart->addDays(((int) $session->day) - 1)->toDateString();

        return ['assignment' => $this->assignmentPayload($assignment), 'week' => $week, 'mode' => $mode, 'date' => $date,
            'template_version' => ['id' => (string) $version->id, 'template_id' => (string) $version->template_id,
                'title' => $version->title, 'version_number' => (int) $version->version_number],
            'session' => ['id' => (string) $session->id, 'day' => (int) $session->day, 'position' => (int) $session->position,
                'introduction' => $session->introduction, 'equipment_notes' => $session->equipment,
                'estimated_seconds' => (int) $session->estimated_seconds, 'dishes' => $summary['dishes'],
                'equipment' => $equipment, 'ingredients' => $ingredients, 'stages' => $stages],
            'progress_scope' => ['client_id' => (string) $client, 'assignment_id' => (string) $assignment->id,
                'week' => $week, 'date' => $date, 'cooking_version_id' => (string) $version->id,
                'session_id' => (string) $session->id,
                'expires_at' => $assignment->end_date ? CarbonImmutable::parse($assignment->end_date)->endOfDay()->toIso8601String() : CarbonImmutable::parse($date)->addDays(30)->endOfDay()->toIso8601String()]];
    }

    private function currentAssignment(int $client, string $today): ?object
    {
        $assignments = DB::table('client_meal_plan_assignments as a')
            ->join('meal_plan_versions as v', 'v.id', '=', 'a.meal_plan_version_id')
            ->join('meal_plans as p', 'p.id', '=', 'v.meal_plan_id')
            ->where('a.client_id', $client)->where('a.start_date', '<=', $today)
            ->where(fn ($query) => $query->whereNull('a.end_date')->orWhere('a.end_date', '>=', $today))
            ->where('v.status', 'published')->where(fn ($owner) => $owner->whereNull('p.owner_client_id')->orWhere('p.owner_client_id', $client))
            ->get(['a.id', 'a.client_id', 'a.start_date', 'a.end_date', 'a.meal_plan_version_id', 'v.meal_plan_id', 'p.title', 'p.week_start_weekday']);
        if ($assignments->count() > 1) {
            throw new DomainException('Ambiguous current assignment.');
        }

        return $assignments->first();
    }

    private function available(int $client, int $assignment, int $version, int $week, int $mode, string $today): bool
    {
        $available = (bool) DB::selectOne('SELECT cooking_available(?,?,?,?,?,?::date) AS available',
            [$client, $assignment, $version, $week, $mode, $today])->available;
        if ($available === false) {
            return false;
        }
        // SQL17 permits a client's own personal recipe inside an owner plan. T5.7b
        // deliberately excludes every personal recipe from the reusable client flow.
        $personal = (bool) DB::selectOne("SELECT EXISTS (
            SELECT FROM cooking_template_versions v
            CROSS JOIN LATERAL jsonb_array_elements(v.menu_pattern->'items') item
            JOIN recipe_versions rv ON rv.id=(item->>'recipe_version_id')::integer
            JOIN recipes r ON r.id=rv.recipe_id
            WHERE v.id=? AND r.owner_client_id IS NOT NULL
        ) AS personal", [$version])->personal;

        return $personal === false;
    }

    private function weekStart(object $assignment, int $week): CarbonImmutable
    {
        if ($week < 1 || $week > 4681) {
            throw new DomainException('Week unavailable.');
        }
        $start = CarbonImmutable::parse($assignment->start_date, config('app.timezone'));
        $offset = ($start->isoWeekday() - (int) $assignment->week_start_weekday + 7) % 7;

        return $start->subDays($offset)->addDays(($week - 1) * 7);
    }

    private function assignmentPayload(object $assignment): array
    {
        return ['id' => (string) $assignment->id, 'start_date' => $assignment->start_date, 'end_date' => $assignment->end_date,
            'meal_plan_version_id' => (string) $assignment->meal_plan_version_id, 'meal_plan_title' => $assignment->title];
    }

    private function emptyModes(): array
    {
        return array_map(fn (int $mode) => ['mode' => $mode, 'available' => false, 'templates' => []], [1, 2, 7]);
    }

    private function sessionSummary(object $version, object $session, array $pattern, CarbonImmutable $weekStart): array
    {
        $outputs = DB::table('cooking_outputs as o')->join('cooking_output_balances as b', 'b.id', '=', 'o.id')
            ->where('o.version_id', $version->id)->where('o.session_id', $session->id)
            ->orderBy('o.id')->get(['o.id', 'o.recipe_version_id', 'o.format_id', 'o.portion_divisor', 'b.cooked', 'b.allocated', 'b.left_over']);
        $dishes = [];
        foreach ($outputs as $output) {
            $allocations = DB::table('cooking_allocations')->where('version_id', $version->id)->where('output_id', $output->id)
                ->orderBy('day')->orderBy('meal_position')->orderBy('item_position')->get();
            $slots = [];
            foreach ($allocations as $allocation) {
                $slot = collect($pattern['items'] ?? [])->first(fn ($item) => (int) $item['day'] === (int) $allocation->day
                    && (int) $item['meal_position'] === (int) $allocation->meal_position && (int) $item['item_position'] === (int) $allocation->item_position);
                if (! $slot || (int) ($slot['recipe_version_id'] ?? 0) !== (int) $output->recipe_version_id
                    || (int) ($slot['format_id'] ?? 0) !== (int) ($output->format_id ?? 0)
                    || ! BigDecimal::of((string) $slot['divisor'])->isEqualTo(BigDecimal::of((string) $output->portion_divisor))
                    || ! BigDecimal::of((string) $slot['quantity'])->isEqualTo(BigDecimal::of((string) $allocation->portions))) {
                    throw new DomainException('Cooking allocation mismatch.');
                }
                $title = $slot['recipe_snapshot']['version']['title'] ?? null;
                if (! is_string($title) || $title === '') {
                    throw new DomainException('Frozen recipe title unavailable.');
                }
                $slots[] = ['day' => (int) $allocation->day,
                    'date' => $weekStart->addDays(((int) $allocation->day) - 1)->toDateString(),
                    'portions' => (string) $allocation->portions];
            }
            if ($slots === []) {
                throw new DomainException('Unused cooking output.');
            }
            $dishes[] = ['output_id' => (string) $output->id, 'recipe_version_id' => (string) $output->recipe_version_id,
                'title' => $title, 'cooked_portions' => (string) $output->cooked,
                'allocated_portions' => (string) $output->allocated, 'left_over_portions' => (string) $output->left_over,
                'allocations' => $slots];
        }
        if ($dishes === []) {
            throw new DomainException('Cooking session has no outputs.');
        }

        return ['id' => (string) $session->id, 'day' => (int) $session->day,
            'date' => $weekStart->addDays(((int) $session->day) - 1)->toDateString(),
            'position' => (int) $session->position, 'estimated_seconds' => (int) $session->estimated_seconds,
            'dishes' => $dishes];
    }

    private function resolveStages(int $session, array $stages): array
    {
        foreach ($stages as &$stage) {
            foreach ($stage['blocks'] as &$block) {
                if ($block['type'] === 'image') {
                    $block['url'] = PublicImageUrl::safe($block['url']);
                } elseif ($block['type'] === 'action') {
                    foreach ($block['parts'] as &$part) {
                        if (! is_array($part)) {
                            continue;
                        }
                        $token = json_decode(DB::selectOne('SELECT cooking_token(?,?::jsonb) AS token',
                            [$session, json_encode($part, JSON_THROW_ON_ERROR)])->token, true, flags: JSON_THROW_ON_ERROR);
                        if (! is_string($token['unit_label'] ?? null) || ($token['label_source'] ?? null) === 'unavailable') {
                            throw new DomainException('Frozen quantity label unavailable.');
                        }
                        $part = ['type' => 'token', 'value' => $token['value'], 'metric' => $token['metric'],
                            'unit_label' => $token['unit_label'], 'sources' => $token['sources']];
                    }
                    unset($part);
                }
            }
            unset($block);
        }
        unset($stage);

        return $stages;
    }

    private function ingredientsForSession(int $version, int $session): array
    {
        $outputs = DB::table('cooking_outputs')->where('version_id', $version)->where('session_id', $session)->orderBy('id')->get();
        $totals = [];
        foreach ($outputs as $output) {
            $snapshot = DB::selectOne('SELECT cooking_recipe_snapshot(?) AS snapshot', [$output->recipe_version_id])->snapshot;
            if (! is_string($snapshot)) {
                throw new DomainException('Frozen recipe snapshot unavailable.');
            }
            $recipe = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            $versionSnapshot = $recipe['version'] ?? [];
            if (($versionSnapshot['status'] ?? null) !== 'published') {
                throw new DomainException('Frozen recipe version unavailable.');
            }
            $format = null;
            if ($output->format_id !== null) {
                $format = collect($recipe['formats'] ?? [])->firstWhere('id', (int) $output->format_id);
                if (! $format || ! in_array($format['code'] ?? null, ['single', 'week', 'half_week'], true)) {
                    throw new DomainException('Frozen recipe format unavailable.');
                }
            }
            $quantityRows = collect($recipe['quantities'] ?? [])->where('format_id', $output->format_id)
                ->groupBy('recipe_ingredient_id');
            $rows = $recipe['rows'] ?? [];
            if ($rows === []) {
                throw new DomainException('Frozen ingredient rows unavailable.');
            }
            foreach ($rows as $row) {
                $source = $row;
                $nameContext = $row['ingredient_snapshot'] ?? null;
                if (in_array($format['code'] ?? null, ['week', 'half_week'], true)) {
                    $quantities = $quantityRows->get($row['id']);
                    if ($quantities === null || $quantities->count() !== 1) {
                        throw new DomainException('Frozen batch quantity unavailable.');
                    }
                    $source = $quantities->first();
                    if (($source['format_code'] ?? null) !== $format['code']
                        || (int) ($source['recipe_version_id'] ?? 0) !== (int) $output->recipe_version_id) {
                        throw new DomainException('Frozen batch quantity context mismatch.');
                    }
                    $input = $source['input_snapshot'] ?? null;
                    $input = is_string($input) ? json_decode($input, true, flags: JSON_THROW_ON_ERROR) : $input;
                    $nameContext ??= is_array($input) ? ($input['ingredient_snapshot'] ?? null) : null;
                }
                $context = is_string($nameContext) ? json_decode($nameContext, true, flags: JSON_THROW_ON_ERROR) : $nameContext;
                $name = is_array($context) ? ($context['name'] ?? null) : null;
                $grams = $source['quantity_grams'] ?? null;
                $ingredientId = $row['ingredient_id'] ?? null;
                if (! is_string($name) || trim($name) === '' || ! is_string($grams) && ! is_numeric($grams)
                    || ! is_numeric($ingredientId) || BigDecimal::of((string) $grams)->isLessThanOrEqualTo(0)) {
                    throw new DomainException('Frozen ingredient value unavailable.');
                }
                $key = (string) $ingredientId.'|'.hash('sha256', $name);
                if (! isset($totals[$key])) {
                    $totals[$key] = ['id' => (string) $ingredientId, 'name' => $name, 'quantity_grams' => '0',
                        'image_url' => PublicImageUrl::safe(is_array($context) ? ($context['image_url'] ?? null) : null)];
                }
                $totals[$key]['quantity_grams'] = (string) BigDecimal::of($totals[$key]['quantity_grams'])->plus((string) $grams);
            }
        }
        if ($totals === []) {
            throw new DomainException('Frozen ingredient list unavailable.');
        }
        $result = array_values($totals);
        usort($result, fn (array $left, array $right) => [$left['name'], $left['id']] <=> [$right['name'], $right['id']]);

        return $result;
    }
}

<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Recipes\BatchEditor;
use App\Services\Recipes\RecipeTokens;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class RecipeBatches extends Page
{
    protected string $view = 'filament.pages.recipe-batches';

    protected static ?string $title = 'Рецепти A/B/C';

    #[Locked]
    public ?int $version = null;

    #[Locked]
    public string $revision = '';

    public array $payload = [];

    public array $preview = [];

    public array $presetChoices = [];

    public string $message = '';

    public string $boundary = '';

    #[Locked]
    public bool $canCreateVersion = false;

    public bool $publicationConfirmed = false;

    public string $copyOwnerId = '';

    #[Locked]
    public ?int $ownerClientId = null;

    #[Locked]
    public ?int $parentRecipeId = null;

    #[Locked]
    public bool $isLegacy = false;

    #[Locked]
    public string $legacyStatus = '';

    public array $legacyPreview = [];

    public bool $legacySourcesAccepted = false;

    public bool $legacyReviewed = false;

    public function previewLegacy(): void
    {
        $this->legacyPreview = [];
        $this->legacySourcesAccepted = $this->legacyReviewed = false;
        $this->attempt(function () {
            $this->legacyPreview = app(BatchEditor::class)->previewLegacy($this->actor(), $this->version, $this->revision);
        });
    }

    public function cancelLegacy(): void
    {
        $this->actor();
        $this->legacyPreview = [];
        $this->legacySourcesAccepted = $this->legacyReviewed = false;
    }

    public function convertLegacy(): void
    {
        $this->attempt(function () {
            $id = app(BatchEditor::class)->convertLegacy($this->actor(), $this->version, $this->revision,
                $this->legacyPreview['token'] ?? '', $this->legacySourcesAccepted, $this->legacyReviewed);
            $this->redirect(self::getUrl(['version' => $id]));
        });
    }

    public static function canAccess(): bool
    {
        return config('recipes.batch_editor_enabled') && Filament::auth()->user()?->role === User::ROLE_ADMIN;
    }

    private function actor(): User
    {
        $actor = Filament::auth()->user();
        app(BatchEditor::class)->authorize($actor);

        return $actor;
    }

    public function mount(): void
    {
        $this->actor();
        if ($id = request()->integer('version')) {
            $this->version = $id;
            $this->reload();
        }
    }

    public function createDraft(): void
    {
        $id = app(BatchEditor::class)->create($this->actor());
        $this->redirect(self::getUrl(['version' => $id]));
    }

    public function reload(): void
    {
        $state = app(BatchEditor::class)->open($this->actor(), $this->version);
        $this->isLegacy = $state['legacy'] ?? false;
        $this->legacyStatus = $this->isLegacy ? $state['status'] : '';
        $this->legacyPreview = [];
        $this->legacySourcesAccepted = $this->legacyReviewed = false;
        $this->canCreateVersion = ! isset($state['boundary']) && $state['status'] === 'published';
        $this->boundary = $state['boundary'] ?? ($state['status'] === 'published' ? 'Опублікована версія незмінна. Створіть нову draft-версію для редагування A/B/C.' : '');
        $this->ownerClientId = $state['owner_client_id'] ?? null;
        $this->parentRecipeId = $state['parent_recipe_id'] ?? null;
        $this->payload = $state['payload'] ?? [];
        $this->revision = $state['revision'] ?? '';
        $this->preview = [];
        $this->presetChoices = [];
        foreach ($this->payload['rows'] ?? [] as $index => $row) {
            $this->presetChoices[$index] = ($row['week']['rounding'] ?? 'exact') === 'nearest' ? 'nearest100' : (($row['week']['rounding'] ?? 'exact') === 'ceil' ? $row['week']['step'] : 'exact');
        }
        $this->publicationConfirmed = false;
        $this->copyOwnerId = '';
    }

    public function createVersion(): void
    {
        $this->attempt(function () {
            $id = app(BatchEditor::class)->createVersion($this->actor(), $this->version, $this->revision);
            $this->redirect(self::getUrl(['version' => $id]));
        });
    }

    public function createPersonalCopy(): void
    {
        $this->attempt(function () {
            $actor = $this->actor();
            if (! ctype_digit($this->copyOwnerId) || (int) $this->copyOwnerId < 1 || (int) $this->copyOwnerId > 2147483647) {
                throw ValidationException::withMessages(['batch' => 'Оберіть клієнта для персональної копії.']);
            }
            $id = app(BatchEditor::class)->createPersonalCopy($actor, $this->version, $this->revision, (int) $this->copyOwnerId);
            $this->redirect(self::getUrl(['version' => $id]));
        });
    }

    public function updatedPayload(): void
    {
        $this->publicationConfirmed = false;
        $this->preview = [];
        $this->calculate();
    }

    public function addRow(): void
    {
        $this->actor();
        $this->payload['rows'][] = ['id' => 'pending:'.Str::uuid(), 'ingredient_id' => '', 'quantity' => '', 'component_id' => '',
            'week' => ['mode' => 'auto', 'quantity' => '', 'uom_id' => '', 'rounding' => 'exact', 'step' => '0.01', 'confirmed' => false],
            'half_week' => ['mode' => 'auto', 'quantity' => '', 'uom_id' => '', 'rounding' => 'exact', 'step' => '0.01', 'confirmed' => false], 'auto_policies' => []];
        $this->updatedPayload();
    }

    public function removeRow(int $index): void
    {
        $this->actor();
        unset($this->presetChoices[$index]);
        $this->presetChoices = array_values($this->presetChoices);
        unset($this->payload['rows'][$index]);
        $this->payload['rows'] = array_values($this->payload['rows']);
        $this->updatedPayload();
    }

    public function addComponent(): void
    {
        $this->actor();
        $this->payload['components'][] = ['id' => 'new-'.Str::uuid(), 'title' => 'Компонент'];
        $this->updatedPayload();
    }

    public function addDirection(): void
    {
        $this->actor();
        $this->payload['directions'][] = '';
        $this->updatedPayload();
    }

    public function addEquipment(): void
    {
        $this->actor();
        $this->payload['equipment'][] = ['id' => 'new-'.Str::uuid(), 'kitchen_equipment_id' => '', 'min_value' => '', 'uom_id' => '', 'scaling_mode' => 'fixed', 'tip' => ''];
        $this->preview = [];
    }

    public function removeDetail(string $type, int $index): void
    {
        $this->actor();
        abort_unless(in_array($type, ['components', 'directions', 'equipment'], true), 422);
        if ($type === 'components') {
            $id = $this->payload['components'][$index]['id'];
            foreach ($this->payload['rows'] as &$row) {
                if ($row['component_id'] === $id) {
                    $row['component_id'] = '';
                }
            }
            unset($row);
        }
        unset($this->payload[$type][$index]);
        $this->payload[$type] = array_values($this->payload[$type]);
        $this->updatedPayload();
    }

    public function resetQuantity(int $index, string $code): void
    {
        $this->actor();
        abort_unless(in_array($code, ['week', 'half_week'], true), 422);
        $this->payload['rows'][$index][$code] = ['mode' => 'auto', 'quantity' => '', 'uom_id' => '', 'rounding' => 'exact', 'step' => '0.01', 'confirmed' => false];
        $this->updatedPayload();
    }

    public function acceptPolicy(int $index): void
    {
        $this->actor();
        $row = $this->payload['rows'][$index];
        $unit = DB::table('uoms')->find($row['week']['uom_id']);
        abort_unless($unit, 422);
        // Явне прийняття кроку для успадкованої UOM, без вгадування фасування.
        $this->payload['rows'][$index]['auto_policies'][$unit->code] = ['rounding' => 'ceil', 'step' => $row['half_week']['step'], 'confirmed' => true];
        $this->updatedPayload();
    }

    public function applyPreset(int $index): void
    {
        $this->attempt(function () use ($index) {
            $this->actor();
            $row = $this->payload['rows'][$index];
            $preset = $this->presetChoices[$index] ?? 'exact';
            $unit = DB::table('uoms')->find($row['week']['uom_id'] ?: DB::table('uoms')->where('code', 'g')->value('id'));
            if (! $unit) {
                throw ValidationException::withMessages(['batch' => 'Оберіть дозволену одиницю B.']);
            }
            if ($preset === 'exact' || $preset === 'nearest100') {
                if ($unit->code !== 'g') {
                    throw ValidationException::withMessages(['batch' => 'Для точних грамів або округлення до 100 г оберіть одиницю g.']);
                }
                $policy = ['rounding' => $preset === 'exact' ? 'exact' : 'nearest', 'step' => $preset === 'exact' ? '0.01' : '100.00', 'confirmed' => true];
            } else {
                if (! in_array($preset, ['1.00', '0.50', '0.25'], true) || $unit->kind !== 'count'
                    || ! DB::table('ingredient_uoms')->where('ingredient_id', $row['ingredient_id'])->where('uom_id', $unit->id)->exists()) {
                    throw ValidationException::withMessages(['batch' => 'Округлення упаковок потребує дозволеної лічильної UOM, конверсії та кроку 1, 0.50 або 0.25.']);
                }
                $policy = ['rounding' => 'ceil', 'step' => $preset, 'confirmed' => true];
                $this->payload['rows'][$index]['auto_policies'][$unit->code] = $policy;
            }
            $this->payload['rows'][$index]['week'] = ['mode' => 'auto', 'quantity' => '', 'uom_id' => (string) $unit->id] + $policy;
            $this->updatedPayload();
        });
    }

    public function manualQuantity(int $index, string $code): void
    {
        $this->actor();
        abort_unless(in_array($code, ['week', 'half_week'], true), 422);
        $q = $this->preview['evaluation']['rows'][$this->payload['rows'][$index]['id']][$code] ?? null;
        abort_unless($q, 422);
        $this->payload['rows'][$index][$code] = ['mode' => 'manual', 'quantity' => $q['quantity'],
            'uom_id' => (string) DB::table('uoms')->where('code', $q['uom'])->value('id')] + $q['policy'];
        $this->updatedPayload();
    }

    public function resetEquipment(int $index, string $code): void
    {
        $this->actor();
        abort_unless(in_array($code, ['week', 'half_week'], true), 422);
        $this->payload['equipment'][$index][$code] = ['mode' => 'auto', 'min_value' => '', 'uom_id' => '', 'tip' => ''];
        $this->updatedPayload();
    }

    public function manualEquipment(int $index, string $code): void
    {
        $this->actor();
        abort_unless(in_array($code, ['week', 'half_week'], true), 422);
        $q = $this->preview['equipment'][$this->payload['equipment'][$index]['id']][$code] ?? null;
        abort_unless($q, 422);
        $this->payload['equipment'][$index][$code] = ['mode' => 'manual', 'min_value' => $q['min_value'] ?? '', 'uom_id' => (string) ($q['uom_id'] ?? ''), 'tip' => $q['tip']];
        $this->updatedPayload();
    }

    public function deletionImpact(string $type, int $index): string
    {
        $row = $this->payload[$type][$index];
        $id = $row['id'];
        if ($type === 'components') {
            $count = count(array_filter($this->payload['rows'], fn ($r) => $r['component_id'] === $id));

            return "Видалити компонент? $count інгредієнтів залишаться без компонента в A/B/C.";
        }
        $kind = $type === 'equipment' ? 'equipment' : 'ingredient';
        $count = count(array_filter($this->payload['directions'], fn ($text) => str_contains($text, '{{'.$kind.':'.$id.':')));

        return "Видалити рядок у всіх A/B/C разом із ручними значеннями? Пов’язаних кроків із токенами: $count. Їх треба виправити перед публікацією.";
    }

    public function calculate(): void
    {
        $this->attempt(function () {
            abort_unless($this->boundary === '', 403);
            $p = app(BatchEditor::class)->preview($this->actor(), $this->version, $this->revision, $this->payload);
            $this->preview = array_intersect_key($p, array_flip(['token', 'evaluation', 'diff', 'equipment', 'equipment_diff']));
            $this->message = 'Preview готовий. Збереження та публікація — окремі явні дії.';
        });
    }

    public function saveDraft(bool $refresh = false, bool $acknowledge = false): void
    {
        $this->attempt(function () use ($refresh, $acknowledge) {
            app(BatchEditor::class)->save($this->actor(), $this->version, $this->revision, $this->payload, $this->preview['token'] ?? '', $refresh, $acknowledge);
            $this->reload();
            $this->calculate();
            $this->message = 'Чернетку збережено.';
        });
    }

    public function publish(): void
    {
        $this->attempt(function () {
            abort_unless($this->publicationConfirmed, 422);
            app(BatchEditor::class)->publish($this->actor(), $this->version, $this->revision, $this->preview['token'] ?? '');
            $this->reload();
            $this->message = 'Усі три формати опубліковано.';
        });
    }

    private function attempt(callable $action): void
    {
        $this->resetErrorBag();
        try {
            $action();
        } catch (ValidationException $e) {
            $this->preview = [];
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, implode(' ', $messages));
            }
        } catch (QueryException $e) {
            $this->preview = [];
            $this->addError('batch', 'Операцію відхилено, зміни відкочено. Перевірте дані або перезавантажте після конфлікту.');
            report($e);
        }
    }

    public function ingredientToken(string $id): string
    {
        return '{{ingredient:'.$id.':quantity}}';
    }

    public function equipmentToken(string $id): string
    {
        return '{{equipment:'.$id.':quantity}}';
    }

    public function renderedDirections(string $code): array
    {
        try {
            return array_map(fn ($text) => RecipeTokens::render($text, $this->preview['evaluation']['rows'] ?? [], $code, $this->preview['equipment'] ?? []), $this->payload['directions'] ?? []);
        } catch (ValidationException $e) {
            return ['Перевірте токени: рядок видалено або ще не завершено.'];
        }
    }

    protected function getViewData(): array
    {
        $this->actor();

        $sourceOwner = $this->version ? DB::table('recipes')->join('recipe_versions as v', 'v.recipe_id', '=', 'recipes.id')->where('v.id', $this->version)->value('owner_client_id') : null;

        return ['copyClients' => DB::table('clients as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->when($sourceOwner !== null, fn ($q) => $q->where('c.id', $sourceOwner))->orderBy('c.id')
            ->get(['c.id', 'u.first_name', 'u.last_name']), 'versions' => DB::table('recipe_versions')->orderByDesc('id')->limit(100)->get(['id', 'title', 'status', 'editor_contract']),
            'ingredients' => DB::table('ingredients')->orderBy('name')->get(['id', 'name']), 'units' => DB::table('uoms')->orderBy('id')->get(),
            'categories' => DB::table('recipe_categories')->orderBy('name')->get(), 'tools' => DB::table('kitchen_equipment')->orderBy('name')->get(),
            'conversions' => DB::table('ingredient_uoms')->get()->groupBy('ingredient_id')];
    }
}

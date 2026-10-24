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

    public string $message = '';

    public string $boundary = '';

    public bool $publicationConfirmed = false;

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
        $this->boundary = $state['boundary'] ?? ($state['status'] === 'published' ? 'Опублікована версія незмінна. Створення нових версій ще недоступне.' : '');
        $this->payload = $state['payload'] ?? [];
        $this->revision = $state['revision'] ?? '';
        $this->preview = [];
        $this->publicationConfirmed = false;
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

    public function calculate(): void
    {
        $this->attempt(function () {
            abort_unless($this->boundary === '', 403);
            $p = app(BatchEditor::class)->preview($this->actor(), $this->version, $this->revision, $this->payload);
            $this->preview = array_intersect_key($p, array_flip(['token', 'evaluation', 'diff']));
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

    public function renderedDirections(string $code): array
    {
        try {
            return array_map(fn ($text) => RecipeTokens::render($text, $this->preview['evaluation']['rows'] ?? [], $code), $this->payload['directions'] ?? []);
        } catch (ValidationException $e) {
            return ['Перевірте токени: рядок видалено або ще не завершено.'];
        }
    }

    protected function getViewData(): array
    {
        $this->actor();

        return ['versions' => DB::table('recipe_versions')->orderByDesc('id')->limit(100)->get(['id', 'title', 'status', 'editor_contract']),
            'ingredients' => DB::table('ingredients')->orderBy('name')->get(['id', 'name']), 'units' => DB::table('uoms')->orderBy('id')->get(),
            'categories' => DB::table('recipe_categories')->orderBy('name')->get(), 'tools' => DB::table('kitchen_equipment')->orderBy('name')->get()];
    }
}

<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Models\MealPlanDay;
use App\Services\MealPlans\MealItemWriter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class EditMealPlanDay extends ViewMealPlanVersion
{
    protected string $view = 'filament.meal-plans.day';

    #[Locked]
    public int $dayId;

    #[Url]
    public string $search = '';

    #[Url]
    public string $catalogType = '';

    public int $catalogPage = 1;

    public function mount(int|string $record, int|string|null $day = null): void
    {
        abort_unless(ctype_digit((string) $day) && (int) $day > 0 && (int) $day <= 2147483647, 404);
        $this->dayId = (int) $day;
        parent::mount($record);
        $this->week = intdiv($this->day()->day_number - 1, 7) + 1;
    }

    public function hydrate(): void
    {
        parent::hydrate();
        $this->day();
    }

    public function day(): MealPlanDay
    {
        return $this->getRecord()->days()->whereKey($this->dayId)->firstOrFail();
    }

    protected function dayScope(): ?int
    {
        return $this->dayId;
    }

    public function getTitle(): string
    {
        return 'День '.$this->day()->day_number.' · версія #'.$this->getRecord()->id;
    }

    public function dayMeals(): Collection
    {
        return $this->mealsQuery()->with(['type', 'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'items.food.ingredient', 'items.uom'])
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    public function updatedSearch(): void
    {
        $this->catalogPage = 1;
    }

    public function updatedCatalogType(): void
    {
        $this->catalogPage = 1;
    }

    public function catalogRows(): Collection
    {
        Gate::authorize('view', $this->getRecord());

        return DB::table('foods as f')->leftJoin('ingredients as i', 'i.food_id', '=', 'f.id')
            ->leftJoin('recipe_versions as r', fn ($q) => $q->on('r.food_id', '=', 'f.id')->where('r.status', 'published'))
            ->where(fn ($q) => $q->whereNotNull('i.id')->orWhereNotNull('r.id'))
            ->when(in_array($this->catalogType, ['ingredient', 'recipe_version'], true), fn ($q) => $q->where('f.food_type', $this->catalogType))
            ->whereRaw('coalesce(i.name, r.title) ilike ?', ['%'.mb_substr($this->search, 0, 150).'%'])
            ->selectRaw('f.id, f.food_type, coalesce(i.name, r.title) as label, r.version_number, r.editor_contract')
            ->orderBy('f.id')->offset((max(1, min($this->catalogPage, 100000)) - 1) * 12)->limit(13)->get();
    }

    public function catalogNext(int $delta): void
    {
        $this->catalogPage = max(1, min($this->catalogPage + ($delta > 0 ? 1 : -1), 100000));
    }

    protected function weekDeletionActions(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();

        return [Action::make('overview')->label('До огляду тижня')->url(MealPlanVersionResource::getUrl('view', ['record' => $this->getRecord(), 'week' => $this->week])),
            ...array_values(array_filter($actions, fn ($a) => $a instanceof Action && in_array($a->getName(), ['addMeal', 'editMeal', 'deleteMeal'], true))),
            ...$this->itemActions()];
    }

    protected function catalogForm(array $arguments): array
    {
        if (! isset($arguments['food'])) {
            return ['quantity' => null, 'sort_order' => 0];
        }
        Gate::authorize('editItems', $this->getRecord());
        $writer = app(MealItemWriter::class);
        if (! hash_equals($writer->dayToken(auth()->user(), $this->getRecord(), $this->dayId), (string) ($arguments['expected'] ?? ''))) {
            throw ValidationException::withMessages(['expected' => 'День змінився. Оновіть сторінку перед додаванням.']);
        }
        $meal = $this->mealsQuery()->findOrFail((int) ($arguments['meal'] ?? 0));
        $catalog = $writer->catalog(auth()->user(), $this->getRecord(), (int) $arguments['food']);

        return ['meal_id' => $meal->id, 'food_id' => $catalog['food']->id,
            'expected' => $writer->mealToken(auth()->user(), $this->getRecord(), $meal->id),
            'catalog_expected' => $catalog['token'], 'quantity' => null, 'uom_id' => null,
            'sort_order' => min(32767, (int) $meal->items()->max('sort_order') + 1),
            'recipe_version_id' => null, 'recipe_batch_format_id' => null, 'recipe_portion_divisor' => null];
    }

    /** DOM залишається канонічним до відповіді сервера: невдалий drop нічого не переставляє. */
    public function placeItem(int $item, int $meal, ?int $before, string $expected): void
    {
        Gate::authorize('editItems', $this->getRecord());
        try {
            app(MealItemWriter::class)->place(auth()->user(), $this->getRecord(), $this->dayId, $item, $meal, $before, $expected);
            Notification::make()->success()->title('Позицію впорядковано')->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('День змінився')->body(implode(' ', array_merge(...array_values($e->errors()))))->send();
        }
        $this->getRecord()->refresh();
    }

    protected function done(): void
    {
        Notification::make()->success()->title('Зміни дня збережено')->send();
        $this->redirect(MealPlanVersionResource::getUrl('day', ['record' => $this->getRecord(), 'day' => $this->dayId]), navigate: false);
    }
}

<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Models\Food;
use App\Models\MealItem;
use App\Services\MealPlans\MealItemWriter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** UI лише збирає аргументи спільних серверних команд. */
trait EditsMealItems
{
    protected function itemActions(): array
    {
        $allowed = fn () => Gate::allows('editItems', $this->getRecord());
        $actions = [Action::make('addItem')->label('Додати позицію')->modalSubmitActionLabel('Додати')->authorize($allowed)
            ->fillForm(fn (array $arguments) => $this->catalogForm($arguments))
            ->schema(fn () => [
                $this->itemMealSelector()->afterStateUpdated(function ($state, Set $set): void {
                    Gate::authorize('editItems', $this->getRecord());
                    $set('expected', $state ? app(MealItemWriter::class)->mealToken(auth()->user(), $this->getRecord(), (int) $state) : null);
                }),
                Hidden::make('expected')->required(),
                ...$this->itemFields(),
            ])->action(function (array $data, Action $action): void {
                $this->command($action, fn () => app(MealItemWriter::class)->save(auth()->user(), $this->getRecord(), $data));
            }),
            Action::make('editItem')->label('Змінити позицію')->modalSubmitActionLabel('Зберегти')->authorize($allowed)
                ->schema(fn () => [$this->itemSelector(), Hidden::make('meal_id')->required(), Hidden::make('expected')->required(), ...$this->itemFields(true)])
                ->action(function (array $data, Action $action): void {
                    $this->command($action, fn () => app(MealItemWriter::class)->save(auth()->user(), $this->getRecord(), $data, (int) $data['item_id']));
                }),
            Action::make('moveItem')->label('Перенести позицію')->modalSubmitActionLabel('Перенести')->authorize($allowed)
                ->schema(fn () => [$this->itemSelector(), Hidden::make('expected')->required(), $this->itemMealSelector(), $this->itemOrder()])
                ->action(function (array $data, Action $action): void {
                    $this->command($action, fn () => app(MealItemWriter::class)->move(auth()->user(), $this->getRecord(), (int) $data['item_id'], (int) $data['meal_id'], (int) $data['sort_order'], $data['expected']));
                }),
            Action::make('deleteItem')->label('Видалити позицію')->color('danger')->authorize($allowed)
                ->requiresConfirmation()->modalHeading('Видалити позицію')->modalDescription('Позицію буде видалено з цієї чернетки. Дію неможливо скасувати. Продукт і рецепт збережуться в каталозі.')
                ->modalSubmitActionLabel('Видалити позицію')->modalCancelActionLabel('Скасувати')
                ->schema(fn () => [$this->itemSelector(), Hidden::make('expected')->required()])
                ->action(function (array $data, Action $action): void {
                    $this->command($action, fn () => app(MealItemWriter::class)->delete(auth()->user(), $this->getRecord(), (int) $data['item_id'], $data['expected']));
                }),
        ];

        return [ActionGroup::make($actions)->label('Позиції')->button()->visible($allowed)];
    }

    protected function catalogForm(array $arguments): array
    {
        return ['quantity' => '1.00', 'sort_order' => 0];
    }

    protected function itemMealSelector(): Select
    {
        return Select::make('meal_id')->label('Прийом їжі')->required()->native(false)->searchable()->preload()->live()
            ->options(fn () => $this->mealsQuery()->with(['day', 'type'])->get()
                ->sortBy([['day.day_number', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])
                ->mapWithKeys(fn ($m) => [$m->id => 'День '.$m->day->day_number.' · '.$m->type->title.($m->title ? ' · '.$m->title : '')])->all());
    }

    protected function versionItems(): Builder
    {
        return MealItem::whereIn('meal_id', $this->mealsQuery()->select('id'));
    }

    protected function itemSelector(): Select
    {
        return Select::make('item_id')->label('Позиція')->required()->searchable()->preload()->native(false)->live()
            ->options(fn () => $this->versionItems()->with(['food', 'meal.day', 'meal.type', 'uom'])->orderBy('id')->get()->mapWithKeys(fn ($i) => [$i->id => 'День '.$i->meal->day->day_number.' · '.$i->meal->type->title.' · '.$i->food->label.' · '.$i->quantity.' '.$i->uom->label])->all())
            ->afterStateUpdated(function ($state, Set $set): void {
                Gate::authorize('editItems', $this->getRecord());
                $item = $this->versionItems()->find($state);
                foreach (['food_id', 'meal_id', 'quantity', 'uom_id', 'sort_order', 'recipe_version_id', 'recipe_batch_format_id', 'recipe_portion_divisor'] as $field) {
                    $set($field, $item?->{$field});
                }
                $set('expected', $item ? MealItemWriter::token($item) : null);
                $set('catalog_expected', $item ? $this->itemCatalog($item->food_id)['token'] : null);
            });
    }

    protected function itemCatalog(mixed $food): ?array
    {
        if (! $food || ! ctype_digit((string) $food)) {
            return null;
        }

        return app(MealItemWriter::class)->catalog(auth()->user(), $this->getRecord(), (int) $food);
    }

    protected function foodChoice(Food $food): string
    {
        if ($food->food_type === 'ingredient') {
            return 'Інгредієнт · '.$food->label;
        }
        $version = DB::table('recipe_versions')->where('food_id', $food->id)->first();

        return 'Рецепт · '.$food->label.' · версія '.$version?->version_number.' · '.$version?->published_at;
    }

    protected function itemOrder(): TextInput
    {
        return TextInput::make('sort_order')->label('Порядок у прийомі')->integer()->minValue(-32768)->maxValue(32767)->default(0)->required();
    }

    protected function itemFields(bool $editing = false): array
    {
        $fields = [
            Select::make('food_id')->label('Продукт або опублікований рецепт')->native(false)->searchable()->preload()->live()->required()
                ->helperText('Пошук інгредієнтів і точних опублікованих версій. Якщо каталог порожній — спочатку додайте продукт у довіднику.')
                ->getSearchResultsUsing(fn (string $search) => Food::where(function ($q) use ($search): void {
                    $q->whereHas('ingredient', fn ($i) => $i->where('name', 'ilike', '%'.$search.'%'))
                        ->orWhereIn('id', DB::table('recipe_versions')->where('status', 'published')->where('title', 'ilike', '%'.$search.'%')->select('food_id'));
                })->orderBy('id')->limit(50)->get()->mapWithKeys(fn ($f) => [$f->id => $this->foodChoice($f)])->all())
                ->getOptionLabelUsing(fn ($value) => ($food = Food::find($value)) ? $this->foodChoice($food) : null)
                ->disabled($editing)->dehydrated()
                ->afterStateUpdated(function ($state, Set $set): void {
                    $catalog = $this->itemCatalog($state);
                    $set('catalog_expected', $catalog['token'] ?? null);
                    $set('uom_id', $this->dayScope() ? null : ($catalog['default'] ?? null));
                    foreach (['recipe_version_id', 'recipe_batch_format_id', 'recipe_portion_divisor'] as $field) {
                        $set($field, null);
                    }
                }),
            Hidden::make('catalog_expected')->required(),
            TextInput::make('quantity')->label('Споживана кількість')->default($this->dayScope() ? null : '1.00')->required()->live(onBlur: true)
                ->helperText('Додатне число, до двох знаків після крапки. Для рецепта — кількість порцій, наприклад 0.5.'),
            Select::make('uom_id')->label('Одиниця')->native(false)->required()->live()
                ->options(fn (Get $get) => ($this->itemCatalog($get('food_id'))['units'] ?? collect())->pluck('label', 'id')->all())
                ->helperText('Для рецепта — лише порція. Для інгредієнта — грами або дозволена конверсія.'),
            Hidden::make('recipe_version_id'),
            Select::make('recipe_batch_format_id')->label('Формат фізичної партії')->native(false)->live()
                ->visible(fn (Get $get) => ($this->itemCatalog($get('food_id'))['root']->editor_contract ?? null) === 'batch_v1')
                ->options(fn (Get $get) => ($this->itemCatalog($get('food_id'))['formats'] ?? collect())->mapWithKeys(fn ($f) => [$f->id => ['single' => 'A — одна порція', 'week' => 'B — партія на 7 порцій', 'half_week' => 'C — половина тижневої партії'][$f->code]])->all())
                ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                    $catalog = $this->itemCatalog($get('food_id'));
                    $set('recipe_version_id', $state ? ($catalog['root']->id ?? null) : null);
                    $set('recipe_portion_divisor', null);
                }),
            Select::make('recipe_portion_divisor')->label('Поділ партії на порції')->native(false)->live()
                ->visible(fn (Get $get) => ($this->itemCatalog($get('food_id'))['root']->editor_contract ?? null) === 'batch_v1')
                ->options(function (Get $get): array {
                    $format = ($this->itemCatalog($get('food_id'))['formats'] ?? collect())->firstWhere('id', (int) $get('recipe_batch_format_id'));

                    return match ($format?->code) {
                        'single' => [1 => 'A/1 — ціла одна порція'], 'week' => [7 => 'B/7 — одна сьома партії'],
                        'half_week' => [3 => 'C/3 — одна третина половини партії', 4 => 'C/4 — одна четверта половини партії'], default => [],
                    };
                })->helperText('Оберіть явно. Кількість 0.5 означає половину обраної порції; фізична партія рецепта не змінюється.'),
            $this->itemOrder(),
            Text::make(function (Get $get): string {
                try {
                    $portion = app(MealItemWriter::class)->preview(auth()->user(), $this->getRecord(), $get());
                    $n = $portion->nutrition;

                    return 'На споживану кількість: '.$n['kcal'].' ккал · Б '.$n['protein'].' г · Ж '.$n['fat'].' г · В '.$n['carbs'].' г. Формат: '.MealPlanVersionResource::portionLabel($portion->kind);
                } catch (ValidationException) {
                    return 'Оберіть продукт, одиницю, кількість і, для batch рецепта, явний формат та поділ. Тут з’явиться серверний розрахунок КБЖВ.';
                }
            })->columnSpanFull(),
        ];
        if ($editing) {
            foreach ($fields as $field) {
                if (! $field instanceof Hidden) {
                    $field->extraAttributes(['wire:loading.attr' => 'inert', 'wire:target' => 'mountedActions.0.data.item_id']);
                }
            }
        }

        return $fields;
    }
}

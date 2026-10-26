<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Models\Meal;
use App\Models\MealType;
use App\Services\MealPlans\MealPlanStructure;
use App\Services\MealPlans\MealPlanWeekRepeater;
use App\Support\Presentation\MealPlanDisplay;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ViewMealPlanVersion extends ViewRecord
{
    use EditsMealItems;

    protected string $view = 'filament.meal-plans.week';

    #[Url]
    public int $week = 1;

    #[Locked]
    public string $repeatSummary = '';

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function selectedWeek(): Collection
    {
        $total = $this->weekCount();
        $number = max(1, min($this->week, max(1, $total)));

        return $this->getRecord()->days()->whereBetween('day_number', [($number - 1) * 7 + 1, $number * 7])
            ->orderBy('day_number')->with(['meals' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'meals.type', 'meals.items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'meals.items.food.ingredient', 'meals.items.uom'])->get();
    }

    public function weekCount(): int
    {
        return (int) ceil(($this->getRecord()->days()->max('day_number') ?? 0) / 7);
    }

    public function selectWeek(int $number): void
    {
        $this->week = max(1, min($number, max(1, $this->weekCount())));
    }

    protected function dayScope(): ?int
    {
        return null;
    }

    protected function mealsQuery(): Builder
    {
        return Meal::whereHas('day', fn ($q) => $q->where('meal_plan_version_id', $this->getRecord()->id))
            ->when($this->dayScope(), fn ($q, $day) => $q->where('meal_plan_day_id', $day));
    }

    protected static string $resource = MealPlanVersionResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->plan->title;
    }

    public function getSubheading(): ?string
    {
        return 'Версія #'.$this->getRecord()->id;
    }

    public function dayLabel(int $number): string
    {
        return MealPlanDisplay::day($number, $this->getRecord()->plan->week_start_weekday);
    }

    protected function getHeaderActions(): array
    {
        return [ActionGroup::make($this->managementActions())->label('Керування')->button()->color('gray')];
    }

    protected function managementActions(): array
    {
        return [
            ...$this->itemActions(),
            ...$this->weekRepetitionActions(),
            ...$this->weekDeletionActions(),
            Action::make('generateWeek')->label('Додати 7 днів')->modalSubmitActionLabel('Додати тиждень')->authorize(fn () => $this->editable())
                ->modalHeading('Додати наступний семиденний тиждень')
                ->modalDescription('Створюються лише дні й обрані прийоми. Позицій немає; публікація ще недоступна. Повторний запит того самого тижня не дублює дані.')
                ->fillForm(fn () => ['start_day' => $this->getRecord()->days()->count() + 1])
                ->schema([
                    Hidden::make('start_day')->required(),
                    CheckboxList::make('meal_type_ids')->label('Типи прийомів нового тижня')->options(fn () => $this->types())->required()->minItems(1)
                        ->helperText(fn () => $this->types() === [] ? 'Довідник типів порожній. Генерація недоступна; зверніться до адміністратора довідників.' : 'Оберіть типи; порядок визначається чинним довідником.'),
                ])->action(function (array $data, Action $action): void {
                    $this->command($action, fn () => app(MealPlanStructure::class)->generate(auth()->user(), $this->getRecord(), $data));
                }),
            Action::make('addMeal')->label('Додати прийом')->modalSubmitActionLabel('Додати прийом')->authorize(fn () => $this->editable())
                ->schema(fn () => [
                    Select::make('day_id')->label('День')->options(fn () => $this->days())->required()->native(false),
                    ...$this->mealFields(),
                ])->action(function (array $data, Action $action): void {
                    $this->command($action, fn () => app(MealPlanStructure::class)->saveMeal(auth()->user(), $this->getRecord(), $data));
                }),
            Action::make('editMeal')->label('Змінити прийом')->modalSubmitActionLabel('Зберегти')->authorize(fn () => $this->editable())
                ->schema(fn () => [
                    $this->mealSelector(),
                    Hidden::make('day_id')->required(), Hidden::make('expected')->required(),
                    ...$this->mealFields(editing: true),
                ])->action(function (array $data, Action $action): void {
                    $this->command($action, fn () => app(MealPlanStructure::class)->saveMeal(auth()->user(), $this->getRecord(), $data, (int) $data['meal_id']));
                }),
            Action::make('deleteMeal')->label('Видалити порожній прийом')->modalSubmitActionLabel('Видалити')->color('danger')->authorize(fn () => $this->editable())
                ->modalDescription('Видалення дозволене лише без позицій. День зберігається, порожній день не готовий до публікації.')
                ->schema(fn () => [$this->mealSelector(), Hidden::make('day_id')->required(), Hidden::make('expected')->required()])
                ->requiresConfirmation()->action(function (array $data, Action $action): void {
                    $this->command($action, fn () => app(MealPlanStructure::class)->deleteMeal(auth()->user(), $this->getRecord(), (int) $data['day_id'], (int) $data['meal_id'], $data['expected']));
                }),
        ];
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        if (in_array($name, ['addItem', 'editItem', 'moveItem', 'deleteItem'], true)) {
            Gate::authorize('editItems', $this->getRecord());
        }
        if (in_array($name, ['repeatWeek', 'replaceWeeks', 'generateWeek', 'addMeal', 'editMeal', 'deleteMeal'], true) || str_starts_with($name, 'deleteWeek')) {
            $this->authorizeStructure();
        }

        return parent::mountAction($name, $arguments, $context);
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $this->authorizeStructure();

        return parent::callMountedAction($arguments);
    }

    protected function weekDeletionActions(): array
    {
        if (! $this->editable()) {
            return [];
        }
        $weeks = app(MealPlanStructure::class)->weekDeletionSnapshots(auth()->user(), $this->getRecord());
        if ($weeks === []) {
            return [];
        }
        $actions = [];
        foreach ($weeks as $week) {
            $actions[] = Action::make('deleteWeek'.$week['anchor'])
                ->label('Тиждень '.$week['week_number'].' · '.($week['item_count'] === 0 ? 'Порожній' : 'Позицій: '.$week['item_count']))
                ->color('danger')->authorize(fn () => $this->editable())
                ->arguments(['anchor' => $week['anchor'], 'expected' => $week['expected'], 'item_count' => $week['item_count']])
                ->modalHidden(fn (array $arguments) => (int) ($arguments['item_count'] ?? -1) === 0)
                ->requiresConfirmation(fn (array $arguments) => (int) ($arguments['item_count'] ?? 0) > 0)
                ->modalHeading('Видалити тиждень')
                ->modalDescription(fn (array $arguments) => 'Позицій: '.($arguments['item_count'] ?? 0).'. Увага! Ви збираєтеся видалити тиждень, у якому є додані страви/продукти. Буде видалено всі позиції та прийоми цього тижня. Наступні тижні змістяться на один. Дію неможливо скасувати.')
                ->modalSubmitActionLabel('Видалити тиждень')->modalCancelActionLabel('Скасувати')
                ->schema([Hidden::make('confirmation')])
                ->fillForm(fn (array $arguments, Action $action) => (int) ($arguments['item_count'] ?? 0) > 0
                    ? $this->withStructureValidation($action, fn () => app(MealPlanStructure::class)->confirmWeekDeletion(auth()->user(), $this->getRecord(), $arguments))
                    : [])
                ->action(function (array $data, array $arguments, Action $action): void {
                    $this->command($action, fn () => app(MealPlanStructure::class)->deleteWeek(auth()->user(), $this->getRecord(), array_merge($arguments, ['confirmation' => $data['confirmation'] ?? null])));
                });
        }

        return [ActionGroup::make($actions)->label('Видалити тиждень')->button()->color('danger')];
    }

    protected function weekRepetitionActions(): array
    {
        return [
            Action::make('repeatWeek')->label('Повторити тиждень')->authorize(fn () => $this->editable())
                ->modalHeading('Повторити тиждень')->modalSubmitActionLabel('Повторити')->modalCancelActionLabel('Скасувати')
                ->modalDescription('Незалежні копії всіх 7 днів, прийомів і позицій у чернетці. Джерело й попередні тижні зберігаються. Порожні дні копіюються як є; тиждень має містити хоча б одну позицію. Нові тижні залишаться чернеткою.')
                ->fillForm(function (): array {
                    $snapshot = app(MealPlanWeekRepeater::class)->snapshot(auth()->user(), $this->getRecord());

                    return ['source_week' => 1, 'total_weeks' => max(2, intdiv(count($snapshot['days']), 7)), 'expected' => $snapshot['expected']];
                })
                ->schema([
                    Hidden::make('expected')->required(),
                    Select::make('source_week')->label('Вихідний тиждень')->required()->live()
                        ->options(fn () => collect(range(1, max(1, $this->weekCount())))->mapWithKeys(fn ($n) => [$n => 'Тиждень '.$n])->all()),
                    TextInput::make('total_weeks')->label('Кількість тижнів загалом')->integer()->minValue(2)->maxValue(4681)->required()->live(onBlur: true)
                        ->helperText(fn (Get $get) => (int) $get('total_weeks') > (int) $get('source_week')
                            ? 'Тиждень '.($get('source_week') ?: 1).' → цільові тижні '.((int) $get('source_week') + 1).'–'.$get('total_weeks').'. Наприклад, 4 загалом = джерело 1 + 3 копії. Заповнені цілі потребують окремої згоди.'
                            : 'Укажіть загальну кількість, більшу за номер джерела і не меншу за наявну тривалість.'),
                ])->action(function (array $data, Action $action): void {
                    $preview = $this->withStructureValidation($action, fn () => app(MealPlanWeekRepeater::class)->preview(auth()->user(), $this->getRecord(), $data));
                    if ($preview['filled'] !== []) {
                        $this->replaceMountedAction('replaceWeeks', array_intersect_key($preview, array_flip(['source_week', 'total_weeks', 'expected'])));

                        return;
                    }
                    $this->repeatWeekCommand($action, $data);
                }),

        ];
    }

    /** Окреме confirmation action, доступне лише після перевірки підписаного стану. */
    public function replaceWeeksAction(): Action
    {
        return Action::make('replaceWeeks')->label('Замінити цільові тижні')->authorize(fn () => $this->editable())
            ->requiresConfirmation()->modalHeading('Замінити цільові тижні')
            ->modalSubmitActionLabel('Так, замінити цільові тижні')->modalCancelActionLabel('Скасувати')
            ->modalDescription(fn () => $this->repeatSummary)
            ->schema([Hidden::make('confirmation')])
            ->fillForm(function (array $arguments, Action $action): array {
                $preview = $this->withStructureValidation($action, fn () => app(MealPlanWeekRepeater::class)->preview(auth()->user(), $this->getRecord(), $arguments));
                $counts = collect($preview['filled'])->map(fn ($count, $week) => 'тиждень '.$week.': '.$count.' позицій')->implode('; ');
                $this->repeatSummary = 'Джерело: тиждень '.$preview['source_week'].'. Цілі: '.($preview['source_week'] + 1).'–'.$preview['total_weeks'].'. Буде замінено: '.$counts.'. Вміст цільових тижнів буде повністю замінено незалежними копіями. Поточні правки цілей буде втрачено. Дію неможливо скасувати.';

                return ['confirmation' => $preview['confirmation']];
            })
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->repeatWeekCommand($action, $arguments + ['confirmation' => $data['confirmation'] ?? null]);
            });
    }

    protected function repeatWeekCommand(Action $action, array $input): void
    {
        $this->command($action, fn () => app(MealPlanWeekRepeater::class)->repeat(auth()->user(), $this->getRecord(), $input));
        // У редакторі цільового дня його ID вже замінено: повертаємося до огляду.
        $this->redirect(MealPlanVersionResource::getUrl('view', ['record' => $this->getRecord(), 'week' => $this->week]), navigate: false);
    }

    protected function editable(): bool
    {
        return Gate::allows('editStructure', $this->getRecord());
    }

    protected function authorizeStructure(): void
    {
        Gate::authorize('editStructure', $this->getRecord());
    }

    protected function types(): array
    {
        return MealType::orderBy('sort_order')->orderBy('id')->pluck('title', 'id')->all();
    }

    protected function days(): array
    {
        return $this->getRecord()->days()->when($this->dayScope(), fn ($q, $day) => $q->whereKey($day))->orderBy('day_number')->get()->mapWithKeys(fn ($d) => [$d->id => 'Тиждень '.(intdiv($d->day_number - 1, 7) + 1).' · '.$this->dayLabel($d->day_number)])->all();
    }

    protected function mealFields(bool $editing = false): array
    {
        $fields = [
            Select::make('meal_type_id')->label('Тип прийому')->options(fn () => $this->types())->required()->native(false),
            TextInput::make('title')->label('Назва прийому (необов’язково)'),
            TextInput::make('sort_order')->label('Порядок у дні')->integer()->minValue(-32768)->maxValue(32767)->default(0)->required(),
        ];
        if ($editing) {
            foreach ($fields as $field) {
                $field->disabled(fn (Get $get) => blank($get('expected')))
                    ->extraFieldWrapperAttributes([
                        'wire:loading.attr' => 'inert',
                        'wire:target' => 'mountedActions.0.data.meal_id',
                    ]);
            }
        }

        return $fields;
    }

    protected function mealSelector(): Select
    {
        return Select::make('meal_id')->label('Прийом їжі')->required()->native(false)->live()
            ->options(fn () => $this->mealsQuery()->with(['day', 'type'])
                ->get()->sortBy([['day.day_number', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])
                ->mapWithKeys(fn ($m) => [$m->id => $this->dayLabel($m->day->day_number).' · '.$m->type->title.($m->title ? ' · '.$m->title : '')])->all())
            ->afterStateUpdated(function ($state, Set $set): void {
                $this->authorizeStructure();
                $meal = $this->mealsQuery()->find($state);
                foreach (['day_id' => $meal?->meal_plan_day_id, 'meal_type_id' => $meal?->meal_type_id,
                    'title' => $meal?->title, 'sort_order' => $meal?->sort_order,
                    'expected' => $meal ? MealPlanStructure::token($meal) : null] as $key => $value) {
                    $set($key, $value);
                }
            });
    }

    protected function command(Action $action, callable $operation): void
    {
        $this->withStructureValidation($action, function () use ($operation): void {
            if ($this->dayScope()) {
                $data = $this->mountedActions[0]['data'] ?? [];
                if (isset($data['day_id'])) {
                    abort_unless((int) $data['day_id'] === $this->dayScope(), 404);
                }
                foreach (['meal_id', 'item_id'] as $field) {
                    if (isset($data[$field])) {
                        abort_unless(($field === 'meal_id' ? $this->mealsQuery() : $this->versionItems())->whereKey($data[$field])->exists(), 404);
                    }
                }
            }
            $operation();
        });
        $this->done();
    }

    protected function withStructureValidation(Action $action, callable $operation): mixed
    {
        $this->authorizeStructure();
        try {
            return $operation();
        } catch (ValidationException $exception) {
            $path = $this->getMountedActionSchema(mountedAction: $action)->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$path.'.'.$field] = $messages;
            }
            Notification::make()->danger()->title('Структуру не збережено')
                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->send();

            throw ValidationException::withMessages($errors);
        }
    }

    protected function done(): void
    {
        $this->getRecord()->refresh();
        Notification::make()->title('Структуру збережено')->success()->send();
        $this->redirect(MealPlanVersionResource::getUrl('view', ['record' => $this->getRecord(), ...($this->week > 1 ? ['week' => $this->week] : [])]), navigate: false);
    }
}

<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Models\Meal;
use App\Models\MealType;
use App\Services\MealPlans\MealPlanStructure;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewMealPlanVersion extends ViewRecord
{
    protected static string $resource = MealPlanVersionResource::class;

    public function getTitle(): string
    {
        return 'Версія раціону #'.$this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
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
        if (in_array($name, ['generateWeek', 'addMeal', 'editMeal', 'deleteMeal'], true) || str_starts_with($name, 'deleteWeek')) {
            $this->authorizeStructure();
        }

        return parent::mountAction($name, $arguments, $context);
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $this->authorizeStructure();

        return parent::callMountedAction($arguments);
    }

    private function weekDeletionActions(): array
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

    private function editable(): bool
    {
        return Gate::allows('editStructure', $this->getRecord());
    }

    private function authorizeStructure(): void
    {
        Gate::authorize('editStructure', $this->getRecord());
    }

    private function types(): array
    {
        return MealType::orderBy('sort_order')->orderBy('id')->pluck('title', 'id')->all();
    }

    private function days(): array
    {
        return $this->getRecord()->days()->orderBy('day_number')->get()->mapWithKeys(fn ($d) => [$d->id => 'Тиждень '.(intdiv($d->day_number - 1, 7) + 1).' · День '.$d->day_number])->all();
    }

    private function mealFields(bool $editing = false): array
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

    private function mealSelector(): Select
    {
        return Select::make('meal_id')->label('Прийом їжі')->required()->native(false)->live()
            ->options(fn () => Meal::whereHas('day', fn ($q) => $q->where('meal_plan_version_id', $this->getRecord()->id))->with(['day', 'type'])
                ->get()->sortBy([['day.day_number', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])
                ->mapWithKeys(fn ($m) => [$m->id => 'День '.$m->day->day_number.' · '.$m->type->title.($m->title ? ' · '.$m->title : '')])->all())
            ->afterStateUpdated(function ($state, Set $set): void {
                $this->authorizeStructure();
                $meal = Meal::whereHas('day', fn ($q) => $q->where('meal_plan_version_id', $this->getRecord()->id))->find($state);
                foreach (['day_id' => $meal?->meal_plan_day_id, 'meal_type_id' => $meal?->meal_type_id,
                    'title' => $meal?->title, 'sort_order' => $meal?->sort_order,
                    'expected' => $meal ? MealPlanStructure::token($meal) : null] as $key => $value) {
                    $set($key, $value);
                }
            });
    }

    private function command(Action $action, callable $operation): void
    {
        $this->withStructureValidation($action, $operation);
        $this->done();
    }

    private function withStructureValidation(Action $action, callable $operation): mixed
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

    private function done(): void
    {
        $this->getRecord()->refresh();
        Notification::make()->title('Структуру збережено')->success()->send();
        $this->redirect(MealPlanVersionResource::getUrl('view', ['record' => $this->getRecord()]), navigate: false);
    }
}

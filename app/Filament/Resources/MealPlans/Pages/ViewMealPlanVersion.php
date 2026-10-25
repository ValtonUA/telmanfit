<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Models\Meal;
use App\Models\MealType;
use App\Services\MealPlans\MealPlanStructure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
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
                    ...$this->mealFields(),
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

    private function mealFields(): array
    {
        return [
            Select::make('meal_type_id')->label('Тип прийому')->options(fn () => $this->types())->required()->native(false),
            TextInput::make('title')->label('Назва прийому (необов’язково)'),
            TextInput::make('sort_order')->label('Порядок у дні')->integer()->minValue(-32768)->maxValue(32767)->default(0)->required(),
        ];
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
        $this->authorizeStructure();
        try {
            $operation();
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
        $this->done();
    }

    private function done(): void
    {
        $this->getRecord()->refresh();
        Notification::make()->title('Структуру збережено')->success()->send();
        $this->redirect(MealPlanVersionResource::getUrl('view', ['record' => $this->getRecord()]), navigate: false);
    }
}

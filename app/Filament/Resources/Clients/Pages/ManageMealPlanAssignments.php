<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Models\ClientMealPlanAssignment;
use App\Services\MealPlans\MealPlanAssigner;
use App\Services\MealPlans\MealPlanFoundation;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ManageMealPlanAssignments extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'mealPlanAssignments';

    protected static ?string $title = 'Призначення раціонів';

    protected static ?string $breadcrumb = 'Призначення раціонів';

    #[Locked]
    public string $assignmentSummary = '';

    public static function canAccess(array $parameters = []): bool
    {
        return MealPlanFoundation::allowed(auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [$this->formAction('assignMealPlan'), Action::make('client')->label('До картки клієнта')
            ->url(fn () => ClientResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('version.plan.title')->label('Раціон')->wrap()
                ->url(fn (ClientMealPlanAssignment $record) => MealPlanVersionResource::getUrl('view', ['record' => $record->meal_plan_version_id])),
            TextColumn::make('version.version_number')->label('Версія'),
            TextColumn::make('start_date')->label('Початок')->date('d.m.Y')->sortable(),
            TextColumn::make('end_date')->label('Завершення')->date('d.m.Y')->placeholder('Безстроково'),
            TextColumn::make('replaced_by_assignment_id')->label('Замінено призначенням')->prefix('#')->placeholder('—'),
            TextColumn::make('replaces.id')->label('Замінило призначення')->prefix('#')->placeholder('—'),
        ])->recordActions([
            $this->formAction('replaceMealPlan')->label('Замінити раціон')
                ->visible(fn (ClientMealPlanAssignment $record) => $record->replaced_by_assignment_id === null
                    && ($record->end_date === null || $record->end_date->toDateString() >= today()->toDateString())),
        ])->defaultSort('start_date', 'desc')->emptyStateHeading('Раціони ще не призначені')
            ->emptyStateDescription('Оберіть опубліковану версію та дату початку. Історія призначень зберігатиметься тут.');
    }

    private function formAction(string $name): Action
    {
        return Action::make($name)->label('Призначити раціон')
            ->authorize(fn () => MealPlanFoundation::allowed(auth()->user()))
            ->modalSubmitActionLabel('Перевірити наслідки')->modalCancelActionLabel('Скасувати')
            ->fillForm(fn (Model $record): array => [
                'expected' => app(MealPlanAssigner::class)->snapshot(auth()->user(), $this->getRecord()->id),
                'replace_assignment_id' => $record instanceof ClientMealPlanAssignment ? $record->id : null,
                'start_date' => ($record instanceof ClientMealPlanAssignment ? today()->addDay() : today())->toDateString(),
            ])
            ->schema([
                Hidden::make('expected')->required(), Hidden::make('replace_assignment_id'),
                Select::make('meal_plan_version_id')->label('Опублікована версія')->required()->searchable()
                    ->options(fn () => DB::table('meal_plan_versions as v')->join('meal_plans as p', 'p.id', '=', 'v.meal_plan_id')
                        ->where('v.status', 'published')->where(fn ($q) => $q->whereNull('p.owner_client_id')->orWhere('p.owner_client_id', $this->getRecord()->id))
                        ->orderBy('p.title')->orderByDesc('v.version_number')->select(['v.id', 'p.title', 'v.version_number'])
                        ->get()->mapWithKeys(fn ($v) => [$v->id => $v->title.' · версія '.$v->version_number.' (#'.$v->id.')'])->all())
                    ->noSearchResultsMessage('Доступних опублікованих версій не знайдено.')
                    ->helperText('Доступні універсальні раціони та персональні раціони цього клієнта. Якщо список порожній, спочатку опублікуйте відповідний раціон.'),
                DatePicker::make('start_date')->label('Дата початку')->required()->displayFormat('d.m.Y'),
                DatePicker::make('end_date')->label('Дата завершення')->displayFormat('d.m.Y')->helperText('Необов’язково. Порожнє поле означає безстрокове призначення.'),
            ])->action(function (array $data, Action $action): void {
                // Preview нічого не пише. Новий modal вимагає окремого явного підтвердження.
                $this->validated($action, fn () => app(MealPlanAssigner::class)->preview(auth()->user(), $this->getRecord()->id, $data));
                $this->replaceMountedAction('confirmAssignment', $data);
            });
    }

    public function confirmAssignmentAction(): Action
    {
        return Action::make('confirmAssignment')->label('Підтвердити призначення')
            ->authorize(fn () => MealPlanFoundation::allowed(auth()->user()))->requiresConfirmation()
            ->modalHeading('Підтвердження призначення')->modalDescription(fn () => $this->assignmentSummary)
            ->modalSubmitActionLabel('Так, підтвердити призначення')->modalCancelActionLabel('Скасувати')
            ->schema([Hidden::make('confirmation')->required()])
            ->fillForm(function (array $arguments, Action $action): array {
                $effect = $this->validated($action, fn () => app(MealPlanAssigner::class)->preview(auth()->user(), $this->getRecord()->id, $arguments));
                $old = $effect['old_assignment_id'];
                $label = function (int $id): string {
                    $v = DB::table('meal_plan_versions as v')->join('meal_plans as p', 'p.id', '=', 'v.meal_plan_id')->where('v.id', $id)->select(['p.title', 'v.version_number'])->sole();

                    return $v->title.' · версія '.$v->version_number.' (#'.$id.')';
                };
                $this->assignmentSummary = ($old ? 'Завершиться '.$label($effect['old_meal_plan_version_id']).' (призначення #'.$old.') — '.$effect['old_end_date_after'].'. ' : 'Буде створено окреме призначення без заміни попередніх. ')
                    .'Почнеться '.$label($effect['new_meal_plan_version_id']).' — '.$effect['new_start_date'].', завершення: '.($effect['new_end_date'] ?? 'безстроково').'.';

                return ['confirmation' => $effect['confirmation']];
            })->action(function (array $data, array $arguments, Action $action): void {
                $this->validated($action, fn () => app(MealPlanAssigner::class)->assign(auth()->user(), $this->getRecord()->id, $arguments, $data['confirmation']));
                $this->resetTable();
                Notification::make()->success()->title('Раціон призначено')->send();
            });
    }

    private function validated(Action $action, callable $command): mixed
    {
        try {
            return $command();
        } catch (QueryException $exception) {
            report($exception);
            Notification::make()->danger()->title('Раціон не призначено')
                ->body('Не вдалося зберегти призначення. Оновіть сторінку та перевірте розклад перед повторною спробою.')->send();
            $action->halt();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Раціон не призначено')
                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->send();
            $path = $this->getMountedActionSchema(mountedAction: $action)->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$path.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}

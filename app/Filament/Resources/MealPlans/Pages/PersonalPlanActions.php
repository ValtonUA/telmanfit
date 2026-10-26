<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Models\Client;
use App\Services\MealPlans\MealPlanCopier;
use App\Services\MealPlans\MealPlanFoundation;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait PersonalPlanActions
{
    #[Locked]
    public string $personalCopySummary = '';

    public function personalCopyAction(): Action
    {
        return Action::make('personalCopy')->label('Створити персональну копію')
            ->authorize(fn () => MealPlanFoundation::allowed(auth()->user()) && $this->getRecord()->fresh()->status === 'published')
            ->modalSubmitActionLabel('Перевірити копію')->modalCancelActionLabel('Скасувати')
            ->schema([
                Select::make('owner')->label('Клієнт')->required()->searchable()->options(fn () => Client::with('user')->orderBy('id')->get()->mapWithKeys(fn ($c) => [$c->id => 'Клієнт #'.$c->id.' — '.($c->user?->full_name ?: 'Без акаунта')])->all())
                    ->helperText('Якщо список порожній, спочатку потрібна наявна картка клієнта. Персональне джерело доступне лише тому самому власнику.'),
                TextInput::make('title')->label('Назва копії')->required(),
            ])->fillForm(fn () => ['title' => $this->getRecord()->plan->title])
            ->action(function (array $data): void {
                $this->copyValidation(fn () => app(MealPlanCopier::class)->preview(auth()->user(), $this->getRecord()->id, (int) $data['owner'], $data['title']));
                $this->replaceMountedAction('confirmPersonalCopy', $data);
            });
    }

    public function confirmPersonalCopyAction(): Action
    {
        return Action::make('confirmPersonalCopy')->authorize(fn () => MealPlanFoundation::allowed(auth()->user()))
            ->requiresConfirmation()->modalHeading('Створити персональну копію')
            ->modalDescription(fn () => $this->personalCopySummary)->modalSubmitActionLabel('Так, створити копію')->modalCancelActionLabel('Скасувати')
            ->schema([Hidden::make('confirmation')->required()])
            ->fillForm(function (array $arguments): array {
                $p = $this->copyValidation(fn () => app(MealPlanCopier::class)->preview(auth()->user(), $this->getRecord()->id, (int) $arguments['owner'], $arguments['title']));
                $this->personalCopySummary = $p['source'].' · версія '.$p['version'].'; днів: '.$p['days'].'; тижнів: '.($p['days'] / 7).'. Клієнт #'.$p['owner'].'. Назва: '.$p['title'].'. Призначення не створюється.';

                return ['confirmation' => $p['confirmation']];
            })->action(function (array $arguments, array $data): void {
                $copy = $this->copyValidation(fn () => app(MealPlanCopier::class)->copy(auth()->user(), $this->getRecord()->id, (int) $arguments['owner'], $arguments['title'], $data['confirmation']));
                $this->redirect(MealPlanVersionResource::getUrl('view', ['record' => $copy]), navigate: false);
            });
    }

    private function copyValidation(callable $command): mixed
    {
        try {
            return $command();
        } catch (QueryException $e) {
            report($e);
            Notification::make()->danger()->title('Копію не створено')->body('Не вдалося зберегти копію. Оновіть сторінку та повторіть перевірку.')->send();
            throw ValidationException::withMessages(['copy' => 'Не вдалося зберегти копію.']);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Копію не створено')->body(implode(' ', $e->validator->errors()->all()))->send();
            throw $e;
        }
    }
}

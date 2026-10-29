<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanResource;
use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewMealPlan extends ViewRecord
{
    protected static string $resource = MealPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('editPlan')->label('Редагувати раціон')
            ->visible(fn () => $this->getRecord()->versions()->where('status', 'draft')->exists())
            ->url(fn () => MealPlanVersionResource::getUrl('view', ['record' => $this->getRecord()->versions()->where('status', 'draft')->sole()]))];
    }
}

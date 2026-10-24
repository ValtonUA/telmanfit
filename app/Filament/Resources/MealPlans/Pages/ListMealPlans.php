<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMealPlans extends ListRecords
{
    protected static string $resource = MealPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Створити раціон')];
    }
}

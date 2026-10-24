<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMealPlanVersion extends ViewRecord
{
    protected static string $resource = MealPlanVersionResource::class;

    public function getTitle(): string
    {
        return 'Версія раціону #'.$this->getRecord()->getKey();
    }
}

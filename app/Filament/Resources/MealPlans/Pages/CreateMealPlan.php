<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\Catalog\Concerns\MapsCatalogValidation;
use App\Filament\Resources\MealPlans\MealPlanResource;
use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Services\MealPlans\MealPlanFoundation;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMealPlan extends CreateRecord
{
    use MapsCatalogValidation;

    protected static string $resource = MealPlanResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Створити раціон';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withCatalogValidation(fn () => app(MealPlanFoundation::class)->create(Filament::auth()->user(), $data));
    }

    protected function getRedirectUrl(): string
    {
        return MealPlanVersionResource::getUrl('view', ['record' => $this->getRecord()->versions()->sole()]);
    }
}

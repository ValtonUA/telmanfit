<?php

namespace App\Filament\Resources\MealPlans\Pages;

use App\Filament\Resources\Catalog\Concerns\MapsCatalogValidation;
use App\Filament\Resources\MealPlans\MealPlanResource;
use App\Filament\Resources\MealPlans\MealPlanVersionResource;
use App\Services\MealPlans\MealPlanFoundation;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateMealPlan extends CreateRecord
{
    use MapsCatalogValidation;

    protected static string $resource = MealPlanResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $creationRequestKey = '';

    public function mount(): void
    {
        parent::mount();
        $key = request()->query('creation');
        if (! is_string($key) || ! Str::isUuid($key)) {
            $this->redirect(MealPlanResource::getUrl('create', ['creation' => (string) Str::uuid()]), navigate: true);

            return;
        }
        $this->creationRequestKey = $key;
    }

    public function getTitle(): string
    {
        return 'Створити раціон';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['request_key'] = $this->creationRequestKey;

        return $this->withCatalogValidation(fn () => app(MealPlanFoundation::class)->create(Filament::auth()->user(), $data));
    }

    protected function getRedirectUrl(): string
    {
        // Повтор може надійти після публікації початкової версії та створення нової чернетки.
        return MealPlanVersionResource::getUrl('view', ['record' => $this->getRecord()->versions()->orderBy('id')->firstOrFail()]);
    }
}

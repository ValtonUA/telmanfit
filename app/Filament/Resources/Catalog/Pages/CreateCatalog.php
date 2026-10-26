<?php

namespace App\Filament\Resources\Catalog\Pages;

use App\Filament\Resources\Catalog\Concerns\MapsCatalogValidation;
use App\Services\Catalog\CatalogWriter;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

abstract class CreateCatalog extends CreateRecord
{
    use MapsCatalogValidation;

    public function getTitle(): string
    {
        return 'Створення: '.$this->getResource()::getPluralModelLabel();
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withCatalogValidation(fn () => app(CatalogWriter::class)->save(Filament::auth()->user(), new ($this->getModel()), $data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

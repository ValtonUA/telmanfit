<?php

namespace App\Filament\Resources\Catalog\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

abstract class ListCatalog extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Створити запис')];
    }
}

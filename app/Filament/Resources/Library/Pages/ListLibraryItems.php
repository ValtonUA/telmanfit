<?php

namespace App\Filament\Resources\Library\Pages;

use App\Filament\Resources\Library\LibraryItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLibraryItems extends ListRecords
{
    protected static string $resource = LibraryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

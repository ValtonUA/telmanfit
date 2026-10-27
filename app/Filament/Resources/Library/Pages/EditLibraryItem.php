<?php

namespace App\Filament\Resources\Library\Pages;

use App\Filament\Resources\Library\LibraryItemResource;
use Filament\Resources\Pages\EditRecord;

class EditLibraryItem extends EditRecord
{
    protected static string $resource = LibraryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

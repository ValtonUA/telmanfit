<?php

namespace App\Filament\Resources\Library\Pages;

use App\Filament\Resources\Library\LibraryItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLibraryItem extends CreateRecord
{
    protected static string $resource = LibraryItemResource::class;
}

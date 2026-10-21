<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function authorizeAccess(): void
    {
        // Блокує mount, hydrate і create навіть для раніше відкритої Livewire-форми.
        // Механізм персональних запрошень ще не реалізовано.
        abort(403);
    }
}

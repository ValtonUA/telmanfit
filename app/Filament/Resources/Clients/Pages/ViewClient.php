<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function getTitle(): string
    {
        return 'Картка клієнта #'.$this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}

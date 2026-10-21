<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Concerns\MapsClientValidation;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    use MapsClientValidation;

    protected static string $resource = ClientResource::class;

    public function getTitle(): string
    {
        return 'Редагування картки клієнта #'.$this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withClientValidation(fn () => parent::handleRecordUpdate($record, $data));
    }
}

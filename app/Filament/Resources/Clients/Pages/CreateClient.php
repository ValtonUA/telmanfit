<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Concerns\MapsClientValidation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClient extends CreateRecord
{
    use MapsClientValidation;

    protected static string $resource = ClientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withClientValidation(fn () => parent::handleRecordCreation($data));
    }
}

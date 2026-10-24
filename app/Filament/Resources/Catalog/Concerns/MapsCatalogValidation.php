<?php

namespace App\Filament\Resources\Catalog\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait MapsCatalogValidation
{
    protected function withCatalogValidation(callable $operation): Model
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$this->form->getStatePath().'.'.$field] = $messages;
            }
            Notification::make()->danger()->title('Запис не збережено')->body(implode(' ', array_merge(...array_values($exception->errors()))))->send();

            throw ValidationException::withMessages($errors);
        }
    }
}

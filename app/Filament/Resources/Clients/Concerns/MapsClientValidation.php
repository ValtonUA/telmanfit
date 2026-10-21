<?php

namespace App\Filament\Resources\Clients\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait MapsClientValidation
{
    protected function withClientValidation(callable $operation): Model
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            // Доменна валідація має показувати помилки біля полів Filament.
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$this->form->getStatePath().'.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}

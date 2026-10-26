<?php

namespace App\Models;

use App\Casts\PostgresTextArray;

class ClientActivityProfile extends ClientProfileRecord
{
    protected function casts(): array
    {
        return ['preferred_training_days' => PostgresTextArray::class];
    }

    public static function rules(): array
    {
        return array_merge([
            'steps_target' => ['nullable', 'integer', 'between:0,2147483647'],
            'fitness_equipment' => ['nullable', 'string'],
            'physical_limitations' => ['nullable', 'string'],
        ], self::dayRules('preferred_training_days'));
    }
}

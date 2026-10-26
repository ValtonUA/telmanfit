<?php

namespace App\Models;

use App\Casts\PostgresTextArray;

class ClientNutritionProfile extends ClientProfileRecord
{
    protected function casts(): array
    {
        return ['preferred_cooking_days' => PostgresTextArray::class];
    }

    public static function rules(): array
    {
        return array_merge([
            'maintenance_calories' => ['nullable', 'integer', 'between:0,32767'],
            'target_calories' => ['nullable', 'integer', 'between:0,32767'],
            'weekly_free_calories' => ['nullable', 'integer', 'between:0,32767'],
            'kitchen_appliances' => ['nullable', 'string'],
            'preferences' => ['nullable', 'string'],
            'restrictions' => ['nullable', 'string'],
        ], self::dayRules('preferred_cooking_days'));
    }
}

<?php

namespace App\Models;

class ClientProfile extends ClientProfileRecord
{
    public static function rules(): array
    {
        return [
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'height_cm' => ['nullable', 'integer', 'between:1,32767'],
            'sex' => ['nullable', 'in:male,female'],
            'goal' => ['nullable', 'string'],
            'difficulties' => ['nullable', 'string'],
            'priorities' => ['nullable', 'string'],
            'work_mode' => ['nullable', 'in:remote,office,hybrid'],
        ];
    }
}

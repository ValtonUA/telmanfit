<?php

namespace App\Models;

use Illuminate\Validation\Rule;

class ClientSetting extends ClientProfileRecord
{
    protected $attributes = ['timezone' => 'Europe/Kyiv'];

    public static function rules(): array
    {
        return [
            'timezone' => ['required', 'timezone'],
            'morning_message_time' => ['nullable', 'date_format:H:i:s'],
            'weekly_checkin_day' => ['nullable', Rule::in(self::WEEKDAYS)],
        ];
    }
}

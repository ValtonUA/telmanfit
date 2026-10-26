<?php

namespace App\Casts;

use App\Models\ClientProfileRecord;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Лише коди днів; довільні PostgreSQL-рядки тут не кодуються. */
class PostgresTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : ($value === '{}' ? [] : str_getcsv(substr($value, 1, -1), ',', '"', '\\'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        Validator::make([$key => $value], [
            $key => ['array', 'list'], $key.'.*' => ['required', 'string', 'distinct:strict', Rule::in(ClientProfileRecord::WEEKDAYS)],
        ])->validate();

        return '{'.implode(',', $value).'}';
    }
}

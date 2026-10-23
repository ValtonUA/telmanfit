<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

abstract class ClientProfileRecord extends Model
{
    protected $primaryKey = 'client_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function getFillable(): array
    {
        return array_merge(['client_id'], array_filter(array_keys(static::rules()), fn ($key) => ! str_contains($key, '.')));
    }

    abstract public static function rules(): array;

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            $values = [];
            foreach (array_filter(array_keys(static::rules()), fn ($key) => ! str_contains($key, '.')) as $key) {
                $values[$key] = $profile->getAttribute($key);
            }
            $values['client_id'] = $profile->client_id;
            Validator::make($values, array_merge([
                'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            ], static::rules()))->validate();
        });
    }

    protected static function dayRules(string $field): array
    {
        return [$field => ['nullable', 'array', 'list'], $field.'.*' => ['required', 'string', 'distinct:strict', Rule::in(self::WEEKDAYS)]];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

#[Fillable(['user_id', 'coach_user_id', 'status'])]
class Client extends Model
{
    public const STATUSES = [
        'pending' => 'Очікує',
        'active' => 'Активний',
        'paused' => 'Призупинений',
        'finished' => 'Завершений',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['coaching_started_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            // Перший перехід в active запускає супровід; пауза не стирає дату.
            if ($client->status === 'active' && $client->coaching_started_at === null) {
                $client->coaching_started_at = $client->getRawOriginal('coaching_started_at') ?? now();
            }

            Validator::make($client->getAttributes(), [
                'user_id' => [
                    'nullable', 'integer', Rule::exists('users', 'id'),
                    Rule::unique('clients', 'user_id')->ignore($client->getKey()),
                ],
                'coach_user_id' => [
                    'required', 'integer',
                    Rule::exists('users', 'id')->whereIn('role', [User::ROLE_ADMIN, User::ROLE_COACH]),
                    // BR-U-08: самостійне ведення клієнтом самого себе у v1 відсутнє.
                    Rule::notIn(array_filter([$client->user_id], fn ($id) => $id !== null)),
                ],
                'status' => ['required', Rule::in(array_keys(self::STATUSES))],
                'coaching_started_at' => ['nullable', 'date', 'required_if:status,active'],
            ], [
                'coach_user_id.exists' => 'Оберіть тренера або адміністратора.',
                'coach_user_id.not_in' => 'Клієнт не може супроводжувати самого себе.',
                'user_id.unique' => 'Цей акаунт уже має клієнтську картку.',
            ])->validate();
        });
    }

    public function profile(): HasOne
    {
        return $this->hasOne(ClientProfile::class);
    }

    public function nutritionProfile(): HasOne
    {
        return $this->hasOne(ClientNutritionProfile::class);
    }

    public function activityProfile(): HasOne
    {
        return $this->hasOne(ClientActivityProfile::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(ClientSetting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_user_id');
    }
}

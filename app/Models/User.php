<?php

namespace App\Models;

use App\Services\Auth\Recovery\RecoveryGate;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Auth\Restore\RestoreFence;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['first_name', 'last_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Роль адміністратора (BR-U-01). Роль не входить до $fillable
     * і призначається лише явно (не через масове призначення).
     */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_COACH = 'coach';

    /** Історичне name — лише fallback до підтвердження складових власником. */
    public function getFullNameAttribute(): string
    {
        return self::displayName($this->getAttributes());
    }

    public static function displayName(array|object $user): string
    {
        $data = (array) $user;
        $parts = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        return $parts !== '' ? $parts : ($data['name'] ?? '');
    }

    public function getFilamentName(): string
    {
        return $this->full_name;
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function coachedClients(): HasMany
    {
        return $this->hasMany(Client::class, 'coach_user_id');
    }

    /**
     * Доступ до панелі Filament мають лише користувачі з роллю admin.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->exists && (config('database.default') === 'pgsql' || ! app()->environment(['local', 'testing']))) {
            try {
                app(RestoreFence::class)->assert();
            } catch (\Throwable) {
                return false;
            }
        }

        return $this->role === self::ROLE_ADMIN
            && (! $this->exists || (config('mfa.enabled') ? ! RecoveryGate::blocked((int) $this->getKey()) : ! RecoveryGate::hasHistory((int) $this->getKey())));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

<?php

namespace Auth1;

use Closure;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use PragmaRX\Google2FAQRCode\Google2FA;

// Тільки синтетична модель у тимчасовій SQLite; не App\Models\User.
class User extends BaseUser implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    protected $table = 'auth1_users';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['secret', 'recovery', 'password'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'recovery' => 'array', 'needs_mfa' => 'boolean'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'admin';
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->secret;
    }

    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->update(['secret' => $secret]);
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->recovery;
    }

    public function saveAppAuthenticationRecoveryCodes(#[\SensitiveParameter] ?array $codes): void
    {
        $this->update(['recovery' => $codes]);
    }
}

// PHPUnit за замовчуванням пропускає CSRF: тут виконується справжня перевірка токена.
class Csrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}

class Origin
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_if(! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']) && $request->header('Origin') !== 'https://auth1.test', 403);

        return $next($request);
    }
}

// Спільний factor service: один encrypted secret і durable accepted step для обох каналів.
// SQLite доводить лише послідовні транзакції; PostgreSQL row-lock concurrency — наступний етап.
class Factors
{
    public function __construct(private Google2FA $totp) {}

    public function verify(User $user, string $code, ?string $secret = null): bool
    {
        return DB::transaction(function () use ($user, $code, $secret): bool {
            $factor = User::query()->lockForUpdate()->findOrFail($user->id);
            $last = $factor->last_step ?? ($this->totp->getTimestamp() - 2);
            $step = $this->totp->verifyKeyNewer($secret ?? $factor->secret, $code, $last, 1);
            if ($step === false) {
                return false;
            }
            $factor->update(['last_step' => $step]);

            return true;
        });
    }
}

class SharedAppAuthentication extends AppAuthentication
{
    public function generateSecret(): string
    {
        return $this->google2FA->generateSecretKey(32);
    }

    public function __construct(Google2FA $totp, private Factors $factors)
    {
        parent::__construct($totp);
    }

    public function verifyCode(#[\SensitiveParameter] string $code, #[\SensitiveParameter] ?string $secret = null, bool $shouldPreventCodeReuse = false): bool
    {
        // Незавершений enrollment потребує окремого pending-secret протоколу в AUTH-3.
        if (! $shouldPreventCodeReuse) {
            return parent::verifyCode($code, $secret);
        }

        return $this->factors->verify(Filament::auth()->user(), $code, $secret);
    }
}

class Completed
{
    public function handle(Request $request, Closure $next, string $audience = 'client'): mixed
    {
        abort_unless($request->hasSession(), 401);
        $user = Auth::guard($audience)->user();
        $state = $request->session()->get('assurance.'.$audience);
        abort_unless($user && $state && $state['audience'] === $audience && $state['version'] === $user->auth_version && $state['expires'] > time(), 401);
        abort_if(($user->needs_mfa || $audience === 'admin') && ! $state['mfa'], 401);
        abort_if($audience === 'admin' && $user->role !== 'admin', 403);

        return $next($request);
    }
}

class Flow
{
    public int $logins = 0;

    public function otp(Request $request): array
    {
        $row = DB::table('auth1_challenges')->where('id', $request->input('challenge'))->first();
        abort_unless($row && $row->binding === $request->session()->getId() && $row->expires > time() && ! $row->used && hash_equals($row->mac, hash_hmac('sha256', (string) $request->input('code'), 'auth1-synthetic-only')), 422);
        abort_unless(DB::table('auth1_challenges')->where('id', $row->id)->where('used', false)->update(['used' => true]) === 1, 422);
        $user = User::findOrFail($row->user_id);
        abort_unless($user->role === 'client', 403);
        if ($user->needs_mfa) {
            $request->session()->put('pending', ['id' => $user->id, 'version' => $user->auth_version, 'expires' => time() + 300]);

            return ['state' => 'mfa_pending'];
        }

        return $this->complete($request, $user, false);
    }

    public function mfa(Request $request): array
    {
        $pending = $request->session()->get('pending');
        abort_unless($pending && $pending['expires'] > time(), 401);
        $user = User::findOrFail($pending['id']);
        abort_unless($pending['version'] === $user->auth_version, 401);
        abort_unless(app(Factors::class)->verify($user, (string) $request->input('code')), 422);

        return $this->complete($request, $user, true);
    }

    private function complete(Request $request, User $user, bool $mfa): array
    {
        $request->session()->forget('pending');
        Auth::guard('client')->login($user, false);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $request->session()->put('assurance.client', ['audience' => 'client', 'version' => $user->auth_version, 'mfa' => $mfa, 'expires' => time() + 600]);
        $this->logins++;

        return ['state' => 'authenticated'];
    }
}

class Probe extends Component
{
    public int $counter = 0;

    public function increment(): void
    {
        $this->counter++;
    }

    public function render(): string
    {
        return '<div>{{ $counter }}</div>';
    }
}

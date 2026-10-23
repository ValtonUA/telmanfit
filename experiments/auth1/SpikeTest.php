<?php

namespace Auth1;

require_once __DIR__.'/Fixture.php';

use Composer\InstalledVersions;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Http\Requests\LoginRequest;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\SanctumServiceProvider;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FAQRCode\Google2FA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SpikeTest extends TestCase
{
    private Application $app;

    private string $root;

    private array $cookies = [];

    private ?string $csrf = null;

    private User $user;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        Facade::clearResolvedInstances();
        $this->root = sys_get_temp_dir().'/telmanfit-auth1-'.bin2hex(random_bytes(8));
        foreach (['bootstrap/cache', 'config', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
            mkdir($this->root.'/'.$dir, 0700, true);
        }
        file_put_contents($this->root.'/bootstrap/providers.php', '<?php return [];');
        file_put_contents($this->root.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        mkdir($this->root.'/app', 0700);
        symlink(getenv('AUTH1_VENDOR') ?: dirname(__DIR__, 2).'/vendor', $this->root.'/vendor');
        $configs = [
            'app' => ['env' => 'testing', 'key' => 'base64:'.base64_encode(random_bytes(32)), 'cipher' => 'AES-256-CBC', 'debug' => true, 'url' => 'https://auth1.test', 'locale' => 'en'],
            'auth' => ['defaults' => ['guard' => 'client'], 'guards' => ['client' => ['driver' => 'session', 'provider' => 'synthetic'], 'admin' => ['driver' => 'session', 'provider' => 'synthetic']], 'providers' => ['synthetic' => ['driver' => 'eloquent', 'model' => User::class]]],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'session' => ['driver' => 'file', 'files' => $this->root.'/storage/framework/sessions', 'lifetime' => 10, 'lottery' => [0, 100], 'cookie' => 'auth1_client', 'path' => '/', 'domain' => null, 'secure' => true, 'http_only' => true, 'same_site' => 'lax', 'serialization' => 'json'],
            'view' => ['paths' => [], 'compiled' => $this->root.'/storage/framework/views'],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => NullHandler::class]]],
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
        ];
        foreach ($configs as $name => $config) {
            file_put_contents($this->root.'/config/'.$name.'.php', '<?php return '.var_export($config, true).';');
        }
        $this->app = Application::configure(basePath: $this->root)->withMiddleware()->withExceptions()->create();
        $this->app->make(Kernel::class)->bootstrap();
        $this->app->instance('request', Request::create('https://auth1.test'));
        Schema::create('auth1_users', function (Blueprint $t): void {
            $t->id();
            $t->string('email')->unique();
            $t->string('role');
            $t->string('password')->nullable();
            $t->boolean('needs_mfa')->default(true);
            $t->text('secret')->nullable();
            $t->text('recovery')->nullable();
            $t->bigInteger('last_step')->nullable();
            $t->bigInteger('auth_version')->default(0);
            $t->string('remember_token')->nullable();
        });
        Schema::create('auth1_challenges', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('user_id');
            $t->string('binding');
            $t->string('mac');
            $t->bigInteger('expires');
            $t->boolean('used')->default(false);
        });
        $this->totp = new Google2FA;
        $this->app->instance(Google2FA::class, $this->totp);
        $this->app->singleton(Factors::class);
        $this->app->singleton(Flow::class);
        $this->user = User::create(['email' => 'client@example.invalid', 'role' => 'client', 'secret' => $this->totp->generateSecretKey(32)]);
        app(PanelRegistry::class)->register(Panel::make()->default()->id('auth1')->path('admin')->login()->pages([Dashboard::class])->authGuard('admin')->multiFactorAuthentication([AppAuthentication::make()->recoverable()->codeWindow(1)], isRequired: true));
        Filament::setCurrentPanel(Filament::getPanel('auth1'));
        require (getenv('AUTH1_VENDOR') ?: dirname(__DIR__, 2).'/vendor').'/filament/filament/routes/web.php';
        Filament::bootCurrentPanel();
        $this->app['router']->getRoutes()->refreshNameLookups();
        config(['sanctum.routes' => false, 'sanctum.guard' => ['client'], 'sanctum.stateful' => ['auth1.test'], 'sanctum.middleware.validate_csrf_token' => Csrf::class]);
        $this->app->register(SanctumServiceProvider::class);
        Livewire::component('auth1-probe', Probe::class);
        Livewire::addPersistentMiddleware([Completed::class]);
        $router = $this->app['router'];
        $router->middleware([EnsureFrontendRequestsAreStateful::class, Origin::class])->group(function ($router): void {
            $router->get('/api/sanctum/me', fn () => ['id' => Auth::guard('sanctum')->id()])->middleware([Authenticate::class.':sanctum', Completed::class.':client']);
            $router->post('/api/sanctum/probe', fn () => ['ok' => true])->middleware([Authenticate::class.':sanctum', Completed::class.':client']);
        });
        $router->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, Origin::class, Csrf::class])->group(function ($router): void {
            $router->get('/api/auth/csrf', fn (Request $r) => ['csrf' => $r->session()->token(), 'sid' => $r->session()->getId()]);
            $router->post('/api/auth/otp', fn (Request $r) => app(Flow::class)->otp($r));
            $router->post('/api/auth/mfa', fn (Request $r) => app(Flow::class)->mfa($r));
            $router->get('/api/me', fn () => ['id' => Auth::guard('client')->id()])->middleware(Completed::class.':client');
            $router->get('/admin/private', fn () => ['admin' => true])->middleware(Completed::class.':admin');
            $router->get('/admin/probe', fn () => Livewire::mount('auth1-probe'))->middleware(Completed::class.':admin');
            $router->post('/admin/livewire/update', fn () => app(HandleRequests::class)->handleUpdate())
                ->middleware(RequireLivewireHeaders::class);

        });
    }

    protected function tearDown(): void
    {
        $this->app['db']->disconnect();
        HandleExceptions::flushState($this);
        $this->app->flush();
        // Тільки створений цим тестом приватний каталог; symlink vendor не обходиться.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function request(string $method, string $uri, array $data = [], array $headers = []): Response
    {
        Livewire::flushState();
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $request = Request::create('https://auth1.test'.$uri, $method, $data, $this->cookies, [], array_merge(['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://auth1.test', 'HTTP_X_CSRF_TOKEN' => $this->csrf ?? ''], $headers), isset($headers['CONTENT_TYPE']) ? json_encode($data, JSON_THROW_ON_ERROR) : null);
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function json(Response $response): array
    {
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function challenge(?User $user = null): array
    {
        $bootstrap = $this->json($this->request('GET', '/api/auth/csrf'));
        $this->csrf = $bootstrap['csrf'];
        $code = '12345678';
        $id = DB::table('auth1_challenges')->insertGetId(['user_id' => ($user ?? $this->user)->id, 'binding' => $bootstrap['sid'], 'mac' => hash_hmac('sha256', $code, 'auth1-synthetic-only'), 'expires' => time() + 300]);

        return ['challenge' => $id, 'code' => $code];
    }

    private function assertStatus(int $status, Response $response): void
    {
        $this->assertSame($status, $response->getStatusCode(), substr($response->getContent(), 0, 1500));
    }

    public function test_passwordless_otp_pending_mfa_then_totp_rotates_session_and_rejects_replays(): void
    {
        $otp = $this->challenge();
        $oldCookies = $this->cookies;
        $oldCsrf = $this->csrf;
        $this->assertNull($this->user->password);
        $this->assertSame(['state' => 'mfa_pending'], $this->json($this->request('POST', '/api/auth/otp', $otp)));
        $this->assertFalse(Auth::guard('client')->check());
        $this->assertStatus(401, $this->request('GET', '/api/me'));
        $this->assertStatus(401, $this->request('GET', '/admin/private'));
        $this->assertStatus(422, $this->request('POST', '/api/auth/otp', $otp));
        $this->assertStatus(422, $this->request('POST', '/api/auth/mfa', ['code' => '000000']));
        $totp = $this->totp->getCurrentOtp($this->user->secret);
        $this->assertSame(['state' => 'authenticated'], $this->json($this->request('POST', '/api/auth/mfa', ['code' => $totp, 'mfa_verified_at' => time()])));
        $this->assertNotSame($oldCookies['auth1_client'], $this->cookies['auth1_client']);
        $goodCookies = $this->cookies;
        $this->assertStatus(200, $this->request('GET', '/api/me'));
        $this->assertStatus(401, $this->request('GET', '/admin/private'));
        $this->assertStatus(419, $this->request('POST', '/api/auth/mfa', ['code' => $totp]));
        $new = $this->json($this->request('GET', '/api/auth/csrf'));
        $this->assertNotSame($oldCsrf, $new['csrf']);
        $this->csrf = $new['csrf'];
        $this->assertStatus(401, $this->request('POST', '/api/auth/mfa', ['code' => $totp]));
        $this->assertSame(1, app(Flow::class)->logins);
        $this->cookies = $oldCookies;
        $this->assertStatus(401, $this->request('GET', '/api/me'));
        $this->cookies = [];
        $this->csrf = null;
        $next = $this->challenge();
        $this->assertStatus(200, $this->request('POST', '/api/auth/otp', $next));
        $this->assertStatus(422, $this->request('POST', '/api/auth/mfa', ['code' => $totp]));
        $this->assertSame(1, app(Flow::class)->logins);
    }

    public function test_csrf_origin_and_browser_binding_are_enforced(): void
    {
        $otp = $this->challenge();
        $this->assertStatus(419, $this->request('POST', '/api/auth/otp', $otp, ['HTTP_X_CSRF_TOKEN' => '']));
        $this->assertStatus(403, $this->request('POST', '/api/auth/otp', $otp, ['HTTP_ORIGIN' => 'https://evil.invalid']));
        $this->cookies = [];
        $this->csrf = $this->json($this->request('GET', '/api/auth/csrf'))['csrf'];
        $this->assertStatus(422, $this->request('POST', '/api/auth/otp', $otp));
        $this->assertSame(0, app(Flow::class)->logins);
    }

    public function test_optional_client_mfa_and_server_side_session_revocation(): void
    {
        $this->user->update(['needs_mfa' => false, 'secret' => null]);
        $this->assertStatus(200, $this->request('POST', '/api/auth/otp', $this->challenge()));
        $this->assertStatus(200, $this->request('GET', '/api/me'));
        $cookie = $this->cookies;
        $this->user->increment('auth_version');
        $this->assertStatus(401, $this->request('GET', '/api/me'));
        $this->cookies = $cookie;
        $this->assertStatus(401, $this->request('GET', '/api/me'));
        $this->assertNull($this->user->password);
    }

    public function test_cookie_attributes_and_deleted_server_record(): void
    {
        $this->user->update(['needs_mfa' => false]);
        $response = $this->request('POST', '/api/auth/otp', $this->challenge());
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'auth1_client');
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
        $this->assertStatus(200, $this->request('GET', '/api/me'));
        foreach (glob($this->root.'/storage/framework/sessions/*') as $path) {
            unlink($path);
        }
        $this->assertStatus(401, $this->request('GET', '/api/me'));
    }

    public function test_admin_account_cannot_use_client_otp(): void
    {
        $this->user->update(['role' => 'admin']);
        $this->assertStatus(403, $this->request('POST', '/api/auth/otp', $this->challenge()));
        $this->assertStatus(401, $this->request('GET', '/admin/private'));
    }

    public function test_shared_filament_adapter_rejects_api_totp_replay_and_vice_versa(): void
    {
        Auth::guard('admin')->setUser($this->user);
        $adapter = app(SharedAppAuthentication::class)->codeWindow(1);
        $code = $this->totp->getCurrentOtp($this->user->secret);
        $this->assertTrue(app(Factors::class)->verify($this->user, $code));
        $this->assertFalse($adapter->verifyCode($code, shouldPreventCodeReuse: true));
        $this->user->update(['last_step' => null]);
        $this->assertTrue($adapter->verifyCode($code, shouldPreventCodeReuse: true));
        $this->assertFalse(app(Factors::class)->verify($this->user, $code));
        $this->assertSame($this->user->secret, $adapter->getSecret($this->user));
    }

    public function test_native_filament_totp_replay_recovery_hashing_and_single_use(): void
    {
        Auth::guard('admin')->setUser($this->user);
        $native = AppAuthentication::make()->recoverable()->codeWindow(1);
        $code = $native->getCurrentCode($this->user);
        $this->assertTrue($native->verifyCode($code, shouldPreventCodeReuse: true));
        $this->assertFalse($native->verifyCode($code, shouldPreventCodeReuse: true));
        $codes = $native->generateRecoveryCodes();
        $this->assertCount(8, $codes);
        $native->saveRecoveryCodes($this->user, $codes);
        $this->assertNotSame($codes[0], $this->user->fresh()->recovery[0]);
        $this->assertTrue($native->verifyRecoveryCode($codes[0], $this->user));
        $this->assertFalse($native->verifyRecoveryCode($codes[0], $this->user));
        $this->assertCount(7, $this->user->fresh()->recovery);
    }

    public function test_required_filament_middleware_checks_enrollment_not_session_assurance(): void
    {
        $this->user->update(['role' => 'admin']);
        Auth::guard('admin')->setUser($this->user);
        $request = Request::create('https://auth1.test/admin/private');
        $response = app(EnsureMultiFactorAuthenticationIsEnabled::class)->handle($request, fn () => new Response('enrolled'));
        $this->assertSame('enrolled', $response->getContent());
        $this->assertNull(session()->get('assurance.admin'));
        // Негативний контроль: власний gate не пропускає цю саму стару session.
        $request->setLaravelSession(session()->driver());
        try {
            app(Completed::class)->handle($request, fn () => new Response('bypass'), 'admin');
            $this->fail('MFA gate');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function test_sanctum_stateful_cookie_supports_null_password_but_needs_mfa_gate(): void
    {
        $this->assertStatus(401, $this->request('GET', '/api/sanctum/me'));
        $this->assertStatus(200, $this->request('POST', '/api/auth/otp', $this->challenge()));
        $this->assertStatus(401, $this->request('GET', '/api/sanctum/me'));
        $this->assertStatus(200, $this->request('POST', '/api/auth/mfa', ['code' => $this->totp->getCurrentOtp($this->user->secret)]));
        $this->csrf = $this->json($this->request('GET', '/api/auth/csrf'))['csrf'];
        $this->assertStatus(200, $this->request('GET', '/api/sanctum/me'));
        $this->assertStatus(200, $this->request('POST', '/api/sanctum/probe'));
        $this->assertStatus(419, $this->request('POST', '/api/sanctum/probe', [], ['HTTP_X_CSRF_TOKEN' => '']));
        $this->assertStatus(401, $this->request('GET', '/api/sanctum/me', [], ['HTTP_ORIGIN' => 'https://evil.invalid']));
        $this->user->increment('auth_version');
        $this->assertStatus(401, $this->request('GET', '/api/sanctum/me'));
        $this->assertNull($this->user->password);
    }

    public function test_fortify_login_validator_rejects_passwordless_input(): void
    {
        config(['fortify.username' => 'email']);
        $rules = (new LoginRequest)->rules();
        $validator = $this->app['validator']->make(['email' => 'client@example.invalid', 'code' => '12345678'], $rules);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('password'));
        $this->assertFalse($this->app['router']->has('login.store'));
    }

    public function test_filament_livewire_login_requires_totp_after_admin_password(): void
    {
        $this->user->update(['role' => 'admin', 'password' => Hash::make('auth1-admin-synthetic-only')]);
        $component = Livewire::test(Login::class)
            ->fillForm(['email' => $this->user->email, 'password' => 'auth1-admin-synthetic-only'])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertFalse(Auth::guard('admin')->check());
        $component->set('data.multiFactor.app.code', $this->totp->getCurrentOtp($this->user->secret))
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertTrue(Auth::guard('admin')->check());
        $this->assertFalse(Auth::guard('client')->check());
    }

    public function test_livewire_http_update_rechecks_server_mfa_version_and_csrf(): void
    {
        $this->user->update(['role' => 'admin']);
        $this->challenge();
        Auth::guard('admin')->login($this->user, false);
        session()->put('assurance.admin', ['audience' => 'admin', 'version' => 0, 'mfa' => true, 'expires' => time() + 600]);
        session()->save();
        // Синтетична завершена admin-сесія для HTTP/Livewire probe; login уже змінив ID.
        $this->csrf = session()->token();
        $this->cookies['auth1_client'] = app('encrypter')->encrypt(CookieValuePrefix::create('auth1_client', app('encrypter')->getKey()).session()->getId(), false);
        $this->app['router']->getRoutes()->match(Request::create('https://auth1.test/admin/livewire/update', 'POST'))->name('auth1.livewire.update');
        $initial = $this->request('GET', '/admin/probe');
        $this->assertStatus(200, $initial);
        preg_match('/wire:snapshot="([^"]+)"/', $initial->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'increment', 'params' => []]]]]];
        $headers = ['HTTP_X_LIVEWIRE' => 'true', 'CONTENT_TYPE' => 'application/json'];
        $this->assertStatus(419, $this->request('POST', '/admin/livewire/update', $payload, $headers + ['HTTP_X_CSRF_TOKEN' => '']));
        $response = $this->request('POST', '/admin/livewire/update', $payload, $headers);
        $this->assertStatus(200, $response);
        $next = $this->json($response)['components'][0]['snapshot'];
        $this->assertSame(1, json_decode($next, true)['data']['counter']);
        $payload['components'][0]['snapshot'] = $next;
        $this->user->increment('auth_version');
        $this->assertStatus(401, $this->request('POST', '/admin/livewire/update', $payload, $headers));
        $this->assertStatus(401, $this->request('GET', '/admin/private'));
    }

    public function test_totp_window_and_expired_or_revoked_pending_flow(): void
    {
        $this->request('POST', '/api/auth/otp', $this->challenge());
        $pending = session()->get('pending');
        session()->put('pending', [...$pending, 'expires' => time() - 1]);
        session()->save();
        $this->assertStatus(401, $this->request('POST', '/api/auth/mfa', ['code' => $this->totp->getCurrentOtp($this->user->secret)]));
        $this->request('POST', '/api/auth/otp', $this->challenge());
        $this->user->increment('auth_version');
        $this->assertStatus(401, $this->request('POST', '/api/auth/mfa', ['code' => $this->totp->getCurrentOtp($this->user->secret)]));
        $this->assertFalse(app(Factors::class)->verify($this->user, $this->totp->oathTotp($this->user->secret, $this->totp->getTimestamp() - 2)));
        $this->assertSame(0, app(Flow::class)->logins);
    }

    public function test_exact_installed_runtime_versions(): void
    {
        foreach (['laravel/framework' => 'v13.38.0', 'filament/filament' => 'v5.10.2', 'livewire/livewire' => 'v4.4.8', 'pragmarx/google2fa' => 'v9.1.0'] as $package => $version) {
            $this->assertSame($version, InstalledVersions::getPrettyVersion($package));
        }
    }

    public function test_required_filament_enrollment_redirects_and_native_cache_loss_allows_replay(): void
    {
        $this->user->update(['role' => 'admin']);
        Auth::guard('admin')->setUser($this->user);
        $native = AppAuthentication::make()->codeWindow(1);
        $code = $native->getCurrentCode($this->user);
        $this->assertTrue($native->verifyCode($code, shouldPreventCodeReuse: true));
        Cache::flush();
        $this->assertTrue($native->verifyCode($code, shouldPreventCodeReuse: true));
        $this->assertTrue(app(Factors::class)->verify($this->user, $code));
        Cache::flush();
        $this->assertFalse(app(Factors::class)->verify($this->user, $code));
        $this->user->update(['secret' => null]);
        Auth::guard('admin')->setUser($this->user->fresh());
        $response = app(EnsureMultiFactorAuthenticationIsEnabled::class)->handle(Request::create('https://auth1.test/admin'), fn () => new Response('bypass'));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/admin/', $response->headers->get('Location'));
    }
}

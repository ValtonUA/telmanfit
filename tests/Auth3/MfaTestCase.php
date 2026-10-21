<?php

namespace Tests\Auth3;

use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

abstract class MfaTestCase extends TestCase
{
    protected MfaService $mfa;

    protected string $binding;

    protected int $id = 8300000000;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        DB::table('users')->insert(['id' => $this->id, 'name' => 'AUTH3 Synthetic', 'role' => 'admin',
            'email' => 'auth3@example.invalid', 'password' => Hash::make('synthetic-password')]);
        $this->mfa = app(MfaService::class);
        $this->binding = bin2hex(random_bytes(32));
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    protected function flow(): string
    {
        return $this->mfa->beginPasswordLogin('auth3@example.invalid', 'synthetic-password', $this->binding);
    }

    protected function code(string $secret, int $offset = 0): string
    {
        if ($offset === -1 && time() % 30 >= 26) {
            usleep((31 - time() % 30) * 1000000);
        }

        return (new Google2FA)->oathTotp($secret, intdiv(time(), 30) + $offset);
    }

    protected function enrolled(): array
    {
        $flow = $this->flow();
        $secret = $this->mfa->beginEnrollment($flow, $this->binding)->reveal();
        $codes = $this->mfa->confirmEnrollment($flow, $this->binding, $this->code($secret, -1))->reveal();

        return [$secret, $codes];
    }

    protected function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected MFA denial');
        } catch (MfaDenied) {
            $this->addToAssertionCount(1);
        }
    }

    protected function request(): Request
    {
        $request = Request::create('/admin');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->setName('telmanfit_admin_session');
        $request->session()->start();
        $request->session()->put('mfa_binding', $this->binding);
        app()->instance('request', $request);

        return $request;
    }
}

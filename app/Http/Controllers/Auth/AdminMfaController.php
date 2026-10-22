<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AdminAssurance;
use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetLimited;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\PostgresBudgets;
use App\Services\Auth\Budgets\SyntheticBudgets;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class AdminMfaController extends Controller
{
    public function __invoke(Request $request, MfaService $mfa): mixed
    {
        // Ніяких JSON відповідей із enrollment/recovery матеріалами чи flashed input.
        abort_if($request->expectsJson(), 406);
        try {
            $name = $request->route()->getName();
            if ($name === 'auth3.login') {
                return $this->page('login');
            }
            if ($name === 'auth3.logout') {
                Auth::guard('admin')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/admin/login');
            }
            if ($request->isMethod('post')) {
                $key = 'auth3:'.hash('sha256', $request->ip());
                if (RateLimiter::tooManyAttempts($key, (int) config('mfa.attempts'))) {
                    return $this->page('login', ['error' => 'Забагато спроб. Спробуйте пізніше.'], 429);
                }
                RateLimiter::hit($key, (int) config('mfa.flow_seconds'));
            }
            if ($request->isMethod('post') && in_array($name, ['auth3.confirm', 'auth3.verify'], true)
                && SyntheticBudgets::enabled()
                && ! app(PostgresBudgets::class)->admit([
                    new BudgetKey(BudgetScope::MfaIp, (string) $request->ip()),
                ])->allowed) {
                return $this->page('login', ['error' => 'Забагато спроб. Спробуйте пізніше.'], 429)->header('Retry-After', '900');
            }
            if ($name === 'auth3.primary') {
                foreach (['email' => 255, 'password' => 1024] as $field => $limit) {
                    if (! is_string($request->input($field)) || strlen($request->input($field)) > $limit) {
                        throw new MfaDenied;
                    }
                }
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $binding = bin2hex(random_bytes(32));
                $flow = $mfa->beginPasswordLogin((string) $request->input('email'), (string) $request->input('password'), $binding, (string) $request->ip());
                $request->session()->put(['mfa_flow' => $flow, 'mfa_binding' => $binding]);

                return redirect('/admin/auth/challenge');
            }
            if ($request->isMethod('post') && in_array($name, ['auth3.confirm', 'auth3.verify'], true)
                && (! is_string($request->input('code')) || strlen($request->input('code')) > 100)) {
                throw new MfaDenied;
            }
            $flow = (string) $request->session()->get('mfa_flow');
            $binding = (string) $request->session()->get('mfa_binding');
            if (! $flow || ! $binding) {
                throw new MfaDenied;
            }
            if ($name === 'auth3.challenge') {
                // Лише вибір restricted форми; не перевірка доступу до бізнес-даних.
                $userId = DB::table('auth_flows')->where('public_id', $flow)->value('user_id');
                $active = DB::table('user_totp_factors')->where('user_id', $userId)->whereNotNull('confirmed_at')->whereNull('disabled_at')->exists();

                return $this->page($active ? 'verify' : 'enroll');
            }
            if ($name === 'auth3.enroll') {
                return $this->page('confirm', ['secret' => $mfa->beginEnrollment($flow, $binding)->reveal()]);
            }
            if ($name === 'auth3.confirm') {
                $codes = $mfa->confirmEnrollment($flow, $binding, (string) $request->input('code'));
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $this->page('recovery', ['codes' => $codes->reveal()]);
            }
            if ($name === 'auth3.verify') {
                // MFA commit перед session issuance; збій issuance вимагає нового входу.
                $proof = $mfa->finishLogin($flow, $binding, (string) $request->input('code'), $request->boolean('recovery'));
                app(AdminAssurance::class)->issue($request, $proof);

                return redirect('/admin');
            }
            abort(404);
        } catch (BudgetLimited $exception) {
            return $this->page('login', ['error' => 'Забагато спроб. Спробуйте пізніше.'], 429)
                ->header('Retry-After', (string) $exception->decision->retryAfter);
        } catch (MfaDenied $exception) {
            return $this->page('login', ['error' => $exception->getMessage()], 422);
        } catch (\Throwable) {
            // Жодного debug trace або SQL bindings у відповіді/логах.
            return $this->page('login', ['error' => 'Автентифікація тимчасово недоступна.'], 503);
        }
    }

    private function page(string $step, array $data = [], int $status = 200): mixed
    {
        return response()->view('auth.admin-mfa', ['step' => $step] + $data, $status)
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    }
}

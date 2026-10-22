<?php

namespace Tests\Auth6B6;

use App\Mail\EmailOtp;
use App\Services\Auth\Budgets\BudgetKey;
use App\Services\Auth\Budgets\BudgetLimited;
use App\Services\Auth\Budgets\BudgetScope;
use App\Services\Auth\Budgets\PostgresBudgets;
use App\Services\Auth\Client\AuthBudget;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaService;
use App\Support\Auth\AuthSecretCodec;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    private string $email;

    private string $ip;

    private string $binding;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        $this->email = bin2hex(random_bytes(12)).'@example.invalid';
        $this->ip = 'synthetic:'.bin2hex(random_bytes(12));
        $this->binding = bin2hex(random_bytes(32));
        DB::beginTransaction();
        $coach = DB::table('users')->insertGetId(['name' => 'B6 synthetic coach', 'role' => 'admin']);
        $this->user = DB::table('users')->insertGetId(['name' => 'B6 synthetic client', 'role' => 'client',
            'email' => $this->email, 'email_normalized' => $this->email, 'email_verified_at' => DB::raw('clock_timestamp()')]);
        DB::table('clients')->insert(['user_id' => $this->user, 'coach_user_id' => $coach]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function budgetCount(BudgetScope $scope, string $input): int
    {
        $key = new BudgetKey($scope, $input);

        return (int) (DB::selectOne('SELECT cardinality(events) n FROM auth_rate_budgets WHERE scope=? AND key_hash=?', [$scope->value, $key->hash])->n ?? 0);
    }

    private function request(): array
    {
        $response = app(ClientAuth::class)->request('login', $this->email, null, $this->binding, $this->ip);
        $mail = Mail::sent(EmailOtp::class)->last();
        $this->assertNotNull($mail);
        preg_match('/<strong>([0-9]{8})<\/strong>/', $mail->render(), $match);

        return [$response['challenge_id'], $match[1]];
    }

    public function test_otp_fifth_failure_commits_attempt_pause_audit_and_denial_does_not_extend(): void
    {
        [$id, $code] = $this->request();
        for ($i = 1; $i <= 5; $i++) {
            try {
                app(ClientAuth::class)->verify($id, 'wrong', $this->binding, $this->ip);
                $this->fail('Expected OTP denial');
            } catch (AuthDenied) {
                $this->assertSame($i, DB::table('auth_challenges')->where('public_id', $id)->value('attempts'));
                $this->assertSame($i, $this->budgetCount(BudgetScope::OtpFailure, $this->email));
                $this->assertSame($i, DB::table('auth_audit_events')->where('event_type', 'client.otp_denied')->where('subject_user_id', $this->user)->count());
            }
        }
        $key = new BudgetKey(BudgetScope::OtpFailure, $this->email);
        $pause = DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->value('blocked_until');
        try {
            app(ClientAuth::class)->verify($id, $code, $this->binding, $this->ip);
            $this->fail('Pause bypass');
        } catch (AuthDenied) {
            $this->assertSame($pause, DB::table('auth_rate_budgets')->where('key_hash', $key->hash)->value('blocked_until'));
            $this->assertSame(5, $this->budgetCount(BudgetScope::OtpFailure, $this->email));
        }
    }

    public function test_failure_audit_fault_rolls_back_attempts_and_failure_budget_but_not_ip(): void
    {
        [$id] = $this->request();
        DB::unprepared("CREATE FUNCTION pg_temp.b6_audit_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_type='client.otp_denied' THEN RAISE EXCEPTION 'synthetic_audit_fault'; END IF; RETURN NEW; END $$; CREATE TRIGGER b6_fault BEFORE INSERT ON auth_audit_events FOR EACH ROW EXECUTE FUNCTION pg_temp.b6_audit_fault()");
        try {
            app(ClientAuth::class)->verify($id, 'wrong', $this->binding, $this->ip);
            $this->fail('Expected fault');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('auth_challenges')->where('public_id', $id)->value('attempts'));
            $this->assertSame(0, $this->budgetCount(BudgetScope::OtpFailure, $this->email));
            $this->assertSame(1, $this->budgetCount(BudgetScope::OtpVerifyIp, $this->ip));
        }
    }

    public function test_known_unknown_resend_new_flow_and_mixed_purpose_group_suppression(): void
    {
        [$id] = $this->request();
        $known = app(ClientAuth::class)->request('login', $this->email, null, $this->binding, $this->ip);
        $unknown = app(ClientAuth::class)->request('login', 'unknown-'.$this->email, null, $this->binding, $this->ip);
        $this->assertSame(array_keys($known), array_keys($unknown));
        $this->assertSame([300, 60], [$known['expires_in'], $known['resend_after']]);
        $this->assertFalse(DB::table('auth_challenges')->whereIn('public_id', [$known['challenge_id'], $unknown['challenge_id']])->exists());
        $this->assertSame(1, $this->budgetCount(BudgetScope::OtpEmailAll, $this->email));
        $this->assertSame(1, $this->budgetCount(BudgetScope::OtpEmailAll, 'unknown-'.$this->email));
        // Mixed purposes share all-purpose quota; independent cooldown keys.
        $all = new BudgetKey(BudgetScope::OtpEmailAll, $this->email);
        for ($i = 1; $i < 15; $i++) {
            $this->assertTrue(app(PostgresBudgets::class)->admit([$all])->allowed);
        }
        $limits = [['cooldown:reauth:'.$this->email, 1, 60], ['issue:15:reauth:'.$this->email, 3, 900],
            ['issue:day:reauth:'.$this->email, 10, 86400], ['issue:all:'.$this->email, 15, 86400]];
        $this->assertFalse(app(AuthBudget::class)->emailGroup($limits, $this->email));
        $this->assertSame(0, $this->budgetCount(BudgetScope::OtpCooldown, 'reauth:'.$this->email));
        $this->assertSame(0, $this->budgetCount(BudgetScope::OtpEmailDay, 'reauth:'.$this->email));
    }

    public function test_successful_otp_consumption_cannot_repeat(): void
    {
        [$id, $code] = $this->request();
        $result = app(ClientAuth::class)->verify($id, $code, $this->binding, $this->ip);
        $this->assertSame('authenticated', $result['next']);
        try {
            app(ClientAuth::class)->verify($id, $code, $this->binding, $this->ip);
            $this->fail('OTP replay');
        } catch (AuthDenied) {
            $this->assertNotNull(DB::table('auth_challenges')->where('public_id', $id)->value('consumed_at'));
        }
    }

    public function test_admin_password_budgets_and_flow_ttl_ceiling_preserve_stricter_policy(): void
    {
        $email = 'admin-'.$this->email;
        $password = 'synthetic-admin-password';
        $admin = DB::table('users')->insertGetId(['name' => 'B6 synthetic admin', 'role' => 'admin', 'email' => $email,
            'password' => Hash::make($password)]);
        $mfa = app(MfaService::class);
        config(['mfa.flow_seconds' => 600]);
        $flow = $mfa->beginPasswordLogin($email, $password, bin2hex(random_bytes(32)), $this->ip);
        $this->assertSame('300.000000', DB::selectOne('SELECT extract(epoch FROM expires_at-created_at)::text ttl FROM auth_flows WHERE public_id=?', [$flow])->ttl);
        config(['mfa.flow_seconds' => 120]);
        $flow = $mfa->beginPasswordLogin($email, $password, bin2hex(random_bytes(32)), $this->ip);
        $this->assertSame('120.000000', DB::selectOne('SELECT extract(epoch FROM expires_at-created_at)::text ttl FROM auth_flows WHERE public_id=?', [$flow])->ttl);
        for ($i = 0; $i < 3; $i++) {
            $mfa->beginPasswordLogin($email, $password, bin2hex(random_bytes(32)), $this->ip);
        }
        try {
            $mfa->beginPasswordLogin($email, $password, bin2hex(random_bytes(32)), $this->ip);
            $this->fail('Admin password budget bypass');
        } catch (BudgetLimited $e) {
            $this->assertFalse($e->decision->allowed);
            $this->assertGreaterThan(0, $e->decision->retryAfter);
            $this->assertLessThanOrEqual(900, $e->decision->retryAfter);
        }
        $this->assertSame(5, $this->budgetCount(BudgetScope::AdminEmail, $email));
        $this->assertSame(6, $this->budgetCount(BudgetScope::AdminIp, $this->ip));
        $this->assertSame(5, DB::table('auth_flows')->where('user_id', $admin)->count());
    }

    private function flow(): string
    {
        DB::table('auth_flows')->where('user_id', $this->user)->whereIn('state', ['pending', 'primary_verified'])->update(['state' => 'invalidated', 'invalidated_at' => DB::raw('clock_timestamp()')]);
        $id = (string) Str::uuid();
        DB::table('auth_flows')->insert(['public_id' => $id, 'user_id' => $this->user, 'auth_version_at_start' => 0,
            'browser_binding_hash' => hash('sha256', $this->binding), 'audience' => 'client', 'purpose' => 'login',
            'state' => 'primary_verified', 'primary_verified_at' => DB::raw('clock_timestamp()'), 'expires_at' => DB::raw("clock_timestamp()+interval '5 minutes'")]);

        return $id;
    }

    public function test_mfa_failure_group_with_factor_audit_rollback_and_shared_user_pause(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        DB::table('user_totp_factors')->insert(['user_id' => $this->user, 'secret_ciphertext' => app(AuthSecretCodec::class)->encryptTotp($secret),
            'key_version' => 'test_v1', 'confirmed_at' => DB::raw('clock_timestamp()')]);
        for ($flowIndex = 0; $flowIndex < 2; $flowIndex++) {
            $flow = $this->flow();
            for ($i = 0; $i < 5; $i++) {
                try {
                    app(MfaService::class)->finishLogin($flow, $this->binding, 'wrong');
                    $this->fail('Expected MFA denial');
                } catch (MfaDenied) {
                    $this->assertSame($i + 1, $this->budgetCount(BudgetScope::MfaFlow, $flow));
                }
            }
        }
        $this->assertSame(10, $this->budgetCount(BudgetScope::MfaUser, (string) $this->user));
        $this->assertSame(10, DB::table('auth_audit_events')->where('event_type', 'mfa.denied')->where('subject_user_id', $this->user)->count());
        $this->assertNotNull(DB::table('auth_rate_budgets')->where('scope', 'mfa_user')->where('key_hash', (new BudgetKey(BudgetScope::MfaUser, (string) $this->user))->hash)->value('blocked_until'));
        $flow = $this->flow();
        try {
            app(MfaService::class)->finishLogin($flow, $this->binding, (new Google2FA)->getCurrentOtp($secret));
            $this->fail('MFA pause bypass');
        } catch (MfaDenied) {
            $this->assertNull(DB::table('user_totp_factors')->where('user_id', $this->user)->value('last_used_step'));
        }
    }
}

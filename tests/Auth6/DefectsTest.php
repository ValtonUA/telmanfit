<?php

namespace Tests\Auth6;

use App\Jobs\DeliverEmailOtp;
use App\Jobs\DeliverSecurityNotice;
use App\Services\Auth\Client\NoticeDeliveryFailed;
use Illuminate\Bus\Dispatcher;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class DefectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        $coach = DB::table('users')->insertGetId(['name' => 'AUTH6 admin', 'role' => 'admin', 'email' => 'admin6@example.invalid', 'email_normalized' => 'admin6@example.invalid', 'email_verified_at' => now()]);
        foreach (['known6', 'other6', 'unverified6'] as $email) {
            $id = DB::table('users')->insertGetId(['name' => 'AUTH6 client', 'email' => $email.'@example.invalid', 'email_normalized' => $email.'@example.invalid', 'email_verified_at' => $email !== 'unverified6' ? now() : null]);
            DB::table('clients')->insert(['user_id' => $id, 'coach_user_id' => $coach]);
        }
        Bus::fake();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel()) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_invalid_login_flow_matrix_has_neutral_shape_and_decoy_without_authority(): void
    {
        $user = DB::table('users')->where('email_normalized', 'known6@example.invalid')->first();
        foreach (['missing', 'foreign', 'other_user', 'expired', 'used', 'invalidated', 'version_stale', 'wrong_purpose', 'wrong_audience'] as $kind) {
            DB::table('auth_flows')->whereIn('state', ['pending', 'primary_verified'])->update(['state' => 'invalidated', 'invalidated_at' => now()]);
            $id = (string) Str::uuid();
            if ($kind !== 'missing') {
                DB::table('auth_flows')->insert(['public_id' => $id, 'user_id' => $kind === 'other_user' ? DB::table('users')->where('email_normalized', 'other6@example.invalid')->value('id') : $user->id, 'auth_version_at_start' => $kind === 'version_stale' ? 99 : 0,
                    'audience' => $kind === 'wrong_audience' ? 'admin' : 'client', 'purpose' => $kind === 'wrong_purpose' ? 'reauth' : 'login', 'action' => 'enable_factor', 'session_binding_hash' => hash('sha256', 'test'),
                    'browser_binding_hash' => hash('sha256', $kind === 'foreign' ? $id : 'matrix'), 'state' => match ($kind) {
                        'used' => 'completed', 'invalidated' => 'invalidated', default => 'pending'
                    }, 'invalidated_at' => $kind === 'invalidated' ? now() : null,
                    'created_at' => now()->subMinutes(10), 'expires_at' => $kind === 'expired' ? now()->subMinute() : now()->addMinutes(5),
                    'primary_verified_at' => $kind === 'used' ? now()->subMinute() : null, 'consumed_at' => $kind === 'used' ? now() : null]);
            }
            foreach (['known6', 'unknown6', 'admin6', 'unverified6'] as $email) {
                Cache::store('database')->flush();
                $response = $this->withSession(['client_binding' => 'matrix'])->postJson('/api/auth/email-otp/request', ['purpose' => 'login', 'email' => $email.'@example.invalid', 'flow_id' => $id]);
                $response->assertStatus(202)->assertExactJson(['challenge_id' => $response->json('challenge_id'), 'expires_in' => 300, 'resend_after' => 60]);
                $this->assertTrue(Str::isUuid($response->json('challenge_id')));
                $this->assertFalse(DB::table('auth_challenges')->where('public_id', $response->json('challenge_id'))->exists());
                $response->assertHeader('Cache-Control', 'no-store, private');
            }
        }
        Bus::assertDispatchedTimes(DeliverEmailOtp::class, 36);
    }

    public function test_suppression_ip_limit_and_queue_failure_are_equal_for_all_email_classes(): void
    {
        foreach (['known6', 'unknown6', 'admin6', 'unverified6'] as $email) {
            Cache::store('database')->flush();
            $payload = ['purpose' => 'login', 'email' => $email.'@example.invalid', 'flow_id' => (string) Str::uuid()];
            for ($i = 0; $i < 20; $i++) {
                $this->postJson('/api/auth/email-otp/request', $payload)->assertStatus(202);
            }
            $this->postJson('/api/auth/email-otp/request', $payload)->assertStatus(429)->assertHeader('Retry-After', '900');
        }
        foreach (['known6', 'unknown6', 'admin6', 'unverified6'] as $email) {
            $this->postJson('/api/auth/email-otp/request', ['purpose' => 'login', 'email' => $email.'@example.invalid', 'flow_id' => 'malformed'])
                ->assertStatus(422)->assertJsonPath('code', 'validation_error');
        }
        Bus::swap(new class
        {
            public function dispatch($job): void
            {
                throw new \RuntimeException('synthetic queue unavailable');
            }
        });
        foreach (['known6', 'unknown6', 'admin6', 'unverified6'] as $email) {
            Cache::store('database')->flush();
            $this->postJson('/api/auth/email-otp/request', ['purpose' => 'login', 'email' => $email.'@example.invalid', 'flow_id' => (string) Str::uuid()])
                ->assertStatus(503)->assertExactJson(['code' => 'auth_unavailable']);
        }
        DB::statement('ALTER TABLE cache RENAME TO auth6_cache_outage');
        foreach (['known6', 'unknown6', 'admin6', 'unverified6'] as $email) {
            $this->postJson('/api/auth/email-otp/request', ['purpose' => 'login', 'email' => $email.'@example.invalid', 'flow_id' => (string) Str::uuid()])
                ->assertStatus(503)->assertExactJson(['code' => 'auth_unavailable']);
        }
    }

    public function test_mixed_gc_retains_client_and_unknown_context_until_client_idle_ttl(): void
    {
        $id = DB::table('users')->where('email_normalized', 'known6@example.invalid')->value('id');
        foreach (['client', 'admin', 'guest', 'restricted', 'expired_client'] as $kind) {
            $session = str_pad($kind, 40, 'x');
            DB::table('sessions')->insert(['id' => $session, 'payload' => base64_encode('synthetic-payload'), 'last_activity' => time() - ($kind === 'expired_client' ? 43201 : 960)]);
            if (in_array($kind, ['client', 'admin', 'expired_client'])) {
                DB::table('auth_session_states')->insert(['session_id' => $session, 'user_id' => $id, 'audience' => $kind === 'admin' ? 'admin' : 'client',
                    'browser_binding_hash' => hash('sha256', 'test'), 'auth_version' => 0, 'primary_method' => $kind === 'admin' ? 'password' : 'email_otp',
                    'primary_verified_at' => now()->subMinutes(20), 'mfa_verified_at' => $kind === 'admin' ? now()->subMinutes(19) : null, 'mfa_method' => $kind === 'admin' ? 'totp' : null, 'absolute_expires_at' => now()->addHour()]);
            }
        }
        config(['session.lifetime' => 15]);
        app('session')->forgetDrivers();
        $handler = app('session')->driver()->getHandler();
        $this->assertGreaterThanOrEqual(2, $handler->gc(900));
        foreach (['admin', 'expired_client'] as $kind) {
            $this->assertFalse(DB::table('sessions')->where('id', str_pad($kind, 40, 'x'))->exists());
            $this->assertFalse(DB::table('auth_session_states')->where('session_id', str_pad($kind, 40, 'x'))->exists());
        }
        foreach (['client', 'guest', 'restricted'] as $kind) {
            $this->assertTrue(DB::table('sessions')->where('id', str_pad($kind, 40, 'x'))->exists());
        }
        $this->assertSame('', $handler->read(str_pad('client', 40, 'x')));
        config(['session.lifetime' => 720]);
        app('session')->forgetDrivers();
        $this->assertSame('synthetic-payload', app('session')->driver()->getHandler()->read(str_pad('client', 40, 'x')));
        $this->assertSame(0, app('session')->driver()->getHandler()->gc(43200));
    }

    public function test_admin_http_store_is_encrypted_with_scoped_cookie(): void
    {
        $response = $this->get('/admin/login');
        $response->assertOk();
        $this->assertInstanceOf(EncryptedStore::class, app('session')->driver());
        $id = app('session')->driver()->getId();
        $payload = base64_decode(DB::table('sessions')->where('id', $id)->value('payload'));
        $this->assertStringNotContainsString('_token', $payload);
        $decoded = json_decode(Crypt::decrypt($payload), true);
        $this->assertArrayHasKey('_token', $decoded);
        $cookies = $response->headers->getCookies();
        $admin = array_values(array_filter($cookies, fn ($cookie) => $cookie->getName() === 'telmanfit_admin_session'))[0];
        $this->assertSame('/admin', $admin->getPath());
        $this->assertTrue($admin->isHttpOnly());
        $this->assertSame('lax', $admin->getSameSite());
    }

    public function test_notice_job_failure_redacts_transport_exception_and_correlates_after_dequeue(): void
    {
        $subject = DB::table('users')->where('email_normalized', 'known6@example.invalid')->value('id');
        $correlation = (string) Str::uuid();
        $job = new DeliverSecurityNotice('CANARY@example.invalid', $subject, $correlation);
        config(['queue.default' => 'database']);
        app('queue')->connection('database')->push($job);
        $payload = DB::table('jobs')->orderByDesc('id')->value('payload');
        $this->assertStringNotContainsString('CANARY', $payload);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('CANARY SMTP password OTP payload'));
        Log::shouldReceive('error')->once()->with('client.notice_failed', ['request_id' => $correlation, 'subject_user_id' => $subject, 'audit' => 'recorded']);
        Bus::swap(new Dispatcher(app()));
        $queued = app('queue')->connection('database')->pop();
        try {
            $queued->fire();
            $this->fail('Expected sanitized delivery failure');
        } catch (NoticeDeliveryFailed $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('CANARY', (string) $e);
        }
        $event = DB::table('auth_audit_events')->where('request_id', $correlation)->first();
        $this->assertSame('error', $event->outcome);
        $this->assertSame($subject, $event->subject_user_id);
    }
}

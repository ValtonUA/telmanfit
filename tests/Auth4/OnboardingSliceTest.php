<?php

namespace Tests\Auth4;

use App\Http\Controllers\Api\ClientOnboardingController;
use App\Mail\EmailOtp;
use App\Services\Auth\Client\AuthDenied;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OnboardingSliceTest extends TestCase
{
    private Request $request;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        DB::beginTransaction();
        Mail::fake();

        $coachId = 8400000010;
        DB::table('users')->insert(['id' => $coachId, 'name' => 'Demo coach', 'role' => 'admin']);
        $token = bin2hex(random_bytes(32));
        DB::table('client_invitations')->insert([
            'token_hash' => hash('sha256', $token),
            'created_by_user_id' => $coachId,
            'coach_user_id' => $coachId,
        ]);

        $binding = bin2hex(random_bytes(32));
        $auth = app(ClientAuth::class);
        $flow = $auth->exchange($token, $binding, '192.0.2.18')['flow_id'];
        $challenge = $auth->request('register', 'slice@example.invalid', $flow, $binding, '192.0.2.18')['challenge_id'];
        preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $matches);
        $auth->verify($challenge, $matches[1], $binding, '192.0.2.18');
        $proof = $auth->complete($flow, 'Клієнт із анкетою', 'slice@example.invalid', $binding, '192.0.2.18');

        $this->request = Request::create('/api/onboarding', 'POST', server: ['REMOTE_ADDR' => '192.0.2.18']);
        $this->request->setLaravelSession(app('session')->driver());
        $this->request->session()->setName('telmanfit_client_session');
        $this->request->session()->start();
        $this->request->session()->put('client_binding', $binding);
        $this->request->session()->save();
        app()->instance('request', $this->request);
        app(ClientSession::class)->issue($this->request, $proof);

        $this->clientId = DB::table('clients')->where('user_id', app(ClientSession::class)->user($this->request)->id)->value('id');
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function save(array $data): array
    {
        $this->request->replace($data);
        $result = app(ClientOnboardingController::class)->store($this->request, app(ClientSession::class));

        return $result->getData(true);
    }

    public function test_client_saves_reloads_and_edits_real_answers_without_starting_coaching(): void
    {
        $controller = app(ClientOnboardingController::class);
        $this->assertSame([], $controller->show($this->request, app(ClientSession::class))->getData(true)['answers']);

        $data = $this->save([
            'top_reasons' => '  Вечірній голод і брак часу  ',
            'workplace' => 'hybrid',
            'meals_per_day' => 3,
        ]);
        $this->assertTrue($data['saved']);
        $this->assertSame('Вечірній голод і брак часу', $data['answers']['top_reasons']);

        // Fresh read is from PostgreSQL, not React state or a mock.
        $this->assertEquals($data['answers'], $controller->show($this->request, app(ClientSession::class))->getData(true)['answers']);
        $this->assertSame(1, DB::table('client_onboardings')->where('client_id', $this->clientId)->count());
        $this->assertNull(DB::table('client_onboardings')->where('client_id', $this->clientId)->value('completed_at'));
        $this->assertSame('pending', DB::table('clients')->where('id', $this->clientId)->value('status'));
        $this->assertNull(DB::table('clients')->where('id', $this->clientId)->value('coaching_started_at'));

        $this->save(['top_reasons' => 'Нові причини', 'workplace' => 'office', 'meals_per_day' => 2]);
        $this->assertSame(1, DB::table('client_onboardings')->where('client_id', $this->clientId)->count());
        $this->assertSame('Нові причини', $controller->show($this->request, app(ClientSession::class))->getData(true)['answers']['top_reasons']);
    }

    public function test_future_json_keys_and_other_client_answers_are_preserved(): void
    {
        $otherUser = DB::table('users')->insertGetId(['name' => 'Other client', 'role' => 'client']);
        $coach = DB::table('clients')->where('id', $this->clientId)->value('coach_user_id');
        $otherClient = DB::table('clients')->insertGetId(['user_id' => $otherUser, 'coach_user_id' => $coach]);
        DB::table('client_onboardings')->insert([
            'client_id' => $this->clientId,
            'answers' => json_encode(['future_question' => 'keep']),
        ]);
        DB::table('client_onboardings')->insert([
            'client_id' => $otherClient,
            'answers' => json_encode(['top_reasons' => 'private']),
        ]);

        $this->save(['top_reasons' => 'Своя відповідь', 'workplace' => 'home', 'meals_per_day' => 4]);
        $own = app(ClientOnboardingController::class)->show($this->request, app(ClientSession::class))->getData(true)['answers'];
        $this->assertSame('keep', $own['future_question']);
        $this->assertSame('private', json_decode(DB::table('client_onboardings')->where('client_id', $otherClient)->value('answers'), true)['top_reasons']);
        $this->assertStringNotContainsString('private', json_encode($own));
    }

    public function test_invalid_answer_is_rejected_without_database_writes(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->save(['top_reasons' => 'Тест', 'workplace' => 'other', 'meals_per_day' => 9]);
        } finally {
            $this->assertSame(0, DB::table('client_onboardings')->where('client_id', $this->clientId)->count());
        }
    }

    public function test_logout_revokes_access_and_does_not_erase_saved_answers(): void
    {
        $this->save(['top_reasons' => 'Причина', 'workplace' => 'office', 'meals_per_day' => 3]);
        app(ClientSession::class)->logout($this->request);
        $this->expectException(AuthDenied::class);
        try {
            app(ClientOnboardingController::class)->show($this->request, app(ClientSession::class));
        } finally {
            $this->assertSame(1, DB::table('client_onboardings')->where('client_id', $this->clientId)->count());
        }
    }
}

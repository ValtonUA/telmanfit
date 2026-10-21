<?php

namespace Tests\Auth3;

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Client;
use App\Services\Auth\AdminAssurance;
use App\Services\Auth\MfaDenied;
use App\Services\Auth\MfaProof;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Auth3\Fixtures\AssuranceProbe;

class AssuranceBoundaryTest extends MfaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Livewire::component('auth3-assurance-probe', AssuranceProbe::class);
        Route::get('/admin/auth3-probe', fn () => Blade::render('<livewire:auth3-assurance-probe/>'))->middleware('web');
        Route::post('/admin/auth3-validation', fn () => throw ValidationException::withMessages(['value' => 'Синтетична помилка.']))->middleware('web');
        Route::get('/admin/auth3-unexpected', fn () => throw new \RuntimeException('synthetic business error'))->middleware('web');
        Route::get('/admin/auth3-policy', fn () => throw new AuthorizationException)->middleware('web');
        Route::get('/admin/auth3-missing', fn () => throw (new ModelNotFoundException)->setModel(Client::class))->middleware('web');
    }

    private function assured(): void
    {
        [$secret] = $this->enrolled();
        $request = $this->request();
        app(AdminAssurance::class)->issue($request, $this->mfa->finishLogin($this->flow(), $this->binding, $this->code($secret)));
        $this->withCookie('telmanfit_admin_session', $request->session()->getId())->withCredentials();
    }

    public static function businessExceptions(): array
    {
        return array_map(fn ($class) => [$class], [ValidationException::class, AuthorizationException::class,
            ModelNotFoundException::class, NotFoundHttpException::class, \RuntimeException::class]);
    }

    #[DataProvider('businessExceptions')]
    public function test_gate_rethrows_the_original_business_exception_and_rolls_back(string $class): void
    {
        [$secret] = $this->enrolled();
        $request = $this->request();
        app(AdminAssurance::class)->issue($request, $this->mfa->finishLogin($this->flow(), $this->binding, $this->code($secret)));
        $exception = $class === ValidationException::class ? ValidationException::withMessages(['value' => 'synthetic']) : new $class;
        $level = DB::transactionLevel();
        $original = DB::table('users')->where('id', $this->id)->value('name');
        try {
            app(RequireAdminMfa::class)->handle($request, function () use ($exception) {
                DB::table('users')->where('id', $this->id)->update(['name' => 'rolled back business write']);
                throw $exception;
            });
            $this->fail('Business exception was swallowed');
        } catch (\Throwable $caught) {
            $this->assertSame($exception, $caught);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($original, DB::table('users')->where('id', $this->id)->value('name'));
    }

    public function test_authorized_http_preserves_validation_redirect_json_422_policy_403_and_model_404(): void
    {
        $this->assured();
        $this->postJson('/admin/auth3-validation')->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->from('/admin/clients')->post('/admin/auth3-validation')->assertRedirect('/admin/clients')->assertSessionHasErrors('value');
        $this->get('/admin/auth3-policy')->assertForbidden();
        $this->get('/admin/auth3-unexpected')->assertStatus(500);
        $this->get('/admin/auth3-missing')->assertNotFound();
        $this->get('/admin/clients/2147483647/edit')->assertNotFound();
        $this->get('/admin/clients/create')->assertForbidden();
    }

    private function snapshot(string $url): string
    {
        $response = $this->get($url)->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $expected = str_ends_with($url, '/edit')
            ? EditClient::class
            : 'auth3-assurance-probe';
        $names = [];
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            $name = json_decode($snapshot, true)['memo']['name'];
            $names[] = $name;
            if ($name === $expected) {
                return $snapshot;
            }
        }
        $this->fail('Expected component snapshot missing: '.implode(', ', $names));
    }

    private function update(string $snapshot, string $method, array $updates = []): TestResponse
    {
        return $this->postJson('/admin/livewire/update', ['components' => [
            ['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['method' => $method, 'params' => []]]],
        ]], ['X-Livewire' => 'true']);
    }

    public function test_real_livewire_http_retains_validation_policy_and_model_not_found_semantics(): void
    {
        $this->assured();
        $snapshot = $this->snapshot('/admin/auth3-probe');
        $response = $this->update($snapshot, 'rejectValidation')->assertOk();
        $data = json_decode($response->json('components.0.snapshot'), true);
        $this->assertArrayHasKey('value', $data['memo']['errors']);
        $this->update($snapshot, 'rejectAuthorization')->assertForbidden();
        $this->update($snapshot, 'missingRecord')->assertNotFound();
    }

    public function test_real_filament_edit_form_retains_required_and_domain_validation_errors(): void
    {
        $this->assured();
        $id = DB::table('clients')->insertGetId(['coach_user_id' => $this->id]);
        $snapshot = $this->snapshot('/admin/clients/'.$id.'/edit');
        foreach ([['data.coach_user_id' => null], ['data.user_id' => $this->id]] as $updates) {
            $response = $this->update($snapshot, 'save', $updates)->assertOk();
            $data = json_decode($response->json('components.0.snapshot'), true);
            $this->assertArrayHasKey('data.coach_user_id', $data['memo']['errors']);
            $this->assertNull(DB::table('clients')->where('id', $id)->value('user_id'));
            $this->assertSame($this->id, DB::table('clients')->where('id', $id)->value('coach_user_id'));
        }
    }

    public function test_http_and_livewire_gate_failures_still_return_403_or_503_without_running_actions(): void
    {
        $this->assured();
        $snapshot = $this->snapshot('/admin/auth3-probe');
        foreach ([new MfaDenied, new \RuntimeException('synthetic gate failure')] as $error) {
            $this->app->bind(AdminAssurance::class, fn () => throw $error);
            $status = $error instanceof MfaDenied ? 403 : 503;
            $this->get('/admin/auth3-probe')->assertStatus($status);
            $this->update($snapshot, 'missingRecord')->assertStatus($status)->assertDontSee('synthetic gate failure');
        }
    }

    public function test_issue_rejects_forged_proofs_and_invalid_primary_or_mfa_context_before_session_rotation(): void
    {
        $request = $this->request();
        $id = $request->session()->getId();
        foreach ([['admin', 'email_otp', 'totp'], ['admin', 'password', 'none'], ['client', 'password', 'totp'], ['admin', 'password', 'totp']] as [$audience, $primary, $method]) {
            $proof = new MfaProof($this->id, $audience, 0, $primary, now()->subSecond()->toIso8601String(), now()->toIso8601String(), $method);
            $this->denied(fn () => app(AdminAssurance::class)->issue($request, $proof));
            $this->assertSame($id, $request->session()->getId());
            $this->assertFalse(DB::table('auth_session_states')->where('user_id', $this->id)->exists());
        }
    }

    public function test_issue_rejects_a_genuine_proof_from_another_browser_binding(): void
    {
        [$secret] = $this->enrolled();
        $proof = $this->mfa->finishLogin($this->flow(), $this->binding, $this->code($secret));
        $request = $this->request();
        $id = $request->session()->getId();
        $request->session()->put('mfa_binding', 'another-browser');
        $this->denied(fn () => app(AdminAssurance::class)->issue($request, $proof));
        $this->assertSame($id, $request->session()->getId());
        $this->assertFalse(DB::table('auth_session_states')->where('user_id', $this->id)->exists());
    }

    public function test_issue_rejects_an_untyped_payload(): void
    {
        $this->expectException(\TypeError::class);
        app(AdminAssurance::class)->issue($this->request(), (object) ['audience' => 'admin']);
    }

    public function test_issue_rejects_a_genuine_proof_if_its_verification_transaction_was_rolled_back(): void
    {
        [$secret] = $this->enrolled();
        $flow = $this->flow();
        DB::beginTransaction();
        $proof = $this->mfa->finishLogin($flow, $this->binding, $this->code($secret));
        DB::rollBack();
        $this->denied(fn () => app(AdminAssurance::class)->issue($this->request(), $proof));
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\ClientAuthBoundary;
use Illuminate\Http\Request;
use Tests\TestCase;

class OnboardingBoundaryTest extends TestCase
{
    public function test_onboarding_uses_client_context_only_in_local_and_testing(): void
    {
        foreach (['local', 'testing', 'production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertSame(in_array($environment, ['local', 'testing']), ClientAuthBoundary::covers(Request::create('/api/onboarding')));
            $this->assertTrue(ClientAuthBoundary::covers(Request::create('/api/me')));
            $this->assertTrue(ClientAuthBoundary::covers(Request::create('/api/auth/csrf')));
            $this->assertFalse(ClientAuthBoundary::covers(Request::create('/api/health')));
        }
    }

    public function test_disabled_local_auth_does_not_expose_onboarding(): void
    {
        $this->app->instance('env', 'local');
        config(['auth4.enabled' => false]);
        $this->getJson('/api/onboarding')->assertNotFound()->assertExactJson(['code' => 'not_found']);
        $this->postJson('/api/onboarding', [])->assertNotFound()->assertExactJson(['code' => 'not_found']);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaFallbackTest extends TestCase
{
    public function test_spa_csp_only_allows_validated_exact_image_hosts(): void
    {
        $this->skipWhenSpaIsNotBuilt();
        config(['presentation.image_hosts' => ['images.example.com', '*.example.com', 'localhost', 'example.com; script-src *']]);
        $policy = $this->get('/app/meal-plan')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: https://images.example.com;", $policy);
        $this->assertStringNotContainsString('*', $policy);
        $this->assertStringNotContainsString('localhost', $policy);
        $this->assertStringContainsString("connect-src 'self'; object-src 'none'", $policy);
    }

    public function test_spa_fallback_serves_the_index_for_the_app_root(): void
    {
        $this->skipWhenSpaIsNotBuilt();

        $response = $this->get('/app');

        $response->assertOk();

        $this->assertStringContainsString(
            'text/html',
            (string) $response->headers->get('content-type'),
        );
    }

    public function test_spa_fallback_serves_the_index_for_nested_client_routes(): void
    {
        $this->skipWhenSpaIsNotBuilt();

        $this->get('/app/training/plan/42')->assertOk();
    }

    public function test_spa_fallback_does_not_intercept_the_api_prefix(): void
    {
        $response = $this->get('/api/health');

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        $this->assertStringNotContainsString(
            'text/html',
            (string) $response->headers->get('content-type'),
        );
    }

    public function test_spa_fallback_does_not_intercept_the_admin_prefix(): void
    {
        // Filament answers on /admin (redirect to its login page when the
        // visitor is not authenticated). The important part is that the SPA
        // fallback does not swallow it and does not return 404.
        $this->assertNotSame(404, $this->get('/admin')->getStatusCode());
    }

    public function test_unknown_paths_are_not_captured_by_the_spa_fallback(): void
    {
        $this->get('/definitely-not-a-route')->assertNotFound();
    }

    private function skipWhenSpaIsNotBuilt(): void
    {
        if (! is_file(public_path('app/index.html'))) {
            $this->markTestSkipped('React SPA build is missing. Run `npm run build` inside client/.');
        }
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok_status(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_health_endpoint_responds_with_json_content_type(): void
    {
        $response = $this->getJson('/api/health');

        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('content-type'),
        );
    }

    public function test_health_endpoint_is_accessible_without_authentication(): void
    {
        $this->get('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientConfigTest extends TestCase
{
    public function test_public_client_settings_follow_server_thresholds_without_exposing_secrets(): void
    {
        config(['commutelive.vehicle_stale_seconds' => 45]);
        $data = $this->getJson('/api/client-config')->assertOk()->assertJsonPath('data.poll_interval_ms', 4000)
            ->assertJsonPath('data.vehicle_stale_seconds', 45)->json('data');
        $this->assertSame(array_keys(config('commutelive.commuter') + ['vehicle_stale_seconds' => 45, 'traffic_stale_seconds' => 90]), array_keys($data));
    }
}

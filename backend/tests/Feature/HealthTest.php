<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_is_public_and_has_the_expected_contract(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson([
            'status' => 'ok',
            'service' => 'CommuteLive API',
        ]);
    }
}

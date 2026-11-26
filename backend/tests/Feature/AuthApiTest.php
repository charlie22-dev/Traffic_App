<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\Trip;
use App\Models\LocationPing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_password_and_cannot_create_an_admin_or_simulated_identity(): void
    {
        $data = ['name' => 'Student', 'email' => 'student@example.test', 'password' => 'test-only-password', 'password_confirmation' => 'test-only-password'];
        $this->postJson('/api/register', $data + ['role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/register', $data + ['is_simulated' => true])->assertUnprocessable()->assertJsonValidationErrors('is_simulated');
        $response = $this->postJson('/api/register', $data)->assertCreated()->assertJsonPath('data.user.role', 'commuter')->assertJsonMissingPath('data.user.password');
        $this->assertTrue(Hash::check($data['password'], User::first()->password));
        $this->assertNotSame($response->json('data.token'), User::first()->tokens()->first()->token);
        $this->postJson('/api/register', $data)->assertUnprocessable();
    }

    public function test_driver_can_register_but_has_no_vehicles_assigned(): void
    {
        $response = $this->postJson('/api/register', ['name' => 'Driver', 'email' => 'driver@example.test', 'password' => 'test-only-password',
            'password_confirmation' => 'test-only-password', 'role' => 'driver'])->assertCreated();
        $this->withToken($response->json('data.token'))->getJson('/api/driver/vehicles')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_login_and_logout_use_and_revoke_real_sanctum_tokens(): void
    {
        $user = User::factory()->driver()->create(['password' => 'test-only-password']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnprocessable();
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-only-password'])->assertOk();
        $token = $login->json('data.token');
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_guest_and_expired_tokens_cannot_use_driver_endpoints(): void
    {
        $this->postJson('/api/driver/pings', [])->assertUnauthorized();
        $user = User::factory()->driver()->create();
        $token = $user->createToken('expired', ['driver'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/driver/vehicles')->assertUnauthorized();
    }

    public function test_bad_login_is_rate_limited_with_retry_header(): void
    {
        config(['commutelive.auth_requests_per_minute' => 2]);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_logout_ends_the_active_trip_and_removes_public_visibility(): void
    {
        $user = User::factory()->driver()->create();
        $vehicle = Vehicle::factory()->create(['driver_id' => $user->id]);
        $trip = Trip::factory()->create(['vehicle_id' => $vehicle->id]);
        LocationPing::factory()->create(['trip_id' => $trip->id]);
        $token = $user->createToken('logout-test', ['driver'])->plainTextToken;
        $this->getJson('/api/vehicles')->assertJsonCount(1, 'data');
        $this->withToken($token)->postJson('/api/logout')->assertOk()->assertJsonPath('tracking_on', false);
        $this->assertNotNull($trip->fresh()->ended_at);
        $this->assertNull($trip->fresh()->active_driver_id);
        $this->getJson('/api/vehicles')->assertJsonCount(0, 'data');
    }
}

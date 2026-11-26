<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(10)],
            'role' => 'sometimes|in:commuter,driver', 'is_simulated' => 'prohibited',
        ]);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $user->forceFill(['role' => $data['role'] ?? 'commuter'])->save();

        return $this->token($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', strtolower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are invalid.']]);
        }

        return $this->token($user);
    }

    private function token(User $user, int $status = 200): JsonResponse
    {
        $expires = now()->addMinutes(config('commutelive.token_lifetime_minutes'));
        $token = $user->createToken('commutelive', [$user->role], $expires);

        return response()->json(['data' => ['user' => $user->fresh(), 'token' => $token->plainTextToken,
            'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601String()]], $status);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request) {
            $trip = Trip::where('active_driver_id', $request->user()->id)->lockForUpdate()->first();
            $trip?->update(['ended_at' => now(), 'active_driver_id' => null, 'active_vehicle_id' => null]);
            $request->user()->currentAccessToken()?->delete();
        });

        return response()->json(['tracking_on' => false, 'message' => 'Signed out. Tracking is OFF and the current access token has been revoked.']);
    }
}

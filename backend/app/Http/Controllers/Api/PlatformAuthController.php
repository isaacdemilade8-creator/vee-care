<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = PlatformUser::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $this->audit($request, 'platform.auth.login', $user, ['email' => $user->email]);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $user->createToken('platform-web')->plainTextToken,
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit($request, 'platform.auth.logout', $request->user());

        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Record a control-plane auth event. Never includes passwords or tokens.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(Request $request, string $event, ?PlatformUser $user = null, array $metadata = []): void
    {
        PlatformAuditLog::query()->create([
            'event' => $event,
            'platform_user_id' => $user?->id,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}

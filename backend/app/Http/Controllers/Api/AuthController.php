<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\TenantConfigurationService;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['sometimes', 'in:patient'],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
        ]);

        $data['role'] = 'patient';

        $user = User::create($data);
        PatientProfile::ensureFor($user);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.register',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $user->createToken('healthtech-web')->plainTextToken,
        ], 201);
    }

    public function login(Request $request, TenantResolver $resolver, TenantConfigurationService $configuration): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Deactivated accounts cannot sign in (their tokens are revoked on
        // deactivation; this also blocks fresh logins).
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated. Contact your administrator.'],
            ]);
        }

        // A hospital may disable an optional role (e.g. pharmacist): the
        // account still exists but must not be able to sign in.
        $tenant = $resolver->current();

        if ($tenant && ! $configuration->isRoleEnabled($tenant, (string) $user->role)) {
            throw ValidationException::withMessages([
                'email' => ['Your role is not enabled at this hospital. Contact your administrator.'],
            ]);
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.login',
            'metadata' => ['email' => $credentials['email']],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $user->createToken('healthtech-web')->plainTextToken,
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'auth.logout',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}

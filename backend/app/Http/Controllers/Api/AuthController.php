<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\PatientProfile;
use App\Models\User;
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
        $this->ensurePatientProfile($user);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $user->createToken('healthtech-web')->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
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
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function ensurePatientProfile(User $user): void
    {
        if (! $user->isRole('patient')) {
            return;
        }

        PatientProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'organization_id' => null,
                'branch_id' => null,
                'patient_number' => 'PAT-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'allergies' => [],
                'chronic_conditions' => [],
            ],
        );
    }
}

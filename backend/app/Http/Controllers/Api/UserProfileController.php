<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $profiles = User::query()
            ->withCount(['followers', 'following'])
            ->withCount('practitionerReviews')
            ->withAvg('practitionerReviews', 'rating')
            ->when($request->string('role')->toString(), fn ($query, $role) => $query->where('role', $role))
            ->when($request->string('specialty')->toString(), fn ($query, $specialty) => $query->where('specialty', $specialty))
            ->when($request->integer('min_rating'), fn ($query, $rating) => $query->having('practitioner_reviews_avg_rating', '>=', $rating))
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('specialty', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 24));

        return UserResource::collection($profiles);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->loadCount(['followers', 'following', 'practitionerReviews'])->loadAvg('practitionerReviews', 'rating'));
    }

    public function update(Request $request, AuditService $audit): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'url', 'max:2048'],
            'bio' => ['nullable', 'string', 'max:800'],
            'location' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:2048'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($request->user()->id)],
        ]);

        $request->user()->update($data);

        $audit->record($request, 'profile.updated');

        return new UserResource($request->user()->fresh()->loadCount(['followers', 'following', 'practitionerReviews'])->loadAvg('practitionerReviews', 'rating'));
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role === 'hospital_admin' ? 'admin' : $this->role,
            'organizationId' => $this->organization_id,
            'branchId' => $this->branch_id,
            'specialty' => $this->specialty,
            'phone' => $this->phone,
            'avatarUrl' => $this->avatar_url,
            'bio' => $this->bio,
            'location' => $this->location,
            'website' => $this->website,
            'dateOfBirth' => $this->date_of_birth?->toDateString(),
            'followersCount' => $this->whenCounted('followers'),
            'followingCount' => $this->whenCounted('following'),
            'reviewsCount' => $this->whenCounted('practitionerReviews'),
            'averageRating' => $this->when(
                array_key_exists('practitioner_reviews_avg_rating', $this->resource->getAttributes()),
                fn () => $this->practitioner_reviews_avg_rating !== null ? round((float) $this->practitioner_reviews_avg_rating, 1) : null
            ),
            'canReview' => $request->user()?->isRole('patient')
                ? \App\Models\Appointment::query()
                    ->where('patient_id', $request->user()->id)
                    ->where('doctor_id', $this->id)
                    ->where('status', 'completed')
                    ->exists()
                : false,
            'isFollowing' => $request->user()
                ? $this->followers()->where('follower_id', $request->user()->id)->exists()
                : false,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}

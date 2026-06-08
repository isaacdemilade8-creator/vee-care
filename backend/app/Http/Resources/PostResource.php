<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'imageUrl' => $this->image_url,
            'shareCount' => 0,
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'email' => '',
                'role' => $this->author->role === 'hospital_admin' ? 'admin' : $this->author->role,
                'avatarUrl' => $this->author->avatar_url,
            ]),
            'repost' => null,
            'comments' => PostCommentResource::collection($this->whenLoaded('comments')),
            'counts' => [
                'likes' => 0,
                'saves' => 0,
                'comments' => $this->comments_count ?? 0,
                'reposts' => 0,
            ],
            'viewer' => [
                'liked' => false,
                'saved' => false,
                'canEdit' => $viewer?->isRole('admin', 'super_admin') ?? false,
            ],
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

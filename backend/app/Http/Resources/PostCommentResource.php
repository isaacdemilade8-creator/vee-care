<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'email' => '',
                'role' => $this->author->role === 'hospital_admin' ? 'admin' : $this->author->role,
                'avatarUrl' => $this->author->avatar_url,
            ]),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicalRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'fileUrl' => $this->file_url,
            'fileType' => $this->file_type,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'uploader' => new UserResource($this->whenLoaded('uploader')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}

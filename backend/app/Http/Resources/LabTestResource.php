<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class LabTestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'resultSummary' => $this->result_summary,
            'reportPath' => $this->report_path,
            'reportUrl' => $this->report_path ? Storage::disk('public')->url($this->report_path) : null,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'requestedBy' => new UserResource($this->whenLoaded('requester')),
            'assignedTo' => $this->assignee ? new UserResource($this->assignee) : null,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

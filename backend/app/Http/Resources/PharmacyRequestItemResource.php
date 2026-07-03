<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PharmacyRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isDoctor = $user?->isRole('doctor');

        return [
            'id' => $this->id,
            'medicationName' => $this->medication_name,
            'dosage' => $this->dosage,
            'quantity' => $this->quantity,
            'instructions' => $this->instructions,
            'availabilityStatus' => $this->availability_status,
            'pharmacistNote' => $this->pharmacist_note,
            'dispenseStatus' => $this->dispense_status,
            'dispensedBy' => $this->whenLoaded('dispensedBy', fn () => ['id' => $this->dispensedBy->id, 'name' => $this->dispensedBy->name]),
            'dispensedAt' => $this->dispensed_at?->toISOString(),
            'givenBy' => $this->whenLoaded('givenBy', fn () => ['id' => $this->givenBy->id, 'name' => $this->givenBy->name]),
            'givenAt' => $this->given_at?->toISOString(),
            'medicine' => $this->whenLoaded('medicine', fn () => [
                'id' => $this->medicine->id,
                'name' => $this->medicine->name,
                ...($isDoctor ? [] : ['stock' => $this->medicine->stock]),
                'strength' => $this->medicine->strength,
                'dosageForm' => $this->medicine->dosage_form,
            ]),
        ];
    }
}

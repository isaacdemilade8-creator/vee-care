<?php

namespace App\Services;

use App\Services\Contracts\AiClinicalAssistant;

class NullAiClinicalAssistant implements AiClinicalAssistant
{
    public function summarizePatient(int $patientId): array
    {
        return ['status' => 'not_configured', 'patientId' => $patientId, 'summary' => null];
    }

    public function suggestDifferentials(array $symptoms): array
    {
        return ['status' => 'not_configured', 'symptoms' => $symptoms, 'suggestions' => []];
    }

    public function draftPrescription(array $clinicalContext): array
    {
        return ['status' => 'not_configured', 'context' => $clinicalContext, 'draft' => null];
    }
}

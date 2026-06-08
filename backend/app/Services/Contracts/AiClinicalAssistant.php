<?php

namespace App\Services\Contracts;

interface AiClinicalAssistant
{
    public function summarizePatient(int $patientId): array;

    public function suggestDifferentials(array $symptoms): array;

    public function draftPrescription(array $clinicalContext): array;
}

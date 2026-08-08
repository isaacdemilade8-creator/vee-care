<?php

namespace App\Enums;

/**
 * Lifecycle states for a hospital application on the control plane.
 *
 * Pending -> UnderReview -> Approved (provisioned into an active tenant)
 *                     \-> Rejected
 */
enum HospitalApplicationStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

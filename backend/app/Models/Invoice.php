<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'patient_id', 'invoice_number', 'status', 'currency', 'subtotal', 'tax', 'total', 'due_at'])]
class Invoice extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}

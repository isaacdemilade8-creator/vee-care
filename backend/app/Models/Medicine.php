<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'name', 'sku', 'category', 'dosage_form', 'strength', 'manufacturer', 'batch_number', 'storage_location', 'stock', 'reorder_level', 'unit_price', 'status', 'expires_at'])]
class Medicine extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(MedicineStockMovement::class);
    }
}

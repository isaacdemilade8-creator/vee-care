<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

trait BelongsToOrganization
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Inside a tenant context every tenant database contains exactly one
     * organization record. Automatically link newly created rows to it so
     * that organization_id remains meaningful within the tenant database.
     */
    protected static function bootBelongsToOrganization(): void
    {
        static::creating(function ($model): void {
            if ($model->organization_id !== null) {
                return;
            }

            if (Schema::hasTable('organizations')) {
                $model->organization_id = Organization::query()->value('id');
            }
        });
    }
}

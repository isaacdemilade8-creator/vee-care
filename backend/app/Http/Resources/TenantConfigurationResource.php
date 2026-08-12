<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\TenantConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Platform view of a tenant's configuration (control database).
 *
 * This is the private, platform-admin view: it includes general settings and
 * per-role availability. It deliberately never includes the public
 * tenant-context projection's database/internal fields, and it never exposes
 * tenant database credentials.
 *
 * @mixin Tenant
 */
class TenantConfigurationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $config = app(TenantConfigurationService::class);

        return [
            'branding' => $config->branding($this->resource),
            'modules' => $config->modules($this->resource),
            'roles' => $config->roles($this->resource),
            'settings' => $config->settings($this->resource),
        ];
    }
}

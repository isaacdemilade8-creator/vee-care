<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'type', 'plan', 'status', 'currency', 'database_name', 'database_host', 'database_port', 'database_username', 'database_password', 'settings'])]
#[Hidden(['database_password'])]
class Tenant extends Model
{
    protected $connection = 'control';

    protected $table = 'tenants';

    protected function casts(): array
    {
        return [
            'database_password' => 'encrypted',
            'settings' => 'array',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function primaryDomain(): ?string
    {
        return $this->domains()->where('is_primary', true)->value('domain');
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active->value;
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended->value;
    }

    public function isRejected(): bool
    {
        return $this->status === TenantStatus::Rejected->value;
    }

    public function isOperational(): bool
    {
        return $this->isActive();
    }

    public static function generateSlug(string $name): string
    {
        $slug = Str::slug($name);

        if (! $slug) {
            $slug = 'tenant';
        }

        $base = $slug;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}

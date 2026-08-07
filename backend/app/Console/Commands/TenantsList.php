<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantsList extends Command
{
    protected $signature = 'tenants:list';

    protected $description = 'List all tenants in the control database.';

    public function handle(): int
    {
        $tenants = Tenant::query()->withCount('domains')->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        $rows = $tenants->map(fn (Tenant $tenant) => [
            $tenant->id,
            $tenant->slug,
            $tenant->name,
            $tenant->database_name,
            $tenant->primaryDomain() ?? '-',
            $tenant->status,
            $tenant->plan,
        ]);

        $this->table(
            ['ID', 'Slug', 'Name', 'Database', 'Domain', 'Status', 'Plan'],
            $rows,
        );

        return self::SUCCESS;
    }
}

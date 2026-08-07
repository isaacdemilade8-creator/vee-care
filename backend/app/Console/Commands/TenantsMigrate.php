<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantDatabaseManager;
use Illuminate\Console\Command;

class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate
        {--tenant= : Only migrate the given tenant (slug or id)}
        {--fresh : Drop all tables and re-run the tenant migrations}';

    protected $description = 'Run the tenant migrations against one or all tenant databases.';

    public function handle(TenantDatabaseManager $databases): int
    {
        $query = Tenant::query()->orderBy('id');

        if ($target = $this->option('tenant')) {
            $query->where(function ($nested) use ($target): void {
                $nested->where('id', $target)->orWhere('slug', $target);
            });
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $this->line("Migrating [{$tenant->slug}] ({$tenant->database_name}) ...");

            try {
                $databases->migrate($tenant, (bool) $this->option('fresh'));
                $this->info("  {$tenant->slug}: migrations applied.");
            } catch (\Throwable $e) {
                $this->error("  {$tenant->slug}: FAILED - {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}

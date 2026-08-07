<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class TenantDatabaseManager
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    protected ?string $previousDefault = null;

    /**
     * Create the physical database for a tenant.
     *
     * With the "sqlite" driver this creates an empty file. With MySQL/MariaDB
     * it issues a CREATE DATABASE statement against the control server.
     */
    public function createDatabase(Tenant $tenant): void
    {
        if ($this->driver() === 'sqlite') {
            $this->createSqliteFile($tenant->database_name);

            return;
        }

        $database = $this->assertSafeDatabaseName($tenant->database_name);

        $this->db->connection('control')->statement(
            sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
                $database,
                config('tenancy.database.charset', 'utf8mb4'),
                config('tenancy.database.collation', 'utf8mb4_unicode_ci'),
            )
        );
    }

    /**
     * Drop the physical database for a tenant.
     */
    public function dropDatabase(Tenant $tenant): void
    {
        if ($this->driver() === 'sqlite') {
            $path = $this->sqlitePath($tenant->database_name);

            if (file_exists($path)) {
                @unlink($path);
            }

            return;
        }

        $database = $this->assertSafeDatabaseName($tenant->database_name);

        $this->db->connection('control')->statement(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
    }

    /**
     * Point the "tenant" connection at this tenant's database and make it the
     * default connection for the remainder of the request/process.
     */
    public function connect(Tenant $tenant): void
    {
        $config = $this->buildConnectionConfig($tenant);

        config(['database.connections.tenant' => $config]);

        $previous = config('database.default');

        if ($this->previousDefault === null) {
            $this->previousDefault = $previous;
        }

        config(['database.default' => 'tenant']);

        $this->db->purge('tenant');
    }

    /**
     * Disconnect from the current tenant database and restore the connection
     * that was active before the tenant connection was configured.
     */
    public function disconnect(): void
    {
        $target = $this->previousDefault ?? 'control';
        $this->previousDefault = null;

        config(['database.default' => $target]);
        $this->db->purge('tenant');
    }

    /**
     * Run the tenant migrations against a tenant database.
     */
    public function migrate(Tenant $tenant, bool $fresh = false): void
    {
        $this->connect($tenant);

        $options = [
            '--database' => 'tenant',
            '--path' => config('tenancy.migrations_path'),
            '--force' => true,
        ];

        Artisan::call($fresh ? 'migrate:fresh' : 'migrate', $options);
    }

    /**
     * Ensure a tenant database schema exists by running pending migrations.
     * Returns the list of migrations that were applied, if any.
     */
    public function migratePending(Tenant $tenant): array
    {
        $this->connect($tenant);

        $migrator = app('migrator');
        $migrator->setConnection('tenant');
        $migrator->run(database_path('migrations/tenant'), ['pretend' => false]);

        return $migrator->getNotes();
    }

    public function driver(): string
    {
        return config('tenancy.database.driver') ?: config('database.default');
    }

    /**
     * Build the connection configuration array for a tenant database.
     */
    protected function buildConnectionConfig(Tenant $tenant): array
    {
        if ($this->driver() === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => $this->sqlitePath($tenant->database_name),
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => null,
                'journal_mode' => null,
                'synchronous' => null,
                'transaction_mode' => 'DEFERRED',
            ];
        }

        return [
            'driver' => $this->driver(),
            'host' => $tenant->database_host ?: config('tenancy.database.host'),
            'port' => $tenant->database_port ?: config('tenancy.database.port'),
            'database' => $this->assertSafeDatabaseName($tenant->database_name),
            'username' => $tenant->database_username ?: config('tenancy.database.username'),
            'password' => $tenant->database_password ?: config('tenancy.database.password'),
            'charset' => config('tenancy.database.charset', 'utf8mb4'),
            'collation' => config('tenancy.database.collation', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ];
    }

    protected function sqlitePath(string $databaseName): string
    {
        $directory = config('tenancy.database.sqlite_path');

        if (! $this->isAbsolutePath($directory)) {
            $directory = base_path($directory);
        }

        return rtrim($directory, '/\\')
            .DIRECTORY_SEPARATOR
            .$this->assertSafeDatabaseName($databaseName)
            .'.sqlite';
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    protected function createSqliteFile(string $databaseName): void
    {
        $path = $this->sqlitePath($databaseName);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (! file_exists($path)) {
            touch($path);
        }
    }

    /**
     * Database names are derived from the tenant slug and are strictly
     * validated to prevent identifier injection in raw DDL.
     */
    protected function assertSafeDatabaseName(string $databaseName): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $databaseName)) {
            throw new RuntimeException("Unsafe database name provided: {$databaseName}");
        }

        return $databaseName;
    }
}

# Multi-Tenancy Architecture

Vee-Care is a multi-tenant healthcare platform using **database-per-tenant**
isolation. Every hospital (tenant) gets its own MySQL/MariaDB database, its own
schema, and its own users. A small **control database** holds the tenant
registry, domain mapping, and platform administrators.

```
┌──────────────────────────────────────────────────────────────┐
│                    CONTROL PLANE (control DB)                 │
│  tenants · tenant_domains · users (platform admins)           │
│                                                              │
│  Host: vee-care.test / api.vee-care.test / admin.vee-care.test│
└──────────────────────────────────────────────────────────────┘
             ▲ resolves tenant by hostname
             │
┌────────────┴──────────────────────────────────────────────────┐
│                      TENANT PLANE                             │
│                                                              │
│  vee_care_tenant_hospital_one  (hospital-one.vee-care.test)   │
│  vee_care_tenant_hospital_two  (hospital-two.vee-care.test)   │
│  ... one database per hospital with full schema + data        │
└──────────────────────────────────────────────────────────────┘
```

## Why database-per-tenant

- Strongest isolation: a hospital's PHI is physically separated from every
  other tenant. A bug in one tenant's queries can never read another tenant's
  data.
- Per-tenant backup/restore, migration windows, storage sizing, and compliance
  (each tenant can be exported/audited independently).
- Existing `organization_id` columns are retained for in-app context
  (Phases 1-5). Every tenant database is seeded with exactly one organization.

## Database connections

Two named connections live in `config/database.php`:

| Connection | Used for                        | Database            |
|------------|---------------------------------|---------------------|
| `control`  | tenant registry + platform auth | `vee_care_control`  |
| `tenant`   | the resolved tenant's database  | dynamically swapped |

- The default connection is the **control** connection. Tenant code never runs
  against the control database by accident.
- `config/tenancy.php` configures the platform domain, tenant DB driver,
  credentials, prefix and migration path.

```env
DB_DATABASE=vee_care_control
TENANT_PLATFORM_DOMAIN=vee-care.test
TENANT_PLATFORM_SUBDOMAINS=api,admin,www
TENANT_DB_PREFIX=vee_care_tenant_
# Blank TENANT_DB_DRIVER falls back to DB_CONNECTION (mysql).
# Set to "sqlite" for local dev/tests: databases become files in TENANT_DB_PATH.
TENANT_DB_DRIVER=
TENANT_DB_PATH=database/tenants
```

## Hostname resolution

`App\Http\Middleware\ResolveTenant` runs first in the `api` middleware group.

1. If the host is the platform domain or one of `platform_subdomains`
   (`api`, `admin`, `www`), the request enters **platform context** and the
   default connection stays on the control database.
2. Otherwise the host is looked up in `tenant_domains` (covers custom
   domains), then matched as `<slug>.<platform-domain>` against `tenants.slug`.
3. Unknown hosts abort with `404`. Inactive tenants abort with `403`.
4. On success `TenantResolver::setCurrent()` reconfigures the `tenant`
   connection and makes it the default. Cached auth guards are forgotten so a
   user from one tenant is never reused in another.

```php
// ResolveTenant -> TenantResolver::resolve($host)
$domain = TenantDomain::with('tenant')->where('domain', $host)->first();
// fallback: Tenant::where('slug', $subdomain)
```

## Provisioning

`App\Services\TenantProvisioner::provision()` runs the full lifecycle:

1. Create the control-plane `tenants` record (status `provisioning`).
2. `TenantDatabaseManager::createDatabase()` — `CREATE DATABASE` on MySQL, or an
   empty file on sqlite.
3. `TenantDatabaseManager::migrate()` — run `database/migrations/tenant/*`
   against the tenant database.
4. Seed one organization, one main branch, and (optionally) the hospital admin.
5. Register the primary subdomain in `tenant_domains`.
6. Mark the tenant `active`. On failure the tenant is marked `failed`.

The connection is always restored to the control plane afterward (`finally`).

```bash
php artisan tenants:provision "City General Hospital" --email admin@citygeneral.vee-care.test
php artisan tenants:migrate --tenant=city-general        # run pending migrations
php artisan tenants:list
```

## Platform (control plane) API

Served on the platform domain, authenticated with the `platform` guard against
`App\Models\PlatformUser` (table `users` in the control database).

| Route                                  | Purpose                     |
|----------------------------------------|-----------------------------|
| `POST /api/platform/auth/login`        | Platform admin login        |
| `GET /api/platform/me`                 | Current platform user       |
| `POST /api/platform/auth/logout`       | Logout                      |
| `GET/POST /api/platform/tenants`       | List / create tenants       |
| `GET/PATCH /api/platform/tenants/{t}`  | Show / update a tenant      |
| `POST /api/platform/tenants/{t}/domains` | Add a custom domain       |
| `DELETE /api/platform/tenants/{t}/domains/{d}` | Remove a domain    |
| `POST /api/platform/tenants/{t}/migrate` | Run pending migrations    |

Tenant routes (`/api/auth/*`, appointments, pharmacy, enterprise, etc.) are
unchanged and now run inside the resolved tenant database.

## Models & services

- `App\Models\Tenant` — control connection, `database_password` encrypted at
  rest and hidden from API responses.
- `App\Models\TenantDomain` — control connection, maps hostnames to tenants.
- `App\Models\PlatformUser` — control connection, `users` table, platform admins.
- `App\Models\User` — default connection (the active tenant), hospital users.
- `App\Services\TenantDatabaseManager` — create/drop/connect/disconnect/migrate.
- `App\Services\TenantResolver` — hostname → tenant, context switching.
- `App\Services\TenantProvisioner` — orchestrated provisioning + seeding.

## Migrations layout

- `database/migrations/` (root) — **control plane only**: `tenants`,
  `tenant_domains`, plus base framework tables (`users`, `cache`, `jobs`,
  `personal_access_tokens`) used by the control database.
- `database/migrations/tenant/` — the full healthcare schema. These are applied
  to every tenant database and tracked in each tenant's own `migrations` table.
  MySQL-specific statements are guarded with `DB::getDriverName() === 'mysql'`.

## Isolation tests

`tests/Feature/TenantIsolationTest.php` (runs with `TENANT_DB_DRIVER=sqlite`,
tenant databases as files under `backend/database/tenants/`) verifies:

- hostname resolution and platform-subdomain detection
- per-tenant database/table/schema creation
- cross-tenant data inaccessibility (including duplicate emails per tenant)
- unknown host → 404
- authentication isolation (login + Sanctum tokens never cross tenant DBs)
- provisioning seeds org + branch + admin inside the tenant database
- credentials are encrypted at rest in the control database

```bash
vendor/bin/phpunit --filter TenantIsolationTest
```

## Security notes

- Tenant database names are derived from the slug and validated against
  `^[a-z0-9_]+$` before use in any raw DDL (identifier injection).
- Tenant DB credentials are stored encrypted (`database_password` cast) and
  never exposed by the API.
- The control database never contains tenant health data; the platform and
  tenant planes have separate auth guards and providers.
- Auth guards are forgotten whenever the tenant/platform context switches so a
  resolved user cannot leak across tenants in long-running processes.

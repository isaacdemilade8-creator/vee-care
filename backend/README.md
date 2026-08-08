# Vee-Care Backend

Laravel API for Vee-Care, a multi-tenant healthcare platform using
**database-per-tenant** isolation. Every hospital (tenant) gets its own
database; a small **control database** holds the tenant registry, domain
mapping, and platform administrators.

> See [`MULTI_TENANCY_ARCHITECTURE.md`](../MULTI_TENANCY_ARCHITECTURE.md) for
> the full architecture, provisioning workflow, and isolation guarantees.

## Stack

- Laravel 13, PHP 8.5+, MySQL/MariaDB (tenant DBs), Laravel Sanctum for auth.

## Connections & data planes

| Connection | Used for                          | Database           |
|------------|-----------------------------------|--------------------|
| `control`  | tenant registry + platform auth   | `vee_care_control` |
| `tenant`   | the resolved tenant's database    | dynamically swapped |

- The default connection is `control`. `App\Http\Middleware\ResolveTenant`
  resolves the hostname: platform domains stay on `control`; hospital hosts
  (`<slug>.<platform-domain>` or a custom domain) swap the `tenant` connection
  and make it the default, so tenant code never runs against `control`.
- Control-plane users use `App\Models\PlatformUser` (fixed `control`
  connection, `users` table). Hospital users use `App\Models\User` on the
  resolved tenant connection.

## Environment

```env
DB_DATABASE=vee_care_control
TENANT_PLATFORM_DOMAIN=vee-care.test
TENANT_PLATFORM_SUBDOMAINS=api,admin,www
TENANT_DB_PREFIX=vee_care_tenant_
# Blank TENANT_DB_DRIVER falls back to DB_CONNECTION (mysql).
# Set to "sqlite" for local dev/tests: databases become files in TENANT_DB_PATH.
TENANT_DB_DRIVER=
TENANT_DB_PATH=database/tenants
# Hospital-admin invitation lifetime in days (default 7).
TENANT_INVITATION_EXPIRY_DAYS=7
```

Invitation emails use standard Laravel mail (`MAIL_MAILER`, `MAIL_FROM_ADDRESS`,
etc.) and are queued on `QUEUE_CONNECTION`. Sending is best-effort — a mail
failure is logged and never fails provisioning. In dev, `MAIL_MAILER=log` writes
the invitation to the log file.

Tests force `TENANT_DB_DRIVER=sqlite` with tenant databases as files under
`backend/database/tenants/` and an in-memory control database.

## Setup & local development

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --path=database/migrations        # control plane
php artisan tenants:provision "City General Hospital" \
  --email admin@citygeneral.vee-care.test
php artisan serve
```

The tenant schema lives in `database/migrations/tenant/*` and is applied to
each tenant database during provisioning (`php artisan tenants:migrate
--tenant=slug`).

## Auth

- **Tenants:** `POST /api/auth/register` (patients), `POST /api/auth/login`,
  `GET /api/auth/me`. Tokens are issued by the resolved tenant's Sanctum table;
  they never authenticate on another tenant or on the platform host.
- **Platform:** `POST /api/platform/auth/login`, `GET /api/platform/me`
  against `PlatformUser` on the `control` database.

Roles are split by plane (`App\Enums\Role` / `App\Enums\PlatformRole`):

- **Tenant roles** (`App\Enums\Role`): `hospital_admin`, `doctor`, `nurse`,
  `patient`, `lab_technician`, `pharmacist`. Tenant DBs top out at
  `hospital_admin`; legacy `super_admin`/`admin` values are consolidated to
  `hospital_admin` by migration. Tenant routes gate with `role:hospital_admin`.
- **Platform roles** (`App\Enums\PlatformRole`): `platform_super_admin`,
  `platform_admin` — only on `PlatformUser` in the control DB. Platform routes
  gate with `role:platform_super_admin,platform_admin` behind `auth:platform`.
- Platform role values are not members of the tenant enum, so tenant
  validation and middleware reject them (a hospital admin can never mint or
  escalate to a platform role).

## Hospital onboarding

- `POST /api/platform/hospital-applications` (public) creates a `pending`
  application: hospital/contact details + subdomain only. It never accepts
  roles, passwords or DB credentials and never provisions a tenant. It is
  rate-limited to **5/min per client** (`throttle:hospital-applications`).
- Platform admins `PATCH /{application}` (under review), then
  `POST /{application}/approve` provisions the tenant via
  `TenantProvisioner` (active, seeded with one `hospital_admin`) and issues a
  single-use invitation to the applicant — **no password is generated or
  returned** — or `POST /{application}/reject` (no tenant, slug freed).
- `App\Rules\AvailableHospitalSubdomain` enforces DNS-label format, rejects
  reserved platform subdomains and slugs already claimed by a tenant or a
  non-rejected application.
- Tenant status is `App\Enums\TenantStatus`; non-active (suspended/rejected)
  tenants are blocked at the tenant boundary with 403.
- Approval failures never leave a half-created tenant: the tenant record and
  database are rolled back and the application stays `pending`.

### Hospital-admin invitation

`App\Models\HospitalAdminInvitation` is single-use and expires after
`TENANT_INVITATION_EXPIRY_DAYS` (default 7):

- `POST /api/platform/hospital-applications/invitations/{token}/accept`
  (public, rate-limited to 10/min per client) sets the hospital admin's
  password inside the resolved tenant database.
- Only the token's SHA-256 digest is stored; the raw token is returned to the
  approver once and emailed to the applicant
  (`App\Mail\HospitalAdminInvitation`). It is never logged, and passwords never
  appear in emails, URLs, logs or API responses.
- Expired, used or unknown tokens are rejected with a generic 422; tokens are
  scoped to the application/tenant they were issued for.

### Platform audit events

`App\Models\PlatformAuditLog` records `hospital_application.submitted /
reviewed / approved / rejected / invitation_accepted` on the control database
with the platform actor, application/tenant ids, IP, user agent, and metadata.
Passwords and invitation tokens are never recorded.

## Seeding

`database/seeders/TenantSeeder.php` runs inside each tenant DB during
provisioning: one organization, one main branch, and an optional admin.
`organization_id`/`branch_id` columns remain as compatibility context,
auto-filled to the tenant's organization; the resolved tenant database is the
authoritative tenant boundary, never a client-supplied id.

## Tests

```bash
vendor/bin/phpunit
```

- `tests/Feature/TenantIsolationTest.php` — tenancy infrastructure: resolution,
  schema creation, token isolation, provisioning/seeding.
- `tests/Feature/CoreDomainIsolationTest.php` — core-domain boundary: patients,
  practitioners, appointments are tenant-local; cross-tenant references and
  payload tampering are rejected; platform vs tenant credentials are isolated.
- `tests/Feature/HospitalOnboardingTest.php` — onboarding lifecycle:
  application -> review -> approve/provision or reject, subdomain validation,
  platform-role gating (including `hospital_admin` denial), audit events,
  rate limiting, and failure rollback.
- `tests/Feature/HospitalAdminInvitationTest.php` — invitation lifecycle:
  valid/used/expired/unknown tokens, tenant scoping, password set + login, and
  invitation email dispatch.

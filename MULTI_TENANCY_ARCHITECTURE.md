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
# Hospital-admin invitation lifetime in days (default 7).
TENANT_INVITATION_EXPIRY_DAYS=7
```

### Invitation delivery (mail)

Invitation emails use the standard Laravel mail configuration and are queued on
the configured queue (`QUEUE_CONNECTION`; a worker must be running in
production, or set `QUEUE_CONNECTION=sync` in dev):

```env
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="onboarding@vee-care.test"
MAIL_FROM_NAME="Vee-Care"
```

Sending is **best-effort**: if the mailer fails, approval still completes and
the failure is logged. The raw invitation token is also returned to the
approving platform administrator once, so onboarding is never blocked on a mail
outage.

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

## Local tenant-host development setup

Tenant hosts (`hospital-one.vee-care.test`, `hospital-two.vee-care.test`) and
platform subdomains (`api.vee-care.test`, `admin.vee-care.test`) are not real
DNS names, so they must be mapped to the loopback address on the machine that
runs the local servers.

Windows developers add entries to
`C:\Windows\System32\drivers\etc\hosts` (editing the file as Administrator):

```text
127.0.0.1 hospital-one.vee-care.test
127.0.0.1 hospital-two.vee-care.test
127.0.0.1 admin.vee-care.test
127.0.0.1 api.vee-care.test
```

Only add the entries required by what you are testing. The Windows hosts file
does **not** support wildcards — there is no catch-all `*.vee-care.test` line;
each tenant subdomain you want to reach needs its own `127.0.0.1` entry.

The Vite dev server (`frontend/vite.config.ts` → `server.allowedHosts`) accepts
the local host plus any `vee-care.test` subdomain, so the frontend can be opened
at e.g. `http://hospital-one.vee-care.test:5173`.

### Development-only `?host=` override

In local development the SPA on a tenant host reaches the API at the loopback
address (`http://127.0.0.1:8000/api`), where the backend cannot see the tenant
hostname. `GET /api/tenant-context` therefore accepts an optional `?host=`
query parameter naming the tenant host being viewed. It is honored only outside
production and returns the matching tenant's public context
(`TenantContextController`). In production the parameter is ignored and tenant
identity is always derived from the actual request hostname.

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
| `POST /api/platform/hospital-applications` | Public hospital application |
| `GET /api/platform/hospital-applications` | List applications        |
| `PATCH /api/platform/hospital-applications/{a}` | Mark under review     |
| `POST /api/platform/hospital-applications/{a}/approve` | Provision tenant |
| `POST /api/platform/hospital-applications/{a}/reject` | Reject application |
| `GET/POST /api/platform/tenants`       | List / create tenants       |
| `GET/PATCH /api/platform/tenants/{t}`  | Show / update a tenant      |
| `POST /api/platform/tenants/{t}/domains` | Add a custom domain       |
| `DELETE /api/platform/tenants/{t}/domains/{d}` | Remove a domain    |
| `POST /api/platform/tenants/{t}/migrate` | Run pending migrations    |
| `GET/PATCH /api/platform/tenants/{t}/configuration` | Platform view / update of a tenant's configuration |

Tenant routes (`/api/auth/*`, appointments, pharmacy, enterprise, etc.) are
unchanged and now run inside the resolved tenant database.

### Hospital self-service configuration API

Served on the tenant host, authenticated with the Sanctum `hospital_admin`
token for the **active tenant only** (resolved from the request Host via
`TenantResolver`, never from a client-supplied id).

| Route                    | Purpose                                              |
|--------------------------|------------------------------------------------------|
| `GET /api/configuration` | Read the hospital's branding/modules/roles/settings  |
| `PATCH /api/configuration` | Apply partial updates to any of those sections    |

The response shape is `{ name, branding, modules, roles, settings }` — the
same `TenantConfigurationResource` shape the platform uses, plus the hospital
name. It never includes database fields or credentials.

`PATCH` accepts `name` plus any subset of `branding`, `modules`, `roles`,
`settings`. Validation and registries come from the shared
`TenantConfigurationService` (`config/tenant-defaults.php`), so both surfaces
enforce identical allowlists: unknown setting keys are rejected, required
modules/roles cannot be disabled, and platform roles are never valid tenant
roles. A `null` branding value resets a field to "inherit the Vee-Care
default". Only values that actually change are persisted, and successful
changes emit a `tenant.configuration.updated` audit event with
`platform_user_id = null` and an `actor` metadata block describing the
hospital admin.

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

## Core-domain migration (Milestone 2)

The healthcare domain — users, patient profiles, practitioners/doctors/staff,
appointments — already lived in tenant databases once Milestone 1 made the
resolved tenant connection the default. Milestone 2 hardens that boundary and
adds RBAC and seeding:

- **RBAC** is string roles on `users.role`, sourced from `App\Enums\Role`
  (single source of truth). Tenant roles: `hospital_admin`, `doctor`, `nurse`,
  `patient`, `lab_technician`, `pharmacist`. `Role::assignableByAdmin()` limits
  which roles a hospital admin may create; `Role::staff()` covers clinical
  staff for analytics.
- **Tenant seeding** (`database/seeders/TenantSeeder.php`) is invoked by
  `TenantProvisioner::provision()` and seeds one organization, one main branch,
  and an optional admin inside each tenant database.
- **`organization_id` is retained** as a nullable compatibility column,
  auto-filled to the tenant's single seeded organization
  (`App\Models\Concerns\BelongsToOrganization`). It is a data-consistency
  convenience only: the resolved tenant database is the authoritative
  boundary. Client-supplied `organization_id`/`branch_id` in registration and
  appointment payloads are ignored (validated fields only, no `forceFill`).
- **`UserResource` is tenant/control aware.** `canReview`/`isFollowing` (which
  query tenant-only tables such as `user_follows`) are skipped for
  control-plane `PlatformUser` resources so platform responses never touch
  tenant tables.

## Platform administration & hospital onboarding (Milestones 2.5–2.6)

Milestone 2.5 completes the split between platform and tenant administration
and adds the hospital application → approval → provisioning workflow. Milestone
2.6 hardens that flow for production: secure hospital-admin invitations replace
returned passwords, public registration is rate-limited, onboarding is audited,
and the frontend role model matches the platform/tenant split.

### Role split

| Plane | Connection | Roles | Enum |
|-------|------------|-------|------|
| Platform (control plane) | `control` | `platform_super_admin`, `platform_admin` | `App\Enums\PlatformRole` |
| Tenant (hospital plane) | resolved tenant DB | `hospital_admin`, `doctor`, `nurse`, `patient`, `lab_technician`, `pharmacist` | `App\Enums\Role` |

- Tenant databases **top out at `hospital_admin`**. The legacy `super_admin` /
  `admin` tenant values are consolidated to `hospital_admin` by the
  `introduce_hospital_admin_role` tenant migration. Platform role values
  (`platform_super_admin` / `platform_admin`) are never valid inside a tenant
  database: they are not members of `App\Enums\Role`, so tenant `admin`
  validation and role middleware reject them (422 on create, 422 on update,
  403 on route access).
- Platform roles exist only on control-plane users (`PlatformUser`). The
  `update_platform_user_roles` migration widens the control `users.role` column
  to `VARCHAR(50)` and rewrites `super_admin` → `platform_super_admin`,
  `admin` → `platform_admin`.
- Tenant routes use `role:hospital_admin`; platform routes use
  `role:platform_super_admin,platform_admin` behind the `auth:platform` guard.
  The two role spaces never collide because each user's role string is only
  ever evaluated against the plane it belongs to.

### Application lifecycle

```
  public POST /api/platform/hospital-applications        (pending)
         ├─ platform review   PATCH /{application}       (under_review)
         ├─ platform approve  POST /{application}/approve -> provisions tenant
         │                       ├─ hospital admin account created (no password)
         │                       └─ single-use invitation emailed to applicant
         └─ platform reject   POST /{application}/reject  -> no tenant created
```

- **Public submission** (`HospitalApplicationController@store`) is unauthenticated
  and only accepts hospital/contact details and a subdomain. It never accepts
  roles, passwords or database credentials, and it never creates a tenant.
- **Subdomain validation** (`App\Rules\AvailableHospitalSubdomain`) enforces a
  DNS-label format, rejects reserved platform subdomains (`api`, `admin`,
  `www`), and rejects slugs already claimed by a tenant or a non-rejected
  application. Rejecting an application retires its slug
  (`<slug>-rejected-<id>`) so the subdomain becomes available again.
- **Approval** (`HospitalApplicationController@approve`) provisions the tenant
  through the existing `App\Services\TenantProvisioner`, links it via
  `hospital_applications.tenant_id`, and issues a single-use invitation to the
  applicant's contact email. **No password is ever generated or returned.**
  The tenant is provisioned with a throwaway (never-revealed) password and the
  hospital administrator sets their own password by redeeming the invitation.
- **Tenant status** is an enum (`App\Enums\TenantStatus`):
  `pending`, `provisioning`, `active`, `suspended`, `rejected`, `failed`.
  `ResolveTenant` aborts with `403` for any non-active tenant, so suspended or
  rejected tenants are blocked at the tenant boundary.

### Secure hospital-admin invitation

`App\Models\HospitalAdminInvitation` is a single-use, expiring invitation
issued on the control plane when an application is approved:

- `POST /api/platform/hospital-applications/invitations/{token}/accept` is
  **public** (no platform login) and rate-limited. The applicant submits a new
  password (8+ characters, confirmed); the hospital admin's password is set
  inside the resolved tenant database and the invitation is marked used.
- The token is 64 random bytes (`Str::random(64)`); only its SHA-256 digest is
  stored (`hospital_admin_invitations.token_hash`). A database leak never
  exposes a usable token, and the raw token is never logged.
- The token is returned to the approver **once** in the approval response and
  emailed to the applicant's contact address via
  `App\Mail\HospitalAdminInvitation` (best-effort: a mail failure is logged and
  never fails provisioning). No password ever appears in an email, a URL, a log
  line, or an API response.
- **Expiry** defaults to 7 days (`TENANT_INVITATION_EXPIRY_DAYS`). Expired,
  used, or unknown tokens are rejected with a generic 422 — implementation
  details are never exposed.
- Invitations are scoped to their `application_id` / `tenant_id`: redemption
  always writes to the tenant the invitation was issued for, so a token can
  never activate an account in another hospital.

### Public registration rate limiting

Dedicated per-client limiters live in `App\Providers\AppServiceProvider` and are
applied as named middleware in `routes/api.php` — the platform-admin throttle is
never reused:

| Route | Limiter | Limit |
|-------|---------|-------|
| `POST /api/platform/hospital-applications` | `hospital-applications` | 5/min per IP |
| `POST .../invitations/{token}/accept` | `invitation-accept` | 10/min per IP |
| `POST /api/auth/register` (tenant) | `auth-register` | 10/min per IP |

Duplicate submissions are also blocked at the validation layer (a slug already
claimed by a pending/approved application or a tenant is rejected), so the rate
limit is defence-in-depth, not the only protection.

### Platform audit events

`App\Models\PlatformAuditLog` records control-plane onboarding events. Writes go
to the control database only and never contain passwords, invitation tokens or
database credentials:

| Event | Actor |
|-------|-------|
| `hospital_application.submitted` | none (public) |
| `hospital_application.reviewed` | platform user |
| `hospital_application.approved` | platform user (also records `tenant_id`) |
| `hospital_application.rejected` | platform user |
| `hospital_application.invitation_accepted` | none (public) |

Each record captures the event type, the platform actor (`platform_user_id`),
the affected application/tenant ids, an IP address, a user agent and a timestamp,
plus any relevant metadata (slug, email, rejection reason).

### Testing onboarding locally

Run the control-plane migrations, then exercise the lifecycle end-to-end:

```bash
php artisan migrate                                   # control plane (MySQL)
php artisan optimize:clear

# 1. Submit a public application
curl -X POST http://vee-care.test/api/platform/hospital-applications \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"hospital_name":"Mercy Hospital","slug":"mercy-hospital",
       "contact_name":"Jane Doe","contact_email":"jane@example.com"}'

# 2. Log in as a platform administrator and approve it
curl -X POST http://vee-care.test/api/platform/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"platform@vee-care.test","password":"..."}'
# -> use the returned token to POST .../hospital-applications/1/approve

# 3. The response (and the queued invitation email) contain the accept URL
curl -X POST 'http://vee-care.test/api/platform/hospital-applications/invitations/<TOKEN>/accept' \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"password":"a-strong-password","password_confirmation":"a-strong-password"}'

# 4. The hospital admin can now log in on the tenant host
curl -X POST http://mercy-hospital.vee-care.test/api/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"jane@example.com","password":"a-strong-password"}'
```

With `MAIL_MAILER=log` the invitation is written to the log file; with a real
SMTP mailer it is queued to the applicant's inbox. Every step is recorded in
`platform_audit_logs`.

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

`tests/Feature/CoreDomainIsolationTest.php` covers the core-domain boundary:

- patients exist only in the tenant DB they were registered on; the same email
  may exist independently in multiple tenants
- an id that exists in only one tenant's DB is 404 from every other tenant;
  colliding auto-increment ids resolve to the tenant-local record
- practitioners (doctors) are tenant-local; `/api/doctors` never lists another
  tenant's staff
- appointments are tenant-local; an appointment may never reference a doctor
  from another tenant (validated with `exists:users,id` → 422, no row created)
- client-supplied `organization_id`/`branch_id` cannot switch tenant context
- hospital admins cannot escalate to `super_admin`/`admin` — those values are
  not tenant roles at all, so tenant validation rejects them
- platform (`PlatformUser`) and tenant credentials are fully isolated across
  the control/tenant boundary
- cached auth guards do not leak a user across tenant contexts

`tests/Feature/HospitalOnboardingTest.php` covers the onboarding lifecycle:

- public submissions create a `pending` application and never a tenant; roles,
  passwords and database credentials in the payload are ignored
- duplicate, reserved (`api`/`admin`/`www`) and malformed subdomains are
  rejected; case is normalized to lowercase
- rejection retires the slug (available again) and creates no tenant
- review marks an application `under_review`
- approval provisions an active tenant, seeds exactly one `hospital_admin`
  (the applicant's contact email), returns a single-use invitation **instead of
  a password**, and leaves zero `super_admin`/`admin`/platform roles in the
  tenant DB
- a failed provisioning never leaves a half-created tenant: the application
  stays `pending`, the tenant record is rolled back, and no invitation exists
- terminal applications cannot be approved/reviewed/rejected twice
- `platform_admin` and `platform_super_admin` can manage applications; a
  control user without a platform role — including a `hospital_admin` — gets
  403; tenant tokens never authenticate on the platform host (401)
- suspended tenants return 403 at the tenant boundary
- the whole lifecycle (submitted → approved → invitation accepted → rejected)
  is recorded in `platform_audit_logs`
- public onboarding endpoints are rate-limited (429 past the per-client limit)

`tests/Feature/HospitalAdminInvitationTest.php` covers the invitation lifecycle:

- a valid invitation sets the hospital admin's password, marks the invitation
  used, and lets the admin log in on the tenant host
- used, expired and unknown tokens are rejected; a token can never be redeemed
  twice
- invitations are tenant-scoped: redeeming Hospital One's token never touches
  Hospital Two's administrator
- approval dispatches the invitation email to the applicant's contact address
- the response never contains a raw token hash, password, or plaintext
  credentials

```bash
vendor/bin/phpunit --filter TenantIsolationTest
vendor/bin/phpunit --filter CoreDomainIsolationTest
vendor/bin/phpunit --filter HospitalOnboardingTest
vendor/bin/phpunit --filter HospitalAdminInvitationTest
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
- Hospital-admin invitation tokens are single-use, expiring, stored only as a
  SHA-256 digest, and never logged or exposed through email bodies (only their
  acceptance URL is transmitted). Passwords are never generated by the platform,
  never returned by the API, and never written to logs.
- Public onboarding and invitation endpoints carry dedicated per-client rate
  limits as defence-in-depth on top of validation-level duplicate protection.
- Platform audit events never include passwords, invitation tokens or database
  credentials; each approved application records which platform user approved it.

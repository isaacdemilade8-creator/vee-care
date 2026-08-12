# Vee-Care Tenant Configuration Audit

- **Date:** 2026-08-10
- **Scope:** The full tenant configuration system — branding, fonts, modules, roles, general settings, authorization, tenant isolation, cache/state, provisioning defaults, platform (control plane) surface, audit logging, security and test coverage.
- **Method:** Static review of `backend/` (Laravel) and `frontend/` (React) plus the existing test suite (`backend/tests/`). Test tenants used: `hospital-one` / `hospital-two`.
- **Status:** Report only. No code was changed for this audit; fixes are proposed at the end and are pending decisions.

---

## 1. Executive Summary

The tenant configuration system is well structured and genuinely multi-layered:

- A control plane (`control` database, host = platform domain) owns `tenants`, `tenant_domains`, `tenant_branding`, `tenant_modules`, `tenant_role_configuration`, `hospital_applications`, `hospital_admin_invitations` and `platform_audit_logs`.
- `config/tenant-defaults.php` is a single source of truth (fonts, default branding, settings allowlist, module registry, role registry) consumed by `TenantConfigurationService`, resources, seeders and the provisioner.
- There are **two write surfaces** sharing one code path (`TenantConfigurationService::rules/assertKnownSettings/assertValidTenantRoles/applyChanges`): the platform admin surface (`PATCH /api/platform/tenants/{id}/configuration`) and the hospital-admin self-service surface (`PATCH /api/configuration`). Both are audited on the control plane.
- **Module gating works end-to-end:** the backend `module:` middleware blocks disabled optional-module routes with 403 (per tenant), and the frontend hides all module surfaces/hooks behind `useEnabledModules()`. Required modules can never be hidden (tested even against stale rows).
- **Branding and fonts are safely applied:** backend allowlists (SafeColor, SafeAssetUrl, font list), frontend re-sanitization, CSS-var injection, Google Fonts loading with a strict map, favicon/title/theme-color.

**The most significant gaps** (details in §15):

1. **CRITICAL/HIGH — Disabling a tenant role is cosmetic only.** `tenant_role_configuration` is written and read back, but nothing enforces it: a disabled `pharmacist`/`lab_technician` can still log in, access role-gated routes, and be created by the admin. The Roles settings page advertises "Control which roles can sign in at your hospital" — that control does not exist.
2. **HIGH — `AdminController::updateUser` allows admin privilege escalation.** `storeUser` restricts roles to `Role::assignableByAdmin()` (excludes `hospital_admin`), but `updateUser` accepts `Rule::in(Role::values())` which **includes** `hospital_admin` — an admin can promote any user to hospital_admin (and can also demote their own account, a self-lockout footgun).
3. **MEDIUM — The `enterprise` module has no backend gate.** Disabling "Enterprise Analytics" leaves every `/api/enterprise/*` route reachable (only the pharmacy/laboratory/urgent-care sub-routes are gated by their own modules). The `nurse_station` module also has no backend effect (role-based only).
4. **MEDIUM — `PATCH /api/platform/tenants/{id}` bypasses the settings allowlist.** It accepts an arbitrary `settings` array and writes it straight to `tenants.settings`, unlike the two configuration surfaces which enforce `assertKnownSettings`.
5. **MEDIUM — Tenant lifecycle events are not audited.** Provisioning/updating tenants, adding/removing domains and migrations (`Platform\TenantController`) write no `platform_audit_logs`; platform admin login/logout are not audited either.
6. **MEDIUM — No throttling on `/api/auth/login` and `/api/platform/auth/login`** (only register/hospital-applications are throttled).

---

## 2. Configuration Inventory

### 2.1 Backend (control plane)

| Concern | Storage | Write surface | Read surface |
|---|---|---|---|
| Identity | `tenants` (name, slug, type, plan, status, currency, database fields, `settings` JSON) | `Platform\TenantController` | `TenantResource` |
| Branding | `tenant_branding` (one row; null = inherit Vee-Care) | config surfaces (`updateBranding`) | `TenantConfigurationResource` / `TenantContextResource` |
| Modules | `tenant_modules` (one row per registered module) | config surfaces (`updateModules`) | `TenantConfigurationResource` / `TenantContextResource` |
| Roles | `tenant_role_configuration` (one row per tenant role) | config surfaces (`updateRoles`) | `TenantConfigurationResource` |
| General settings | `tenants.settings` JSON (strict allowlist) | config surfaces (`updateSettings`) | `TenantConfigurationResource` |
| Domains | `tenant_domains` | `Platform\TenantController::addDomain/removeDomain`, provisioner | `TenantResource` |
| Onboarding | `hospital_applications`, `hospital_admin_invitations` | `HospitalApplicationController` | `HospitalApplicationResource` |
| Audit | `platform_audit_logs` | config surfaces + onboarding | `PlatformAuditLogController` (platform admins only) |

### 2.2 Registries (`backend/config/tenant-defaults.php`)

- **Fonts:** `Inter`, `Roboto`, `Open Sans`, `Poppins`, `Montserrat`, `system`.
- **Settings:** `locale` (en), `timezone` (UTC + 5), `date_format` (4), `time_format` (2), `default_appointment_duration` (5–240, default 30).
- **Modules (12):** required = `appointments`, `ehr`, `messaging`, `patient_portal`; optional = `prescriptions`, `laboratory`, `pharmacy`, `telemedicine`, `urgent_care`, `nurse_station`, `blog`, `enterprise`. All `default_enabled = true`.
- **Roles (6):** required = `hospital_admin`, `doctor`, `nurse`, `patient`; optional = `pharmacist`, `lab_technician`.

### 2.3 Frontend

- `src/context/TenantContext.tsx` — bootstrap context (host classification + `GET /tenant-context`), in-memory only (no localStorage).
- `src/lib/tenant/{hostname,types,modules,branding,configuration}.ts` — host classification, types, module hooks, branding application, config patch/diff.
- `src/layouts/HospitalSettingsLayout.tsx` + `src/pages/admin/settings/*` — Branding, Modules, Roles, General, Hospital info.
- `src/components/{ModuleRoute,TenantRoute,PlatformRoute,ProtectedRoute}.tsx` — route guards.
- `src/layouts/DashboardLayout.tsx`, `src/pages/*` — module-gated nav + surfaces.

### 2.4 Tests (`backend/tests`)

- Infra: `Tests\Concerns\CreatesTenantDatabase` (SQLite control on `:memory:`, real `.sqlite` tenant files under `database/tenants/`, `provisionTenant`, `connectToTenant`/`disconnectFromTenant` via `TenantResolver::setCurrent`).
- `TenantConfigurationTest`, `HospitalConfigurationTest` — both config surfaces (defaults, updates, validation, required modules/roles, unknown keys, platform-role rejection, audit, no-leak).
- `TenantContextTest`, `TenantModuleGateTest` — public projection, per-tenant 403 gating, required-module never hidden.
- `TenantIsolationTest`, `CoreDomainIsolationTest` — per-tenant DBs, token/guard isolation, no cross-tenant data, no role escalation on create, encrypted credentials at rest.
- `HospitalOnboardingTest`, `HospitalAdminInvitationTest`, `PlatformAdminDashboardTest` — onboarding lifecycle, invitation, platform dashboard.

---

## 3. Branding Findings

**Verified good:**
- Single `tenant_branding` row; `null` = inherit Vee-Care defaults (`tenant-defaults.php` `branding`). Legacy `settings.branding` JSON is backfilled into the dedicated table on first read (`TenantConfigurationService::brandingRow`, line 384).
- `branding()` returns the row (never defaults), so the private resource shows the hospital's own values while the public UI inherits defaults.
- Backend validation: `SafeColor` (hex/functional), `SafeAssetUrl` (https / relative / loopback-http only), `font_family` restricted to the allowlist.
- Frontend re-sanitizes every value (`sanitizeColor`, `sanitizeFontFamily`, `sanitizeAssetUrl`) before injecting via `style.setProperty` — nothing from the API reaches CSS/DOM unguarded (`branding.ts`).
- `applyTenantBranding` sets `--app-accent`, `--app-accent-soft` (derived via `color-mix`), `--app-secondary`, `--app-font-family`, favicon, `theme-color`, `document.title`. Signature-compare (`TenantContext.tsx:84`) re-applies when identity/branding changes.
- A hospital resetting a field to `''` clears it back to "inherit" (`updateBranding` line 129) — tested.

**Findings:**
- **[INFORMATIONAL] `--app-accent-soft` relies on `color-mix`** (`branding.ts:197`) which is broadly used in `globals.scss` already; not a tenant-specific regression.
- **[LOW] Stale relation cache guard is correct but relies on a comment** (`brandingRow` lines 371-373). Behavior verified by reading code only; consider a test that calls `show()` twice to prove no duplicate row (the existing suite provisions once per tenant).

---

## 4. Font Findings

**Verified good:**
- Single allowlist in `tenant-defaults.php` `fonts`; enforced server-side in `rules()` (`Rule::in`).
- Frontend `WEB_FONT_FAMILIES` maps exactly the 5 web families; `system` maps to the UI stack (`SYSTEM_FONT_STACK`).
- The effective family is applied to `--app-font-family` used by `body` (`globals.scss:22`); the matching Google Fonts stylesheet is loaded/removed idempotently.

**Findings:**
- **[LOW] `system` still triggers an Inter download.** In `loadWebFont`, `resolved = family === 'system' ? DEFAULT_FONT_FAMILY : family` — the branch exists only to keep the CSS fallback sane, but the Inter stylesheet is fetched even though the CSS uses the system stack. Cosmetic/perf, not a correctness bug.
- **[INFORMATIONAL] Font name reuse:** frontend allowlist and backend allowlist are maintained in two files; a mismatch would silently fall back rather than break. `configlogic.test.mjs` smoke-tests the mapping; acceptable.

---

## 5. Module Findings

**Verified good:**
- Registry-driven: `ensureDefaults`/`syncModuleRows` create one row per registered module; `modules()` merges stored state over registry defaults; unknown module keys are rejected (`updateModules` line 157).
- Required modules can never be disabled (`updateModules` line 166) — 422.
- Backend gate `EnsureTenantModuleEnabled` (`module:` alias) blocks disabled optional modules per tenant with 403; required modules pass even with a stale disabled row (`EnsureTenantModuleEnabled.php:46`). Tested (`TenantModuleGateTest`).
- Public projection reports required modules enabled regardless of stored rows (`TenantContextResource::publicModules`).

**Findings:**
- **[MEDIUM] The `enterprise` module is never gated on the backend.** In `routes/api.php` the `enterprise` prefix (lines 186-221) is not wrapped in `module:enterprise`; the only gates inside are `module:laboratory`, `module:pharmacy`, `module:urgent_care` on sub-routes. A tenant with Enterprise Analytics disabled can still call `/api/enterprise/dashboard`, `/patients`, `/staff`, `/ehr`, `/vitals`, `/billing`, `/ai/patient-summary`, `/ehr/entries`, `/vitals`, `/staff` (register), all role-gated but not module-gated.
- **[MEDIUM] The `nurse_station` module is never gated on the backend.** No route uses `module:nurse_station`. All nursing flows are role-gated (`role:nurse,...`) only, so the toggle has zero backend effect (frontend-only hiding).
- **[INFORMATIONAL] OR semantics in `EnsureTenantModuleEnabled` are unused** — every call site passes a single module key.
- **[LOW] `/blog/analytics` and `/blog/create` have no routes** (`App.tsx` registers only `/blog`); `BlogAnalyticsPage` and `CreateBlogPage` are orphaned components (pre-existing, unrelated to module hiding).

---

## 6. Role Findings

**Verified good:**
- Registry-driven role rows; required roles (hospital_admin, doctor, nurse, patient) cannot be disabled; platform roles are rejected as tenant roles (`assertValidTenantRoles` + `updateRoles`).
- Config surfaces serialize `roles` via `TenantConfigurationResource` and the settings UI can toggle optional roles.

**Findings — this is the weakest part of the system:**
- **[CRITICAL] A disabled role is cosmetic.** Nothing consumes `tenant_role_configuration` except `TenantConfigurationService::roles()` (a read-back for the UI):
  - `AuthController::login` (line 46) does not check whether the user's role is enabled for the tenant → a disabled `pharmacist` can still sign in.
  - `EnsureUserHasRole` (line 13) checks only `isRole(...)` → disabled-role users still pass role-gated routes.
  - `AdminController::storeUser` (line 68) does not exclude disabled roles → an admin can keep creating `pharmacist`/`lab_technician` users after disabling the role.
  - The Roles settings page (`HospitalRolesPage.tsx:21`) literally states *"Control which roles can sign in at your hospital"* — that promise is not implemented.
- **[HIGH] `AdminController::updateUser` (line 89) allows promoting any user to `hospital_admin`.** Validation is `Rule::in(Role::values())`, which includes `hospital_admin`; the guard at line 98 only blocks reassigning an *existing* admin (and allows an admin to demote *their own* account). `storeUser` deliberately excludes `hospital_admin` (`Role::assignableByAdmin()`), so the two paths are inconsistent → escalation + self-lockout.
- **[LOW] Frontend has no consumer for role state** beyond the settings toggles; no nav/routes are role-config aware (by design the frontend must not be the enforcement point, but the UI gives no hint that the toggle is advisory).

---

## 7. Settings Findings

**Verified good:**
- Strict allowlist (`assertKnownSettings`) + per-key rules (`settings.locale/timezone/date_format/time_format/default_appointment_duration`) shared by both config surfaces.
- `settings()` merges defaults and drops unknown stored keys on read; change detection compares against effective values so a no-op is not written or audited (`updateSettings`).
- The `validated()` nested-key gotcha is handled explicitly in both controllers (re-attaching raw sections before the allowlist check) — good.

**Findings:**
- **[MEDIUM] `Platform\TenantController::update` (line 76) accepts an arbitrary `settings` array** (`['sometimes','array']`) and `$tenant->update($data)` writes it verbatim, bypassing `assertKnownSettings`/`rules()`. Unknown keys can be persisted into `tenants.settings` (they are filtered on read, and the legacy `settings.branding` backfill path could even be used to write branding outside the branding surface). The two configuration surfaces enforce the allowlist; this lifecycle endpoint does not — an inconsistency.
- **[LOW] No `rules()` validation for the `settings` key type on the lifecycle endpoint** (values are not type-validated before storage).

---

## 8. Authorization Findings

**Verified good:**
- `EnsureUserHasRole` (OR semantics) is applied consistently to role-gated routes; hospital config endpoints require `hospital_admin`; platform config endpoints require `platform_super_admin`/`platform_admin`.
- Tenant vs platform auth is fully separated: separate guards, separate DBs; guard state is forgotten on context switch (`TenantResolver::forgetCachedGuards`), tokens never cross tenants (tested).
- Tenant tokens are meaningless on the platform host and vice-versa (tested `CoreDomainIsolationTest`).
- The active tenant is always resolved from the request host, never from a client id (both config controllers document and implement this; tested).
- Platform roles cannot be minted as tenant roles (create path tested; config validation enforced).

**Findings:**
- **[HIGH] `updateUser` role escalation** (see §6) — new roles allowed include `hospital_admin`.
- **[MEDIUM] Role-disable is not enforced at any auth layer** (see §6) — login and middleware both ignore `tenant_role_configuration`.
- **[LOW] `PlatformAuthController::login` performs no status/audit** and does not check for disabled platform accounts (no disabled flag exists — informational).
- **[LOW] `TenantContextController` dev `?host=` path does not check `isActive()`** (line 32) before returning a tenant context, unlike the middleware path (line 32 in `ResolveTenant`). Dev-only and public data, but an inconsistency.

---

## 9. Tenant-Isolation Findings

**Verified good:**
- Physical per-tenant database; `TenantDatabaseManager` switches the default connection; control-plane models pin `$connection = 'control'`.
- Host resolution order: exact `tenant_domains` match → single-level subdomain of the platform domain by `slug`; platform subdomains (api/admin/www) are reserved and never resolvable as tenants (slug reservation at provision + `isPlatformHost`).
- Unknown hosts 404; suspended tenants 403 (middleware).
- Cross-tenant data isolation, duplicate emails per tenant, colliding auto-increment ids, token isolation — all tested.
- Database names are validated (`assertSafeDatabaseName`) to prevent identifier injection in raw DDL.

**Findings:**
- **[LOW] Dev `?host=` override can read an inactive tenant's public context** (see §8). Public data only; production ignores the parameter entirely (`app()->isProduction()` guard).
- **[INFORMATIONAL] Domain removal keeps the primary domain; non-primary domains can be removed** — enforced at the platform surface only.

---

## 10. Cache / State Findings

**Verified good:**
- Backend holds no tenant-config cache — every read queries the control plane, so a change is immediately authoritative.
- Frontend keeps tenant context **in memory only** (no localStorage); `refresh()` re-fetches after a hospital saves its own config, re-applying branding/modules (signature diff in `TenantContext.tsx`).
- While context is loading, all modules are treated as enabled (`useEnabledModules`) — deliberate, avoids stripping UI on first paint; real state applies on load.

**Findings:**
- **[LOW] No cross-actor invalidation.** A platform admin disabling a module for a tenant does not push an update to that tenant's already-open browsers (they reflect it only after reload/refresh). Given the in-memory design this is expected; worth noting for support/ops.
- **[INFORMATIONAL] `TenantDatabaseManager::previousDefault` is single-level** (nested `connect()` without `disconnect()` would not compose) — safe for the current one-tenant-per-request model.

---

## 11. Provisioning / Default Findings

**Verified good:**
- `TenantProvisioner::provision` creates record → DB → migrate → seed → domain → `ensureDefaults` → active; failure marks `failed` and rethrows; the hospital-application path (`HospitalApplicationController::approve`) rolls back the DB and record on failure.
- `ensureDefaults` backfills branding/modules/roles for legacy tenants; re-running is idempotent (rows are created only when missing).
- Settings defaults merge on read, so a fresh tenant behaves correctly even before the first configuration read.

**Findings:**
- **[MEDIUM] Direct platform provisioning has no rollback.** `Platform\TenantController::store` (line 43) calls `provision()` without a try/catch; when provisioning fails the tenant record is left in `failed` status and the partial database file may remain, permanently occupying the slug (the application-approval path cleans up; the direct path does not).
- **[LOW] `Platform\TenantController::migrate` (line 119) runs full `migrate`, not `migratePending`** and toggles status itself; fine, but the `migratePending` helper is otherwise unused.
- **[INFORMATIONAL] Provisioned `settings` only seed locale/timezone**; full defaults (date/time format, appointment duration) are merged at read time — correct, just not materialized.

---

## 12. Platform (Control Plane) Findings

**Verified good:**
- Platform routes are grouped under `auth:platform` + `role:platform_super_admin,platform_admin`.
- A single unified configuration surface (`Platform\TenantConfigurationController`) — GET returns the full model, PATCH applies any combination, audited.
- Hospital applications + invitation flow are audited end-to-end (submitted/reviewed/approved/rejected/invitation_accepted); invitation tokens are single-use, expiring, digest-only.

**Findings:**
- **[MEDIUM] `Platform\TenantController` lifecycle actions are un-audited**: `store`, `update`, `addDomain`, `removeDomain`, `migrate` write no `platform_audit_logs` (only config changes and onboarding events are recorded).
- **[LOW] `PlatformAuthController` login/logout are un-audited** and not throttled (see §14).
- **[INFORMATIONAL] `update` accepts raw `status` and `settings`** (see §7) — status is a legitimate platform action; settings is the allowlist bypass.

---

## 13. Audit-Log Findings

**Verified good:**
- Control plane: `PlatformAuditLog` records onboarding lifecycle + config changes from both surfaces, with actor, ip, user-agent, changed categories/keys (never values/secrets). Index restricted to platform admins.
- Tenant plane: `AuditLog` via `AuditService`/direct creates for auth (register/login/logout) and admin user management; non-admins can only read their own entries (`AuditLogController`).

**Findings:**
- **[MEDIUM] Lifecycle events missing** (see §12): tenant create/update/domain/migrate and platform login/logout are not audited.
- **[INFORMATIONAL] `PlatformAuditLog` config-change metadata stores keys but not before/after values** — deliberate anti-noise/anti-secret design; acceptable.
- **[LOW] `acceptInvitation` does not check tenant `isActive()`** before redeeming (only token validity) — a suspended tenant's admin can still claim the invitation.

---

## 14. Security Findings

**Verified good:**
- Tenant database credentials are encrypted at rest (`Tenant::$casts database_password => encrypted`, `Hidden`) and never serialized (tested).
- No secret (passwords, tokens, credentials, database details) is ever returned by config/context resources (tested via `assertJsonMissingPath`).
- Injection-safe database identifiers; sanitized asset/color/font allowlists on both tiers.
- No client-controlled tenant id anywhere in configuration resolution.

**Findings (ranked):**
- **[CRITICAL] Role-disable is unenforced** (§6) — advertised control, zero enforcement.
- **[HIGH] `updateUser` admin escalation + self-demotion** (§6/§8).
- **[MEDIUM] `/api/auth/login` and `/api/platform/auth/login` have no throttling** (only `throttle:60,1` on the authenticated group; register and hospital-applications are throttled). Brute-force surface.
- **[MEDIUM] Settings allowlist bypass on `Platform\TenantController::update`** (§7).
- **[MEDIUM] Enterprise/nurse_station modules un-gated** (§5) — disabled-module users keep full API access to those surfaces.
- **[LOW] Dev `?host=` returns inactive-tenant context** (§8).

---

## 15. Gaps Ranked by Severity

| # | Severity | Gap | Location | Suggested fix |
|---|---|---|---|---|
| 1 | **CRITICAL** | Disabled tenant roles are not enforced (login, routes, user creation) | `AuthController::login`, `EnsureUserHasRole`, `AdminController::storeUser` | Consult `TenantConfigurationService::roles()`: block login for disabled roles, 403 in `EnsureUserHasRole` for disabled roles, reject disabled roles in `storeUser`. Requires product decision (see §16). |
| 2 | **HIGH** | `updateUser` can promote to `hospital_admin` / demote self | `AdminController::updateUser:93` | Restrict to `Role::assignableByAdmin()`; forbid demoting the last admin. |
| 3 | **MEDIUM** | `enterprise` module un-gated on backend | `routes/api.php:186` | Wrap enterprise prefix in `module:enterprise` (nested gates already handle pharmacy/lab/urgent-care). |
| 4 | **MEDIUM** | `nurse_station` module un-gated on backend | `routes/api.php` | Decide which nurse routes belong to the module and gate them. |
| 5 | **MEDIUM** | Settings allowlist bypass | `Platform\TenantController::update:84` | Run `assertKnownSettings` (+ type rules) before `update`. |
| 6 | **MEDIUM** | Tenant lifecycle + platform login not audited | `Platform\TenantController`, `PlatformAuthController` | Add `PlatformAuditLog` writes for create/update/domain/migrate/login/logout. |
| 7 | **MEDIUM** | No login throttling | `routes/api.php:44,92` | Add `throttle` to both login routes. |
| 8 | **MEDIUM** | Direct provision leaves `failed` record + partial DB on error | `Platform\TenantController::store` | Reuse rollback logic (like `HospitalApplicationController::rollbackFailedProvision`). |
| 9 | **LOW** | `system` font loads Inter stylesheet unnecessarily | `branding.ts:113` | Skip `loadWebFont` when resolved family maps to system stack. |
| 10 | **LOW** | Dev `?host=` returns inactive-tenant context | `TenantContextController:32` | Add `isActive()` check on the declared tenant. |
| 11 | **LOW** | `/blog/analytics`, `/blog/create` unrouted | `App.tsx` | Register or remove orphaned pages. |
| 12 | **INFORMATIONAL** | No cross-actor frontend invalidation; OR-semantics unused; `--app-accent-soft` uses `color-mix` | — | None required. |

---

## 16. Open Product Decisions (blocking fixes 1–2)

Before implementing, these need answers:

1. **What should disabling an optional role actually do?** The UI says "Control which roles can sign in." Enforce at login only, at route access only, at user-creation only, or all three? (Recommendation: all three — login 403/422, `EnsureUserHasRole` 403, `storeUser` 422.)
2. **Should `hospital_admin` ever be assignable via `updateUser`?** (Recommendation: no — match `storeUser`'s `assignableByAdmin()`.)
3. **Should an admin be able to demote their own account if they are the last admin?** (Recommendation: block — prevents self-lockout.)
4. **Gate the whole `/enterprise/*` prefix behind `module:enterprise`, or leave the current sub-gates?** (Recommendation: wrap the prefix; pharmacy/lab/urgent-care sub-routes keep their nested gates so they work when enterprise is off.)

---

## 17. Fix Plan (pending §16 decisions)

- Add `TenantConfigurationService::isRoleEnabled(Tenant, string): bool`; wire into login, `EnsureUserHasRole`, `AdminController::storeUser/updateUser`.
- Restrict `AdminController::updateUser` roles to `Role::assignableByAdmin()`; prevent demoting the final admin.
- Wrap the `enterprise` route prefix in `module:enterprise`; gate nurse-station routes per decision.
- Enforce settings allowlist in `Platform\TenantController::update`.
- Add lifecycle + platform-auth audit writes.
- Add throttling to both login routes.
- Add rollback to direct provisioning.
- Add tests: disabled-role login/route/create blocked; `updateUser` escalation blocked; enterprise/nurse gates; lifecycle audit entries; login throttle; allowlist on lifecycle settings.
- Run `php artisan test`, `tsc -b`, `npm run build`, `npm run lint` (ESLint baseline: 5 pre-existing errors), and Pint.

# Two-Hospital End-to-End Acceptance Audit

Date: 2026-08-10
Scope: two fully independent hospitals + platform control plane, exercising the full production surface end-to-end. Redesign/refactor of working architecture was explicitly out of scope — only clear, verified issues were changed.

## Summary

- Backend suite: **122 tests, 723 assertions, all passing** (was 115/638; +7 acceptance tests / +85 assertions added by this audit).
- Frontend: `tsc -b` + `vite build` pass; lint reports **7 problems (5 errors, 2 warnings)** — all pre-existing baseline, none in files touched by this audit.
- One genuine production bug was found and fixed (see B1).
- All four tenant surfaces (identity/branding, modules, roles, lifecycle) verified independent between hospitals and reversible without data loss.

---

## A. PASSING checks (verified green)

| Area | Check | Verification |
|---|---|---|
| 1. Two hospitals + platform | Three independent DBs; `ResolveTenant` on every API route; platform vs tenant resolution by Host | Existing CoreDomainIsolation / TenantIsolation / TenantContext tests + new acceptance tests |
| 2. Host routing | Tenant + platform Host resolution; dev `?host=` override; **production ignores client-declared host** | `test_production_never_honors_a_client_declared_host` (new) |
| 3. Branding isolation + sanitization | Branding set on one hospital never bleeds to the other; public `/api/tenant-context` reflects each hospital's own branding; `javascript:`/`data:`/non-loopback `http`/invalid colors/fonts rejected 422 | `test_two_hospitals_keep_independent_branding_and_identity`, `test_invalid_branding_input_is_rejected` (new) |
| 4. Module gating | Disable `pharmacy` on one hospital → 403 there, unaffected on the other; **re-enable restores access**; required modules un-disableable; data survives disable | `TenantModuleGateTest` + `test_disabling_a_module_is_reversible_and_preserves_data` (new) |
| 5. Role gating | Disabled role blocks login + role-gated routes; **re-enable restores login + routes**; account not deleted; hospital_admin never assignable; last-admin cannot demote self | `TenantRoleGateTest` + `test_two_hospitals_keep_independent_role_state`, `test_disabling_a_role_is_reversible_and_preserves_the_account` (new) |
| 6. Platform lifecycle | Direct provision, application review/approve/reject + invitation, suspend, reactivate — all audited; suspend rejects every tenant-host request while DB is preserved | `HospitalOnboardingTest` + `test_platform_can_suspend_and_reactivate_without_losing_data` (new) |
| 7. Audit trail | `tenant.configuration.updated` records categories/keys only (never values/secrets); platform events record actor/IP/user-agent; failed provisioning rolls back | `TenantConfigurationTest` + new audit assertions in acceptance tests |
| 8. Credential hygiene | `TenantConfigurationResource`, `TenantContextResource`, dashboard/audit endpoints never expose `database_password` | `PlatformAdminDashboardTest` (existing) |
| 9. Data safety | Disabling a module/role never deletes data; suspension never destroys the tenant DB file | new acceptance tests |
| 10. Access control | Tenant tokens rejected cross-tenant; platform roles never resolve as tenant users; config endpoints resolve tenant from Host (never client id) | existing tests |

## B. FAILING checks

- **B1 — Frontend production API base (FOUND + FIXED).** `src/services/apiBase.ts` fell back to a hardcoded `http://127.0.0.1:8000/api` in production when `VITE_API_BASE_URL` was unset — pointing every tenant's browser at its own localhost. Fixed to derive from `window.location.origin`, so the request Host header stays equal to the tenant/platform subdomain the browser is on and `ResolveTenant` keeps switching databases correctly. Build + lint re-verified after the fix.
- No other failing checks found. Remaining lint errors are pre-existing and unrelated to tenant acceptance.

## C. Security risks (none critical; rank low → info)

- **C1 [LOW]** `TenantResource` (platform-admin surface only) exposes `database_name`, `database_host`, `database_port`, `database_username`. Password is already excluded, but a compromised platform role leaks infra connection details. Prefer hiding host/port/username (environment-level, not per-tenant).
- **C2 [LOW]** `tenant.migrate_failed` audit stores `$e->getMessage()` — a DB exception may embed hostname/credentials in the audit log. Store a sanitized exception class instead.
- **C3 [LOW]** `patient.emergency_requested` audit stores the full free-text `message` (≤500 chars). Intended for traceability, but it is sensitive clinical free-text; acceptable, flagged for awareness.
- **C4 [INFO]** No `trustProxies` config in `bootstrap/app.php`. Behind a reverse proxy, audit `ip_address` will log the proxy IP unless forwarded headers are trusted; tenant resolution already requires the proxy to preserve the `Host` header. Document as a deployment requirement (see G).

## D. Architectural risks

- **D1 [MED]** Several tenant-facing copy surfaces hardcode "vee-care" instead of using `useTenantName()`/branding: BlogPage ("Vee-care Journal"), AppointmentsPage ("Pay with your Vee-care card"), EnterpriseModulesPage ("vee-care://" QR, "Vee-care membership card"), PatientCardPage copy, LandingPage hero/CTA. LandingPage's header already uses the tenant name — the pass is partial.
- **D2 [MED]** `EnterpriseModulesPage` inlines its own client-side role arrays instead of reusing the central `routeRoles` map in `src/auth/roleAccess.ts`; duplicated access logic can drift from server truth.
- **D3 [MED]** Single ~1.2 MB JS chunk (362 KB gzip). Route-level lazy loading is recommended.
- **D4 [INFO]** The dev `?host=` override is gated only on `!app()->isProduction()`; a staging env mislabeled `production` would silently lose host-following. Low likelihood.

## E. Missing tests (gaps found and now closed by `TwoHospitalAcceptanceTest`)

Coverage that did not exist before this audit and is now green:
- Cross-tenant branding isolation (setting branding on one hospital and asserting the other is untouched, incl. public bootstrap endpoint).
- Cross-tenant role isolation (role disabled on one hospital, fully functional on the other).
- Module disable → re-enable round-trip restoring routes.
- Role disable → re-enable round-trip restoring login + route access.
- Disabling a module/role preserves the underlying data (inventory row, user account).
- Platform suspend → reactivate round-trip preserving the tenant DB file and restoring access, with control-plane audit rows.
- Production ignoring a client-declared `?host=` (the `!isProduction()` branch was previously untested).
- Invalid branding asset URL / color / font rejection at the API surface.

Remaining gap [INFO]: there is no frontend unit test suite at all; acceptance coverage is integration-level (backend) + build-time (frontend).

## F. Recommended fixes by severity

1. **[DONE] Production API base** — origin-derived fallback in `resolveApiBaseUrl()` (B1).
2. [LOW] Trim `database_host/port/username` from `TenantResource` or mark platform-internal (C1).
3. [LOW] Sanitize `tenant.migrate_failed` error metadata to exception class only (C2).
4. [LOW] `useTenantName()`/branding pass over hardcoded brand copy (D1).
5. [MED, optional] Reuse `routeRoles` in `EnterpriseModulesPage` (D2); lazy-load routes for the bundle (D3).

## G. Next milestone

1. **Frontend brand/role-consistency pass** — swap hardcoded "vee-care" copy for tenant branding on the tenant pages (D1), centralize role checks (D2).
2. **Deployment hardening** — document reverse-proxy requirements (`proxy_set_header Host $host`, forwarded-proto), add `trustProxies` config, and note `VITE_API_BASE_URL` semantics per environment (C4).
3. **Bundle code-splitting** — route-level lazy loading to clear the 500 kB chunk warning (D3).
4. **Optional cleanup** — C1 + C2 sanitization.

## Changes made by this audit

- `backend/tests/Feature/TwoHospitalAcceptanceTest.php` (new, 7 tests).
- `backend/tests/Feature/TenantConfigurationTest.php` — fixed `test_tenant_lifecycle_actions_are_audited` (seed a primary domain; delete a non-primary domain, since the primary cannot be removed — 422).
- `frontend/src/services/apiBase.ts` — production API base now derived from `window.location.origin`.
- `backend/app/Http/Controllers/Api/AdminController.php`, `backend/app/Http/Controllers/Api/Platform/TenantConfigurationController.php`, `backend/app/Http/Controllers/Api/Platform/TenantController.php`, `backend/app/Http/Controllers/Api/TenantConfigurationController.php`, `backend/app/Http/Middleware/EnsureUserHasRole.php`, `backend/app/Services/TenantProvisioner.php`, `backend/app/Services/TenantResolver.php`, `backend/bootstrap/app.php`, `backend/routes/api.php` — Pint style fixes (no behavior change).

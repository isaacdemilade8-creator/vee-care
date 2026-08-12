import { useMemo } from 'react';
import { useTenant } from '../../context/TenantContext';
import type { TenantModuleState } from './types';

/**
 * Single frontend mechanism for "is module X enabled for this tenant?".
 *
 * The authoritative module states come from GET /api/tenant-context
 * (`TenantContext.modules`), which mirrors backend/config/tenant-defaults.php
 * and is regenerated on every tenant-context refresh, so a hospital saving a
 * module change re-applies to the UI without a logout. Only the stable module
 * keys are enumerated here — labels, descriptions, required flags and default
 * states all come from the API so nothing is duplicated.
 */

/** Mirrors the module keys in backend/config/tenant-defaults.php `modules`. */
export const TENANT_MODULES = [
  'appointments',
  'ehr',
  'prescriptions',
  'messaging',
  'laboratory',
  'pharmacy',
  'telemedicine',
  'urgent_care',
  'patient_portal',
  'nurse_station',
  'blog',
  'enterprise',
] as const;

export type TenantModuleKey = (typeof TENANT_MODULES)[number];

export function isTenantModuleKey(value: string): value is TenantModuleKey {
  return (TENANT_MODULES as readonly string[]).includes(value);
}

/**
 * Whether a module state is available to a tenant. Required modules can never
 * be disabled (enforced server-side), but this also guards against a stale
 * row: a required module is always treated as enabled.
 */
export function isModuleEnabled(state: TenantModuleState | undefined): boolean {
  return !state || state.enabled || state.required;
}

const ALL_MODULES = new Set<TenantModuleKey>(TENANT_MODULES);

/**
 * The set of modules enabled for the active tenant. While the authoritative
 * tenant context is still loading (or absent) every module is treated as
 * enabled so the first paint is never falsely stripped of navigation or
 * routes; the real state is applied as soon as the context arrives.
 */
export function useEnabledModules(): ReadonlySet<TenantModuleKey> {
  const { tenant, isLoading } = useTenant();
  const modules = tenant?.modules;

  return useMemo(() => {
    if (isLoading || !modules) {
      return ALL_MODULES;
    }

    const enabled = new Set<TenantModuleKey>();

    for (const key of TENANT_MODULES) {
      if (isModuleEnabled(modules[key])) {
        enabled.add(key);
      }
    }

    return enabled;
  }, [isLoading, modules]);
}

/** Whether a specific module is enabled for the active tenant. */
export function useModuleEnabled(module: TenantModuleKey): boolean {
  return useEnabledModules().has(module);
}

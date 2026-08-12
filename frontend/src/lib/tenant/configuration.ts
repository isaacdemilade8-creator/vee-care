import { api } from '../../services/api';

/**
 * Hospital self-service configuration client for GET/PATCH /api/configuration.
 *
 * Types mirror backend/app/Http/Resources/TenantConfigurationResource.php and
 * the option lists below mirror backend/config/tenant-defaults.php — the
 * backend is the single source of truth, so both sides must change together.
 */

export interface TenantConfigurationBranding {
  logo: string | null;
  favicon: string | null;
  primary_color: string | null;
  secondary_color: string | null;
  accent_color: string | null;
  font_family: string | null;
}

export interface TenantModuleConfig {
  enabled: boolean;
  required: boolean;
  name: string;
  description: string;
}

export interface TenantRoleConfig {
  enabled: boolean;
  required: boolean;
  label: string;
}

export interface TenantGeneralSettings {
  locale: string;
  timezone: string;
  date_format: string;
  time_format: string;
  default_appointment_duration: number;
}

export interface TenantConfiguration {
  name: string;
  branding: TenantConfigurationBranding;
  modules: Record<string, TenantModuleConfig>;
  roles: Record<string, TenantRoleConfig>;
  settings: TenantGeneralSettings;
}

/** Mirrors config('tenant-defaults.fonts'). */
export const TENANT_FONTS = ['Inter', 'Roboto', 'Open Sans', 'Poppins', 'Montserrat', 'system'] as const;

/** Mirrors config('tenant-defaults.settings.locales'). */
export const TENANT_LOCALES = ['en'] as const;

/** Mirrors config('tenant-defaults.settings.timezones'). */
export const TENANT_TIMEZONES = [
  'UTC',
  'Africa/Lagos',
  'America/New_York',
  'Europe/London',
  'Asia/Tokyo',
  'Australia/Sydney',
] as const;

/** Mirrors config('tenant-defaults.settings.date_formats'). */
export const TENANT_DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd M Y'] as const;

/** Mirrors config('tenant-defaults.settings.time_formats'). */
export const TENANT_TIME_FORMATS = ['H:i', 'h:i A'] as const;

/** Mirrors config('tenant-defaults.branding') — the values a hospital inherits. */
export const TENANT_BRANDING_DEFAULTS: Pick<
  TenantConfigurationBranding,
  'primary_color' | 'secondary_color' | 'accent_color' | 'font_family'
> = {
  primary_color: '#0f766e',
  secondary_color: '#0f766e',
  accent_color: '#0f766e',
  font_family: 'Inter',
};

export type TenantConfigurationPatch = {
  name?: string;
  branding?: Partial<TenantConfigurationBranding>;
  modules?: Record<string, boolean>;
  roles?: Record<string, boolean>;
  settings?: Partial<TenantGeneralSettings>;
};

export async function getTenantConfiguration(): Promise<TenantConfiguration> {
  const { data } = await api.get<TenantConfiguration>('/configuration');
  return data;
}

/**
 * Whether a tenant role may be assigned to a user. Mirrors the backend
 * TenantConfigurationService::isRoleEnabled semantics: while the configuration
 * is still loading (undefined) the role is treated as enabled so the UI never
 * falsely strips options, and required roles can never be disabled. Used to
 * drive the role dropdowns in the admin and staff-registration surfaces.
 */
export function isTenantRoleAssignable(configuration: TenantConfiguration | undefined, role: string): boolean {
  const state = configuration?.roles[role];
  return !state || state.enabled || state.required;
}

export async function updateTenantConfiguration(payload: TenantConfigurationPatch): Promise<TenantConfiguration> {
  const { data } = await api.patch<TenantConfiguration>('/configuration', payload);
  return data;
}

/** Branding a hospital actually displays once nulls inherit the defaults. */
export function effectiveBranding(branding: TenantConfigurationBranding): TenantConfigurationBranding {
  return { ...TENANT_BRANDING_DEFAULTS, ...branding };
}

/**
 * Compute the minimal PATCH payload between a baseline and the current draft.
 * Only sections/keys that actually differ are included, so saving never
 * produces a no-op change and the backend's change-detection stays meaningful.
 */
export function configurationPatch(
  baseline: TenantConfiguration,
  draft: TenantConfiguration,
): TenantConfigurationPatch {
  const patch: TenantConfigurationPatch = {};

  if (baseline.name !== draft.name) {
    patch.name = draft.name;
  }

  const branding = diffBranding(baseline.branding, draft.branding);
  if (branding) {
    patch.branding = branding;
  }

  const modules = diffEnabledMap(baseline.modules, draft.modules);
  if (modules) {
    patch.modules = modules;
  }

  const roles = diffEnabledMap(baseline.roles, draft.roles);
  if (roles) {
    patch.roles = roles;
  }

  const settings = diffSettings(baseline.settings, draft.settings);
  if (settings) {
    patch.settings = settings;
  }

  return patch;
}

/** Whether a configuration patch contains anything to send. */
export function patchHasChanges(patch: TenantConfigurationPatch): boolean {
  return Object.keys(patch).length > 0;
}

function diffBranding(
  baseline: TenantConfigurationBranding,
  draft: TenantConfigurationBranding,
): Partial<TenantConfigurationBranding> | undefined {
  const diff: Partial<TenantConfigurationBranding> = {};

  for (const key of Object.keys(baseline) as Array<keyof TenantConfigurationBranding>) {
    if (baseline[key] !== draft[key]) {
      diff[key] = draft[key];
    }
  }

  return Object.keys(diff).length > 0 ? diff : undefined;
}

function diffEnabledMap(
  baseline: Record<string, { enabled: boolean }>,
  draft: Record<string, { enabled: boolean }>,
): Record<string, boolean> | undefined {
  const diff: Record<string, boolean> = {};

  for (const key of Object.keys(draft)) {
    if (draft[key] && baseline[key]?.enabled !== draft[key].enabled) {
      diff[key] = draft[key].enabled;
    }
  }

  return Object.keys(diff).length > 0 ? diff : undefined;
}

function diffSettings(
  baseline: TenantGeneralSettings,
  draft: TenantGeneralSettings,
): Partial<TenantGeneralSettings> | undefined {
  const diff: Partial<TenantGeneralSettings> = {};

  for (const key of Object.keys(baseline) as Array<keyof TenantGeneralSettings>) {
    if (baseline[key] !== draft[key]) {
      (diff as Record<string, string | number>)[key] = draft[key];
    }
  }

  return Object.keys(diff).length > 0 ? diff : undefined;
}
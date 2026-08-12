/** Statuses match the backend `TenantStatus` enum (all six lifecycle states). */
export type TenantStatus = 'pending' | 'provisioning' | 'active' | 'suspended' | 'rejected' | 'failed';

/** Public, safe tenant branding — mirrors the backend TenantContextResource. */
export interface TenantBranding {
  logo?: string | null;
  favicon?: string | null;
  primaryColor?: string | null;
  secondaryColor?: string | null;
  accentColor?: string | null;
  fontFamily?: string | null;
}

/**
 * Enabled/required state of one tenant module — mirrors the backend
 * TenantContextResource `modules` map. Required modules can never be disabled
 * and are reported enabled even if a stale row were ever disabled.
 */
export interface TenantModuleState {
  enabled: boolean;
  required: boolean;
}

/** Public tenant context exposed by GET /api/tenant-context. */
export interface TenantContext {
  id: number;
  name: string;
  slug: string;
  status: TenantStatus;
  branding: TenantBranding;
  /** Module key → enabled/required state (single source for tenant UI gating). */
  modules: Record<string, TenantModuleState>;
}

/** `context` values returned by the backend TenantContextController. */
export type ApiContextKind = 'platform' | 'tenant' | 'none';

export interface TenantContextResponse {
  context: ApiContextKind;
  tenant: TenantContext | null;
}

/** Tenant record as returned by the platform (control plane) API. */
export interface PlatformTenant {
  id: number;
  name: string;
  slug: string;
  type: string;
  plan: string;
  status: TenantStatus;
  currency: string;
  /** Non-sensitive configuration; database fields are never exposed here. */
  settings?: Record<string, string | number | boolean | null>;
  domains: Array<{ id: number; domain: string; is_primary: boolean }>;
  created_at?: string;
  updated_at?: string;
}

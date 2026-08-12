import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { applyTenantBranding, clearTenantBranding } from '../lib/tenant/branding';
import { resolveHostContext } from '../lib/tenant/hostname';
import type { HostContext } from '../lib/tenant/hostname';
import type { ApiContextKind, TenantBranding, TenantContext, TenantContextResponse } from '../lib/tenant/types';
import { api } from '../services/api';

interface TenantContextValue {
  /** Synchronous, hostname-based classification (never blocks first paint). */
  host: HostContext;
  /** Authoritative context from GET /api/tenant-context, once loaded. */
  context: ApiContextKind | null;
  tenant: TenantContext | null;
  branding: TenantBranding | null;
  isLoading: boolean;
  /**
   * Re-fetch the authoritative context (used after a hospital updates its own
   * branding/name so the whole app re-applies the new identity).
   */
  refresh: () => Promise<void>;
}

const TenantContext = createContext<TenantContextValue | null>(null);

export function TenantProvider({ children }: { children: ReactNode }) {
  const host = useMemo(() => resolveHostContext(window.location.hostname), []);
  const [context, setContext] = useState<ApiContextKind | null>(null);
  const [tenant, setTenant] = useState<TenantContext | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const appliedSignature = useRef('');

  const refresh = useCallback(async () => {
    // In development the API is usually reached at 127.0.0.1, so the backend
    // cannot infer the tenant from the request Host. Declare the browser host
    // explicitly; the backend honors it only outside production.
    const hostQuery = host.kind === 'tenant' ? `?host=${encodeURIComponent(host.hostname)}` : '';

    const { data } = await api.get<TenantContextResponse>(`/tenant-context${hostQuery}`);

    setContext(data.context);
    setTenant(data.tenant);
  }, [host]);

  useEffect(() => {
    let cancelled = false;

    // In development the API is usually reached at 127.0.0.1, so the backend
    // cannot infer the tenant from the request Host. Declare the browser host
    // explicitly; the backend honors it only outside production.
    const hostQuery = host.kind === 'tenant' ? `?host=${encodeURIComponent(host.hostname)}` : '';

    api
      .get<TenantContextResponse>(`/tenant-context${hostQuery}`)
      .then(({ data }) => {
        if (cancelled) {
          return;
        }
        setContext(data.context);
        setTenant(data.tenant);
      })
      .catch(() => {
        if (cancelled) {
          return;
        }
        setContext(host.kind === 'platform' ? 'platform' : 'none');
        setTenant(null);
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [host]);

  // Branding side effects follow the resolved tenant (or its removal). The
  // full identity is compared (id, name, branding), so a hospital updating its
  // own name or branding re-applies the new identity immediately.
  useEffect(() => {
    const signature = tenant ? JSON.stringify({ id: tenant.id, name: tenant.name, branding: tenant.branding ?? null }) : '';

    if (appliedSignature.current === signature) {
      return;
    }

    appliedSignature.current = signature;
    applyTenantBranding(tenant);
  }, [tenant]);

  useEffect(() => () => clearTenantBranding(), []);

  const value = useMemo(
    () => ({ host, context, tenant, branding: tenant?.branding ?? null, isLoading, refresh }),
    [context, host, isLoading, refresh, tenant],
  );

  return <TenantContext.Provider value={value}>{children}</TenantContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useTenant() {
  const context = useContext(TenantContext);
  if (!context) {
    throw new Error('useTenant must be used inside TenantProvider');
  }
  return context;
}

/** The active tenant's display name, falling back to the platform brand. */
// eslint-disable-next-line react-refresh/only-export-components
export function useTenantName(): string {
  return useTenant().tenant?.name ?? 'vee-care';
}

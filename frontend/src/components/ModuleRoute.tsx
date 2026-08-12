import type { ReactNode } from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useModuleEnabled, type TenantModuleKey } from '../lib/tenant/modules';

/**
 * Blocks a route (or tree of routes) when the tenant has the module disabled.
 *
 * While the authoritative tenant context is still loading the module is
 * treated as enabled, so a disabled-module redirect never flashes on first
 * paint. Once the context loads, a disabled module is redirected to `fallback`
 * (the dashboard by default; pass a public fallback such as `/` for public
 * pages). Role authorization stays in the existing ProtectedRoute layer.
 */
export function ModuleRoute({
  module,
  children,
  fallback = '/dashboard',
}: {
  module: TenantModuleKey;
  children?: ReactNode;
  fallback?: string;
}) {
  const enabled = useModuleEnabled(module);

  if (!enabled) {
    return <Navigate to={fallback} replace />;
  }

  return children ? <>{children}</> : <Outlet />;
}

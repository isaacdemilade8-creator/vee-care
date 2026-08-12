import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';

/**
 * Only renders children on a platform (control plane) host. Tenant hosts are
 * redirected to their own landing page.
 */
export function PlatformRoute({ children }: { children: ReactNode }) {
  const { host } = useTenant();

  if (host.kind !== 'platform') {
    return <Navigate to="/" replace />;
  }

  return <>{children}</>;
}

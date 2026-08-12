import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';

/**
 * Renders children on tenant-facing hosts. Platform hosts never see hospital
 * surfaces (the backend rejects tenant routes there anyway) and are sent to
 * the platform shell.
 */
export function TenantRoute({ children }: { children: ReactNode }) {
  const { host } = useTenant();

  if (host.kind === 'platform') {
    return <Navigate to="/platform" replace />;
  }

  return <>{children}</>;
}

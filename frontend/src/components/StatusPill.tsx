import {
  applicationStatusClass,
  applicationStatusLabel,
  tenantStatusClass,
  tenantStatusLabel,
  userStatusClass,
  userStatusLabel,
} from '../lib/platform/status';
import type { PlatformApplicationStatus, PlatformUserStatus } from '../lib/platform/types';
import type { TenantStatus } from '../lib/tenant/types';
import styles from './StatusPill.module.scss';

type PillStatus = TenantStatus | PlatformApplicationStatus | PlatformUserStatus;

/**
 * Accessible status indicator for platform records. The label carries the
 * meaning (never colour alone) and the colour only reinforces it.
 */
export function StatusPill({ status, kind }: { status: PillStatus; kind: 'tenant' | 'application' | 'user' }) {
  const isTenant = kind === 'tenant';
  const isUser = kind === 'user';
  const label = isTenant
    ? tenantStatusLabel[status as TenantStatus] ?? String(status).replace(/_/g, ' ')
    : isUser
      ? userStatusLabel[status as PlatformUserStatus] ?? String(status).replace(/_/g, ' ')
      : applicationStatusLabel[status as PlatformApplicationStatus] ?? String(status).replace(/_/g, ' ');
  const cls = isTenant
    ? tenantStatusClass[status as TenantStatus]
    : isUser
      ? userStatusClass[status as PlatformUserStatus]
      : applicationStatusClass[status as PlatformApplicationStatus];

  return (
    <span className={`${styles.pill} ${cls ? styles[cls] : styles.default}`}>
      <span className={styles.dot} aria-hidden="true" />
      {label}
    </span>
  );
}

import type { PlatformRole } from '../../types';
import type { PlatformApplicationStatus, PlatformUserStatus } from './types';
import type { TenantStatus } from '../tenant/types';

/**
 * Human-readable labels and CSS module class suffixes for platform statuses.
 * Values mirror the backend enums exactly — never invent statuses here.
 */
export const tenantStatusLabel: Record<TenantStatus, string> = {
  pending: 'Pending',
  provisioning: 'Provisioning',
  active: 'Active',
  suspended: 'Suspended',
  rejected: 'Rejected',
  failed: 'Failed',
};

/** SCSS module class suffix for a tenant status pill. */
export const tenantStatusClass: Record<TenantStatus, string> = {
  pending: 'pending',
  provisioning: 'provisioning',
  active: 'active',
  suspended: 'suspended',
  rejected: 'rejected',
  failed: 'failed',
};

export const applicationStatusLabel: Record<PlatformApplicationStatus, string> = {
  pending: 'Pending',
  under_review: 'Under review',
  approved: 'Approved',
  rejected: 'Rejected',
};

export const applicationStatusClass: Record<PlatformApplicationStatus, string> = {
  pending: 'pending',
  under_review: 'underReview',
  approved: 'approved',
  rejected: 'rejected',
};

/** Known control-plane audit events, in display order. */
export const AUDIT_EVENTS: Array<{ value: string; label: string }> = [
  { value: 'hospital_application.submitted', label: 'Application submitted' },
  { value: 'hospital_application.reviewed', label: 'Application reviewed' },
  { value: 'hospital_application.approved', label: 'Application approved' },
  { value: 'hospital_application.rejected', label: 'Application rejected' },
  { value: 'hospital_application.invitation_accepted', label: 'Invitation accepted' },
  { value: 'platform_user.invited', label: 'Platform user invited' },
  { value: 'platform_user.invitation_accepted', label: 'Platform user joined' },
  { value: 'platform_user.invitation_revoked', label: 'Platform invitation revoked' },
  { value: 'platform_user.activated', label: 'Platform user activated' },
  { value: 'platform_user.deactivated', label: 'Platform user deactivated' },
  { value: 'platform_user.role_changed', label: 'Platform user role changed' },
  { value: 'platform_user.updated', label: 'Platform user updated' },
];

export function auditEventLabel(event: string): string {
  return AUDIT_EVENTS.find((entry) => entry.value === event)?.label ?? event.replace(/_/g, ' ');
}

/** Human-readable platform role labels. */
export const platformRoleLabel: Record<PlatformRole, string> = {
  platform_super_admin: 'Super admin',
  platform_admin: 'Admin',
};

/** Human-readable platform-user status labels. */
export const userStatusLabel: Record<PlatformUserStatus, string> = {
  active: 'Active',
  inactive: 'Inactive',
};

/** SCSS module class suffix for a platform-user status pill. */
export const userStatusClass: Record<PlatformUserStatus, string> = {
  active: 'active',
  inactive: 'inactive',
};

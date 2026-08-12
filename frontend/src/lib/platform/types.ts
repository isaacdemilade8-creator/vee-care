import type { Paginated, PlatformRole } from '../../types';
import type { TenantStatus } from '../tenant/types';
import type { PlatformTenant } from '../tenant/types';

/**
 * Application statuses mirror the backend `HospitalApplicationStatus` enum:
 * pending -> under_review -> approved | rejected.
 */
export type PlatformApplicationStatus = 'pending' | 'under_review' | 'approved' | 'rejected';

/** Hospital application as returned by the platform (control plane) API. */
export interface PlatformApplication {
  id: number;
  hospitalName: string;
  slug: string;
  type: string;
  contactName: string;
  contactEmail: string;
  contactPhone: string | null;
  description: string | null;
  status: PlatformApplicationStatus;
  reviewNotes: string | null;
  reviewedAt: string | null;
  tenantId: number | null;
  tenant?: PlatformTenantRef | null;
  /** Present only when the single-application endpoint loaded the invitation. */
  invitation?: {
    id: number;
    email: string;
    expiresAt: string | null;
    usedAt: string | null;
  } | null;
  createdAt: string;
}

/** Small tenant projection nested inside application/audit-log records. */
export interface PlatformTenantRef {
  id: number;
  name: string;
  slug: string;
  status: TenantStatus;
}

/** Single-use invitation returned exactly once by the approval endpoint. */
export interface PlatformInvitation {
  token: string;
  email: string;
  expiresAt: string;
  acceptUrl: string;
}

/** Approval response: the resource plus the one-time invitation. */
export interface PlatformApprovalResponse {
  data: PlatformApplication;
  invitation?: PlatformInvitation;
}

/** Control-plane audit record (read-only, safe projection). */
export interface PlatformAuditLog {
  id: number;
  event: string;
  actor: { id: number; name: string; email: string } | null;
  application: { id: number; hospitalName: string; status: string } | null;
  tenant: PlatformTenantRef | null;
  metadata: Record<string, string | number | boolean | null> | null;
  ipAddress: string | null;
  userAgent: string | null;
  createdAt: string;
}

/** Counts per lifecycle state on the platform. */
export interface PlatformSummary {
  hospitals: {
    total: number;
    active: number;
    suspended: number;
    provisioning: number;
    failed: number;
  };
  applications: {
    total: number;
    pending: number;
    underReview: number;
    approved: number;
    rejected: number;
  };
  recentApplications: PlatformApplication[];
  recentTenants: PlatformTenant[];
}

/** Access state of a platform (control-plane) user. */
export type PlatformUserStatus = 'active' | 'inactive';

/**
 * Platform (control-plane) user as returned by the platform API. Safe
 * projection: identity, role and access state only — never credentials.
 */
export interface PlatformUser {
  id: number;
  name: string;
  email: string;
  role: PlatformRole;
  isActive: boolean;
  status: PlatformUserStatus;
  createdAt: string;
  updatedAt: string;
}

/** Outstanding platform-user invitation (never exposes the token). */
export interface PendingPlatformUserInvitation {
  id: number;
  email: string;
  role: PlatformRole;
  inviter: { id: number; name: string } | null;
  status: 'invited';
  expiresAt: string | null;
  createdAt: string;
}

/** Single-use platform-user invitation returned exactly once by invite(). */
export interface PlatformInvitationResult {
  token: string;
  email: string;
  role: PlatformRole;
  expiresAt: string;
  acceptUrl: string;
}

/** Platform-user directory response: paginated users plus outstanding invites. */
export interface PlatformUsersResponse extends Paginated<PlatformUser> {
  pending: PendingPlatformUserInvitation[];
}

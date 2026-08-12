import type { PlatformRole, Role } from '../types';

export type AnyRole = Role | PlatformRole;

/**
 * Route-to-role matrix for tenant (hospital) functionality.
 *
 * Platform roles (platform_super_admin / platform_admin) are deliberately
 * absent from every tenant group: a platform user is never treated as a
 * hospital administrator and never gains access to hospital modules.
 */
export const routeRoles = {
  care: ['patient'] satisfies Role[],
  appointments: ['patient', 'doctor', 'hospital_admin'] satisfies Role[],
  records: ['patient', 'doctor', 'nurse', 'lab_technician', 'hospital_admin'] satisfies Role[],
  chat: ['patient', 'doctor', 'nurse', 'pharmacist', 'hospital_admin'] satisfies Role[],
  community: ['doctor', 'nurse', 'patient', 'lab_technician', 'pharmacist', 'hospital_admin'] satisfies Role[],
  profiles: ['patient', 'doctor', 'nurse', 'lab_technician', 'pharmacist', 'hospital_admin'] satisfies Role[],
  enterprise: ['doctor', 'nurse', 'lab_technician', 'pharmacist', 'hospital_admin'] satisfies Role[],
  enterpriseOverview: ['hospital_admin'] satisfies Role[],
  enterprisePatients: ['hospital_admin', 'doctor', 'nurse'] satisfies Role[],
  enterpriseEhr: ['hospital_admin', 'doctor', 'nurse', 'lab_technician'] satisfies Role[],
  enterpriseStaff: ['hospital_admin'] satisfies Role[],
  enterpriseAi: ['hospital_admin', 'doctor'] satisfies Role[],
  nurseStation: ['nurse', 'hospital_admin'] satisfies Role[],
  laboratory: ['lab_technician', 'doctor', 'nurse', 'hospital_admin'] satisfies Role[],
  pharmacy: ['pharmacist', 'hospital_admin'] satisfies Role[],
  pharmacyRequests: ['doctor', 'hospital_admin'] satisfies Role[],
  admin: ['hospital_admin'] satisfies Role[],
  activityLog: ['patient', 'doctor', 'nurse', 'lab_technician', 'pharmacist', 'hospital_admin'] satisfies Role[],
};

/**
 * Route-to-role matrix for platform (control plane) functionality.
 */
export const platformRouteRoles = {
  overview: ['platform_super_admin', 'platform_admin'] satisfies PlatformRole[],
  tenants: ['platform_super_admin', 'platform_admin'] satisfies PlatformRole[],
  hospitalApplications: ['platform_super_admin', 'platform_admin'] satisfies PlatformRole[],
  auditLogs: ['platform_super_admin', 'platform_admin'] satisfies PlatformRole[],
  users: ['platform_super_admin', 'platform_admin'] satisfies PlatformRole[],
  settings: ['platform_super_admin'] satisfies PlatformRole[],
};

/**
 * Whether a platform user's role grants access to a platform route group.
 */
export function canAccessPlatform(
  role: AnyRole | undefined,
  roles: readonly PlatformRole[],
): boolean {
  if (!role) {
    return false;
  }

  return (roles as readonly string[]).includes(role);
}

/** Names of the platform route groups in `platformRouteRoles`. */
export type PlatformRouteGroup = keyof typeof platformRouteRoles;

/**
 * Whether a user's role grants access to a tenant route group.
 *
 * The comparison is exact: a platform role never collides with a tenant role
 * string, so platform users are correctly denied tenant functionality.
 */
export function canAccess(role: AnyRole | undefined, roles: readonly Role[]): boolean {
  if (!role) {
    return false;
  }

  return (roles as readonly string[]).includes(role);
}

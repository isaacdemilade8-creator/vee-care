import type { Role } from '../types';

type LegacyRole = Role | 'hospital_admin';

export const routeRoles = {
  care: ['patient'] satisfies Role[],
  appointments: ['patient', 'doctor', 'admin', 'super_admin'] satisfies Role[],
  records: ['patient', 'doctor', 'nurse', 'lab_technician', 'admin', 'super_admin'] satisfies Role[],
  chat: ['patient', 'doctor', 'nurse', 'pharmacist', 'admin', 'super_admin'] satisfies Role[],
  community: ['super_admin', 'admin', 'doctor', 'nurse', 'patient', 'lab_technician', 'pharmacist'] satisfies Role[],
  profiles: ['patient', 'doctor', 'nurse', 'lab_technician', 'pharmacist', 'admin', 'super_admin'] satisfies Role[],
  enterprise: ['doctor', 'nurse', 'lab_technician', 'pharmacist', 'admin', 'super_admin'] satisfies Role[],
  enterpriseOverview: ['admin', 'super_admin'] satisfies Role[],
  nurseStation: ['nurse', 'admin', 'super_admin'] satisfies Role[],
  laboratory: ['lab_technician', 'doctor', 'nurse', 'admin', 'super_admin'] satisfies Role[],
  pharmacy: ['pharmacist', 'admin', 'super_admin'] satisfies Role[],
  medicineOrders: ['patient'] satisfies Role[],
  admin: ['admin', 'super_admin'] satisfies Role[],
};

export function canAccess(role: LegacyRole | undefined, roles: readonly Role[]): boolean {
  const normalizedRole = role === 'hospital_admin' ? 'admin' : role;

  return Boolean(normalizedRole && roles.includes(normalizedRole));
}

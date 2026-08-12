import type { Role } from '../../types';

/**
 * Human-readable labels and options for tenant (hospital) roles. Values mirror
 * the backend Role enum exactly — never invent roles here. The managed options
 * list mirrors `Role::assignableByAdmin()` (the roles a hospital admin may
 * create/assign): hospital_admin and platform roles are deliberately absent.
 */
export const tenantRoleLabel: Record<Role, string> = {
  hospital_admin: 'Hospital admin',
  doctor: 'Doctor',
  nurse: 'Nurse',
  patient: 'Patient',
  lab_technician: 'Lab technician',
  pharmacist: 'Pharmacist',
};

export const MANAGED_ROLE_OPTIONS: Array<{ value: Role; label: string }> = [
  { value: 'patient', label: 'Patient' },
  { value: 'doctor', label: 'Doctor' },
  { value: 'nurse', label: 'Nurse' },
  { value: 'lab_technician', label: 'Lab technician' },
  { value: 'pharmacist', label: 'Pharmacist' },
];

import { useQuery } from '@tanstack/react-query';
import { endpoints } from '../services/endpoints';

export function useEnterpriseDashboard(enabled = true) {
  return useQuery({
    queryKey: ['enterprise-dashboard'],
    enabled,
    queryFn: async () => (await endpoints.enterpriseDashboard()).data,
  });
}

export function useEnterprisePatients(search = '', enabled = true) {
  return useQuery({
    queryKey: ['enterprise-patients', search],
    enabled,
    queryFn: async () => (await endpoints.enterprisePatients(search ? { search } : undefined)).data,
  });
}

export function useEnterpriseStaff(role = '', enabled = true) {
  return useQuery({
    queryKey: ['enterprise-staff', role],
    enabled,
    queryFn: async () => (await endpoints.enterpriseStaff(role ? { role } : undefined)).data,
  });
}

export function useEnterprisePharmacy(enabled = true, filters: Record<string, string> = {}) {
  return useQuery({
    queryKey: ['enterprise-pharmacy', filters],
    enabled,
    queryFn: async () => (await endpoints.enterprisePharmacy(filters)).data,
  });
}

export function usePharmacyMedicines(enabled = true) {
  return useQuery({
    queryKey: ['pharmacy-medicines'],
    enabled,
    queryFn: async () => (await endpoints.pharmacyMedicines()).data,
  });
}

export function usePharmacyOrders(status = '', enabled = true) {
  return useQuery({
    queryKey: ['pharmacy-orders', status],
    enabled,
    queryFn: async () => (await endpoints.pharmacyOrders(status ? { status } : undefined)).data,
  });
}

export function useEnterpriseEhr(enabled = true, patientId?: number) {
  return useQuery({
    queryKey: ['enterprise-ehr', patientId],
    enabled,
    queryFn: async () => (await endpoints.enterpriseEhr(patientId ? { patient_id: String(patientId) } : undefined)).data,
  });
}

export function useEnterpriseVitals(patientId?: number, enabled = true) {
  return useQuery({
    queryKey: ['enterprise-vitals', patientId],
    enabled,
    queryFn: async () => (await endpoints.enterpriseVitals(patientId ? { patient_id: String(patientId) } : undefined)).data,
  });
}

export function useEnterpriseLabTests(filters: Record<string, string> = {}, enabled = true) {
  return useQuery({
    queryKey: ['enterprise-lab-tests', filters],
    enabled,
    queryFn: async () => (await endpoints.enterpriseLabTests(filters)).data,
  });
}

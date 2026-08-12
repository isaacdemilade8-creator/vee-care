import axios from 'axios';
import toast from 'react-hot-toast';
import type { Paginated, PlatformRole, User } from '../../types';
import type { PlatformTenant } from '../tenant/types';
import type {
  PlatformApplication,
  PlatformApprovalResponse,
  PlatformAuditLog,
  PlatformInvitationResult,
  PlatformSummary,
  PlatformUser,
  PlatformUsersResponse,
} from './types';
import { getApiErrorMessage } from '../../utils/apiError';
import { resolveApiBaseUrl } from '../../services/apiBase';

/**
 * Platform (control plane) API client.
 *
 * Authentication runs against the control database with the dedicated
 * "platform" guard, so platform sessions are stored under their own keys and
 * never share tokens with tenant (hospital) sessions.
 */
export const PLATFORM_TOKEN_KEY = 'healthtech_platform_token';
export const PLATFORM_USER_KEY = 'healthtech_platform_user';

export const platformApi = axios.create({
  baseURL: resolveApiBaseUrl(),
  headers: { Accept: 'application/json' },
});

platformApi.interceptors.request.use((config) => {
  const token = localStorage.getItem(PLATFORM_TOKEN_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

platformApi.interceptors.response.use(
  (response) => response,
  (error) => {
    const message = getApiErrorMessage(error);
    if (error.response?.status !== 401) {
      toast.error(message);
    }
    return Promise.reject(error);
  },
);

export const platformEndpoints = {
  login: (payload: { email: string; password: string }) =>
    platformApi.post<{ user: User; token: string }>('/platform/auth/login', payload),
  me: () => platformApi.get<User>('/platform/me'),
  logout: () => platformApi.post('/platform/auth/logout'),
  summary: () => platformApi.get<PlatformSummary>('/platform/summary'),
  tenants: (params?: Record<string, string>) =>
    platformApi.get<Paginated<PlatformTenant>>('/platform/tenants', { params }),
  tenant: (id: number) => platformApi.get<{ data: PlatformTenant }>(`/platform/tenants/${id}`),
  updateTenant: (id: number, payload: { status?: string; name?: string; plan?: string }) =>
    platformApi.patch<{ data: PlatformTenant }>(`/platform/tenants/${id}`, payload),
  hospitalApplications: (params?: Record<string, string>) =>
    platformApi.get<Paginated<PlatformApplication>>('/platform/hospital-applications', { params }),
  hospitalApplication: (id: number) =>
    platformApi.get<{ data: PlatformApplication }>(`/platform/hospital-applications/${id}`),
  reviewApplication: (id: number) =>
    platformApi.patch<{ data: PlatformApplication }>(`/platform/hospital-applications/${id}`),
  approveApplication: (id: number) =>
    platformApi.post<PlatformApprovalResponse>(`/platform/hospital-applications/${id}/approve`),
  rejectApplication: (id: number, reason?: string) =>
    platformApi.post<{ data: PlatformApplication }>(`/platform/hospital-applications/${id}/reject`, {
      reason,
    }),
  auditLogs: (params?: Record<string, string>) =>
    platformApi.get<Paginated<PlatformAuditLog>>('/platform/audit-logs', { params }),
  users: (params?: Record<string, string>) =>
    platformApi.get<PlatformUsersResponse>('/platform/users', { params }),
  user: (id: number) => platformApi.get<{ data: PlatformUser }>(`/platform/users/${id}`),
  inviteUser: (payload: { name: string; email: string; role: PlatformRole }) =>
    platformApi.post<{ invitation: PlatformInvitationResult }>('/platform/users', payload),
  acceptInvitation: (token: string, payload: { name?: string; password: string; password_confirmation: string }) =>
    platformApi.post<{ message: string; user: PlatformUser }>(
      `/platform/users/invitations/${token}/accept`,
      payload,
    ),
  updateUser: (id: number, payload: { name?: string; email?: string; role?: PlatformRole }) =>
    platformApi.patch<{ data: PlatformUser }>(`/platform/users/${id}`, payload),
  activateUser: (id: number) => platformApi.post<{ data: PlatformUser }>(`/platform/users/${id}/activate`),
  deactivateUser: (id: number) => platformApi.post<{ data: PlatformUser }>(`/platform/users/${id}/deactivate`),
  revokeUserInvitation: (id: number) =>
    platformApi.delete<{ message: string }>(`/platform/users/invitations/${id}`),
};

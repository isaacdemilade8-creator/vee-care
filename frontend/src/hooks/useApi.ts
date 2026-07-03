import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import toast from 'react-hot-toast';
import { endpoints } from '../services/endpoints';
import { getEchoClient } from '../services/echo';
import type { CareNotification, Paginated, User } from '../types';

type Resource<T> = T | { data: T };

function unwrapResource<T>(value: Resource<T>): T {
  return value && typeof value === 'object' && 'data' in value ? value.data : value;
}

export function useAppointments(filters?: Record<string, string>, enabled = true) {
  return useQuery({
    queryKey: ['appointments', filters],
    enabled,
    queryFn: async () => (await endpoints.appointments(filters)).data,
  });
}

export function useDoctors(filters?: Record<string, string>) {
  return useQuery({ queryKey: ['doctors', filters], queryFn: async () => (await endpoints.doctors(filters)).data });
}

export function useProfiles(filters?: Record<string, string>) {
  return useQuery({ queryKey: ['profiles', filters], queryFn: async () => (await endpoints.profiles(filters)).data });
}

export function useProfile(id?: number) {
  return useQuery({
    queryKey: ['profile', id],
    enabled: Boolean(id),
    queryFn: async () => unwrapResource((await endpoints.profile(id as number)).data),
  });
}

export function useProfileReviews(id?: number) {
  return useQuery({
    queryKey: ['profile-reviews', id],
    enabled: Boolean(id),
    queryFn: async () => (await endpoints.profileReviews(id as number)).data,
  });
}

export function usePosts(filters?: Record<string, string>) {
  return useQuery({ queryKey: ['posts', filters], queryFn: async () => (await endpoints.posts(filters)).data });
}

export function usePrescriptions(enabled = true) {
  return useQuery({
    queryKey: ['prescriptions'],
    enabled,
    queryFn: async () => (await endpoints.prescriptions()).data,
  });
}

export function useUserPosts(userId?: number) {
  return useQuery({
    queryKey: ['posts', { user_id: userId ? String(userId) : '' }],
    enabled: Boolean(userId),
    queryFn: async () => (await endpoints.posts({ user_id: String(userId) })).data,
  });
}

export function useMedicalRecords(filters?: Record<string, string>, enabled = true) {
  return useQuery({
    queryKey: ['medical-records', filters],
    enabled,
    queryFn: async () => (await endpoints.medicalRecords(filters)).data,
  });
}

export function useChatContacts() {
  return useQuery({ queryKey: ['chat-contacts'], queryFn: async () => (await endpoints.contacts()).data });
}

export function useThread(userId?: number) {
  return useQuery({
    queryKey: ['thread', userId],
    enabled: Boolean(userId),
    queryFn: async () => (await endpoints.thread(userId as number)).data,
  });
}

export function useAdminAnalytics(enabled = true) {
  return useQuery({
    queryKey: ['admin-analytics'],
    enabled,
    queryFn: async () => (await endpoints.adminAnalytics()).data,
  });
}

export function useAdminUsers(filters?: Record<string, string>) {
  return useQuery({ queryKey: ['admin-users', filters], queryFn: async () => (await endpoints.adminUsers(filters)).data });
}

export function useNotifications() {
  return useQuery({
    queryKey: ['notifications'],
    queryFn: async () => (await endpoints.notifications()).data,
    refetchInterval: import.meta.env.VITE_PUSHER_APP_KEY ? false : 15000,
  });
}

export function useAuditLogs(filters?: Record<string, string>) {
  return useQuery({
    queryKey: ['audit-logs', filters],
    queryFn: async () => (await endpoints.auditLogs(filters)).data,
  });
}

export function useUrgentCareRequests(filters?: Record<string, string>) {
  return useQuery({
    queryKey: ['urgent-care-requests', filters],
    queryFn: async () => (await endpoints.urgentCareRequests(filters)).data,
  });
}

export function useRealtimeNotifications(user: User | null) {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!user) {
      return;
    }

    const echo = getEchoClient();

    if (!echo) {
      return;
    }

    const channelName = `users.${user.id}`;
    const channel = echo.private(channelName);

    channel.listen('.notification.created', (notification: CareNotification) => {
      queryClient.setQueryData<Paginated<CareNotification>>(['notifications'], (current) => {
        if (!current) {
          return { data: [notification] };
        }

        return {
          ...current,
          data: [notification, ...current.data.filter((item) => item.id !== notification.id)],
          meta: current.meta ? { ...current.meta, total: current.meta.total + 1 } : current.meta,
        };
      });

      toast(notification.title);
    });

    return () => {
      echo.leave(channelName);
    };
  }, [queryClient, user]);
}

export function useApiMutation<TPayload>(
  mutationFn: (payload: TPayload) => Promise<unknown>,
  invalidate: unknown[],
  successMessage: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: invalidate });
      toast.success(successMessage);
    },
  });
}

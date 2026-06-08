import { Bell } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Button } from './Button';
import { useApiMutation, useNotifications } from '../hooks/useApi';
import { endpoints } from '../services/endpoints';
import type { CareNotification, Paginated } from '../types';
import styles from './NotificationBell.module.scss';

export function NotificationBell() {
  const queryClient = useQueryClient();
  const notifications = useNotifications();
  const items = notifications.data?.data ?? [];
  const unreadCount = items.filter((item) => !item.readAt).length;
  const markRead = useApiMutation((id: number) => endpoints.markNotificationRead(id), ['notifications'], 'Notification marked read');
  const markAllRead = useApiMutation(() => endpoints.markAllNotificationsRead(), ['notifications'], 'Notifications cleared');

  const handleMarkRead = (id: number) => {
    queryClient.setQueryData<Paginated<CareNotification>>(['notifications'], (current) => {
      if (!current) {
        return current;
      }

      return {
        ...current,
        data: current.data.map((item) => item.id === id ? { ...item, readAt: new Date().toISOString() } : item),
      };
    });
    markRead.mutate(id);
  };

  return (
    <details className={styles.wrap}>
      <summary aria-label="Notifications">
        <Bell size={20} />
        {unreadCount ? <span>{unreadCount}</span> : null}
      </summary>
      <div className={styles.panel}>
        <header>
          <strong>Notifications</strong>
          {unreadCount ? <button onClick={() => markAllRead.mutate(undefined)}>Mark all read</button> : null}
        </header>
        <div className={styles.list}>
          {items.length ? items.slice(0, 8).map((item) => (
            <article key={item.id} className={!item.readAt ? styles.unread : undefined}>
              <strong>{item.title}</strong>
              <p>{item.body}</p>
              {!item.readAt ? <Button variant="ghost" onClick={() => handleMarkRead(item.id)}>Mark read</Button> : null}
            </article>
          )) : <p className={styles.empty}>No notifications yet.</p>}
        </div>
      </div>
    </details>
  );
}

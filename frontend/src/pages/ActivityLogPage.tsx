import { useState, useCallback, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import type { Variants } from 'framer-motion';
import { Activity, CalendarDays, ChevronLeft, ChevronRight, LogIn, LogOut, MessageCircle, Pill, ShieldCheck, Stethoscope, Upload, UserCog, UserPlus, Users } from 'lucide-react';
import { Card } from '../components/Card';
import { SelectField } from '../components/FormField';
import { useAuth } from '../context/AuthContext';
import { endpoints } from '../services/endpoints';
import type { AuditLog } from '../types';
import styles from './ActivityLogPage.module.scss';

const actionMeta: Record<string, { label: string; icon: typeof Activity; color: string }> = {
  'auth.login': { label: 'Logged in', icon: LogIn, color: '#059669' },
  'auth.logout': { label: 'Logged out', icon: LogOut, color: '#d97706' },
  'auth.register': { label: 'Registered account', icon: UserPlus, color: '#0f766e' },
  'appointment.created': { label: 'Booked appointment', icon: CalendarDays, color: '#3b82f6' },
  'appointment.approved': { label: 'Approved appointment', icon: CalendarDays, color: '#059669' },
  'appointment.rejected': { label: 'Rejected appointment', icon: CalendarDays, color: '#dc2626' },
  'appointment.completed': { label: 'Completed appointment', icon: CalendarDays, color: '#6366f1' },
  'appointment.updated': { label: 'Updated appointment', icon: CalendarDays, color: '#6366f1' },
  'message.sent': { label: 'Sent message', icon: MessageCircle, color: '#8b5cf6' },
  'medical_record.created': { label: 'Uploaded medical record', icon: Upload, color: '#0f766e' },
  'prescription.created': { label: 'Issued prescription', icon: Pill, color: '#0891b2' },
  'admin.user_created': { label: 'Created user account', icon: UserPlus, color: '#d97706' },
  'admin.user_updated': { label: 'Updated user', icon: UserCog, color: '#d97706' },
  'admin.user_deleted': { label: 'Deleted user', icon: Users, color: '#dc2626' },
  'profile.updated': { label: 'Updated profile', icon: UserCog, color: '#6366f1' },
  'post.created': { label: 'Published blog post', icon: Activity, color: '#0891b2' },
  'post.updated': { label: 'Updated blog post', icon: Activity, color: '#0891b2' },
  'post.deleted': { label: 'Deleted blog post', icon: Activity, color: '#dc2626' },
  'post.comment': { label: 'Commented on post', icon: MessageCircle, color: '#6366f1' },
  'urgent_care.created': { label: 'Requested urgent care', icon: Stethoscope, color: '#dc2626' },
  'urgent_care.updated': { label: 'Updated urgent care', icon: Stethoscope, color: '#d97706' },
  'review.created': { label: 'Left a review', icon: Activity, color: '#f59e0b' },
  'pharmacy_request.created': { label: 'Created pharmacy request', icon: Pill, color: '#0891b2' },
  'lab.requested': { label: 'Requested lab test', icon: Stethoscope, color: '#0f766e' },
  'lab.result_updated': { label: 'Updated lab result', icon: Stethoscope, color: '#3b82f6' },
  'medicine.created': { label: 'Added medicine', icon: Pill, color: '#059669' },
  'medicine.updated': { label: 'Updated medicine', icon: Pill, color: '#d97706' },
  'medicine.deleted': { label: 'Removed medicine', icon: Pill, color: '#dc2626' },
  'medicine.stock_adjusted': { label: 'Adjusted medicine stock', icon: Pill, color: '#d97706' },
  'ehr.created': { label: 'Created clinical note', icon: Upload, color: '#0f766e' },
  'vitals.recorded': { label: 'Recorded vitals', icon: Activity, color: '#3b82f6' },
  'staff.registered': { label: 'Registered staff', icon: UserPlus, color: '#0f766e' },
  'patient.emergency_requested': { label: 'Sent emergency request', icon: Stethoscope, color: '#dc2626' },
  'pharmacy_request.item_dispensed': { label: 'Dispensed medication', icon: Pill, color: '#059669' },
  'pharmacy_request.item_given': { label: 'Gave medication to patient', icon: Pill, color: '#059669' },
  'pharmacy_request.completed': { label: 'Completed pharmacy review', icon: ShieldCheck, color: '#059669' },
};

const roleBadgeColors: Record<string, { bg: string; text: string }> = {
  admin: { bg: '#e0f2fe', text: '#0369a1' },
  super_admin: { bg: '#fef3c7', text: '#b45309' },
  doctor: { bg: '#dcfce7', text: '#15803d' },
  nurse: { bg: '#fce7f3', text: '#be185d' },
  patient: { bg: '#e0e7ff', text: '#4338ca' },
  lab_technician: { bg: '#f3e8ff', text: '#7e22ce' },
  pharmacist: { bg: '#fff1f2', text: '#be123c' },
};

const uniqueActions = Object.keys(actionMeta);

function formatTimeAgo(iso: string) {
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  if (days < 30) return `${days}d ago`;
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function getDateLabel(iso: string): string {
  const date = new Date(iso);
  const today = new Date();
  const yesterday = new Date(today);
  yesterday.setDate(yesterday.getDate() - 1);

  if (date.toDateString() === today.toDateString()) return 'Today';
  if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';

  return date.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
}

function groupByDate(logs: AuditLog[]): Map<string, AuditLog[]> {
  const groups = new Map<string, AuditLog[]>();
  for (const log of logs) {
    const label = getDateLabel(log.createdAt);
    const arr = groups.get(label) ?? [];
    arr.push(log);
    groups.set(label, arr);
  }
  return groups;
}

function getPageNumbers(current: number, total: number): (number | null)[] {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

  const pages: (number | null)[] = [];

  if (current <= 4) {
    for (let i = 1; i <= Math.min(5, total); i++) pages.push(i);
    if (total > 5) { pages.push(null); pages.push(total); }
  } else if (current >= total - 3) {
    pages.push(1);
    if (total > 5) pages.push(null);
    for (let i = Math.max(total - 4, 2); i <= total; i++) pages.push(i);
  } else {
    pages.push(1);
    pages.push(null);
    pages.push(current - 1);
    pages.push(current);
    pages.push(current + 1);
    pages.push(null);
    pages.push(total);
  }

  return pages;
}

const containerMotion: Variants = {
  hidden: { opacity: 0 },
  show: { opacity: 1, transition: { staggerChildren: 0.04 } },
};

const itemMotion: Variants = {
  hidden: { opacity: 0, y: 10 },
  show: { opacity: 1, y: 0, transition: { duration: 0.25, ease: 'easeOut' } },
};

export function ActivityLogPage() {
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin' || user?.role === 'super_admin';
  const [page, setPage] = useState(1);
  const [actionFilter, setActionFilter] = useState('');
  const [userIdFilter, setUserIdFilter] = useState('');

  const onFilterChange = useCallback((setter: typeof setActionFilter | typeof setUserIdFilter) =>
    (e: React.ChangeEvent<HTMLSelectElement>) => {
      setter(e.target.value);
      setPage(1);
    }, []);

  const params: Record<string, string> = { page: String(page), per_page: '25' };
  if (actionFilter) params.action = actionFilter;
  if (isAdmin && userIdFilter) params.user_id = userIdFilter;

  const { data, isLoading } = useQuery({
    queryKey: ['audit-logs', params],
    queryFn: async () => (await endpoints.auditLogs(params)).data,
  });

  const { data: allUsers } = useQuery({
    queryKey: ['admin-users', { per_page: '200' }],
    queryFn: async () => (await endpoints.adminUsers({ per_page: '200' })).data,
    enabled: isAdmin,
  });

  const logs = data?.data ?? [];
  const userOptions = allUsers?.data ?? [];
  const totalPages = data?.meta?.last_page ?? 1;
  const currentPage = data?.meta?.current_page ?? 1;
  const totalEntries = data?.meta?.total ?? 0;
  const visiblePages = getPageNumbers(currentPage, totalPages);

  const groupedLogs = useMemo(() => groupByDate(logs), [logs]);

  return (
    <motion.div className={styles.page} variants={containerMotion} initial="hidden" animate="show">
      <motion.div className={styles.header} variants={itemMotion}>
        <div>
          <span>Audit trail</span>
          <h2>Activity Log</h2>
          <p>Track every action performed across the platform.</p>
        </div>
        <div className={styles.headerFilters}>
          <SelectField label="Action" name="action" value={actionFilter} onChange={onFilterChange(setActionFilter)}>
            <option value="">All actions</option>
            {uniqueActions.map((action) => (
              <option key={action} value={action}>{actionMeta[action]?.label ?? action}</option>
            ))}
          </SelectField>
          {isAdmin ? (
            <SelectField label="User" name="user_id" value={userIdFilter} onChange={onFilterChange(setUserIdFilter)}>
              <option value="">All users</option>
              {userOptions.map((u) => (
                <option key={u.id} value={String(u.id)}>{u.name} ({u.role.replace('_', ' ')})</option>
              ))}
            </SelectField>
          ) : null}
        </div>
      </motion.div>

      <Card>
        {isLoading ? (
          <div className={styles.loading}>
            <div className={styles.spinner} />
            Loading activity...
          </div>
        ) : logs.length === 0 ? (
          <div className={styles.empty}>
            <div className={styles.emptyIcon}>
              <Activity size={40} />
            </div>
            <h3>No activity recorded</h3>
            <p>Actions performed across the platform will appear here.</p>
          </div>
        ) : (
          <div className={styles.timeline}>
            {Array.from(groupedLogs.entries()).map(([dateLabel, dateLogs]) => (
              <div key={dateLabel} className={styles.dateGroup}>
                <div className={styles.dateSticky}>
                  <span className={styles.dateLabel}>{dateLabel}</span>
                  <span className={styles.dateCount}>{dateLogs.length} event{dateLogs.length !== 1 ? 's' : ''}</span>
                </div>
                {dateLogs.map((log) => {
                  const meta = actionMeta[log.action] ?? { label: log.action.replace(/_/g, ' '), icon: Activity, color: '#5b7083' };
                  const Icon = meta.icon;
                  const roleColor = log.user?.role ? roleBadgeColors[log.user.role] : null;
                  return (
                    <motion.article key={log.id} className={styles.entry} variants={itemMotion} layout>
                      <div className={styles.entryLine}>
                        <div className={styles.entryDot} style={{ background: meta.color }} />
                        <div className={styles.entryLineTrack} />
                      </div>
                      <div className={styles.entryCard}>
                        <div className={styles.entryIcon} style={{ background: `color-mix(in srgb, ${meta.color} 14%, transparent)`, color: meta.color }}>
                          <Icon size={16} />
                        </div>
                        <div className={styles.entryBody}>
                          <div className={styles.entryHead}>
                            <strong>{meta.label}</strong>
                            <time>{formatTimeAgo(log.createdAt)}</time>
                          </div>
                          <div className={styles.entryMeta}>
                            {log.user ? <span>{log.user.name}</span> : null}
                            {log.user?.role ? (
                              <span
                                className={styles.badge}
                                style={roleColor ? { background: roleColor.bg, color: roleColor.text } : undefined}
                              >
                                {log.user.role.replace('_', ' ')}
                              </span>
                            ) : null}
                          </div>
                          {log.metadata ? (
                            <div className={styles.entryDetails}>
                              {Object.entries(log.metadata).filter(([, v]) => v !== null && v !== undefined).map(([key, value]) => (
                                <span key={key} className={styles.detail}>
                                  <span className={styles.detailKey}>{String(key)}</span>
                                  <span className={styles.detailValue}>{String(value)}</span>
                                </span>
                              ))}
                            </div>
                          ) : null}
                          <div className={styles.entryFooter}>
                            <span className={styles.timestamp}>
                              {new Date(log.createdAt).toLocaleString(undefined, {
                                month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
                              })}
                            </span>
                            {log.ipAddress ? <span className={styles.ip}>IP: {log.ipAddress}</span> : null}
                          </div>
                        </div>
                      </div>
                    </motion.article>
                  );
                })}
              </div>
            ))}
          </div>
        )}
      </Card>

      {totalPages > 1 && logs.length > 0 ? (
        <motion.div className={styles.pagination} variants={itemMotion}>
          <div className={styles.pages}>
            <button
              className={`${styles.pageArrow} ${currentPage <= 1 ? styles.pageArrowDisabled : ''}`}
              disabled={currentPage <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              aria-label="Previous page"
            >
              <ChevronLeft size={16} />
            </button>

            {visiblePages.map((p, i) =>
              p === null ? (
                <span key={`e-${i}`} className={styles.ellipsis}>&hellip;</span>
              ) : (
                <button
                  key={p}
                  className={`${styles.pageNum} ${p === currentPage ? styles.pageNumActive : ''}`}
                  onClick={() => setPage(p)}
                  aria-label={`Page ${p}`}
                  aria-current={p === currentPage ? 'page' : undefined}
                >
                  {p}
                </button>
              )
            )}

            <button
              className={`${styles.pageArrow} ${currentPage >= totalPages ? styles.pageArrowDisabled : ''}`}
              disabled={currentPage >= totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              aria-label="Next page"
            >
              <ChevronRight size={16} />
            </button>
          </div>
          <span className={styles.pageInfo}>{totalEntries} entry{totalEntries !== 1 ? 'ies' : 'y'}</span>
        </motion.div>
      ) : null}
    </motion.div>
  );
}

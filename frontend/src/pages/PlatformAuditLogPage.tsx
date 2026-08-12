import { useQuery } from '@tanstack/react-query';
import { ScrollText } from 'lucide-react';
import { useState } from 'react';
import { canAccessPlatform, platformRouteRoles } from '../auth/roleAccess';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { DataStatePanel } from '../components/DataStatePanel';
import { Pagination } from '../components/Pagination';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { platformEndpoints } from '../lib/platform/api';
import { AUDIT_EVENTS, auditEventLabel } from '../lib/platform/status';
import { formatDate } from '../utils/format';
import styles from './PlatformAuditLogPage.module.scss';

/**
 * Read-only platform audit trail. Consumes the existing control-plane audit-log
 * endpoint (safe projections only — the backend never emits secrets here).
 * Event filtering and pagination run server-side.
 */
export function PlatformAuditLogPage() {
  const { platformUser } = useAuth();
  const [event, setEvent] = useState('');
  const [page, setPage] = useState(1);

  const params: Record<string, string> = { page: String(page), per_page: '25' };
  if (event) params.event = event;

  const logs = useQuery({
    queryKey: ['platform', 'audit-logs', params],
    queryFn: async () => (await platformEndpoints.auditLogs(params)).data,
    enabled: canAccessPlatform(platformUser?.role, platformRouteRoles.auditLogs),
  });

  const rows = logs.data?.data ?? [];
  const total = logs.data?.meta?.total ?? 0;
  const totalPages = logs.data?.meta?.last_page ?? 1;
  const currentPage = logs.data?.meta?.current_page ?? 1;

  const onEventChange = (value: string) => {
    setEvent(value);
    setPage(1);
  };

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Platform administration</span>
          <h1>Audit logs</h1>
          <p>Actions taken by platform administrators across hospital onboarding.</p>
        </div>
      </header>

      <div className={styles.filters}>
        <select
          className={styles.select}
          value={event}
          onChange={(eventChange) => onEventChange(eventChange.target.value)}
          aria-label="Filter by event"
        >
          <option value="">All events</option>
          {AUDIT_EVENTS.map((entry) => (
            <option key={entry.value} value={entry.value}>{entry.label}</option>
          ))}
        </select>
      </div>

      {logs.isLoading ? (
        <Card><SkeletonRows rows={7} /></Card>
      ) : logs.isError ? (
        <DataStatePanel
          title="Unable to load audit logs"
          description="The audit trail could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void logs.refetch()}>Try again</Button>}
        />
      ) : rows.length === 0 ? (
        <DataStatePanel
          title={event ? 'No events of this type' : 'No audit activity yet'}
          description={event ? 'Try a different event filter.' : 'Platform actions appear here as administrators review applications and manage hospitals.'}
        />
      ) : (
        <Card className={styles.tableCard}>
          <div className={styles.table}>
            <div className={styles.tableHead} role="row">
              <span role="columnheader">Event</span>
              <span role="columnheader">Actor</span>
              <span role="columnheader">Hospital / application</span>
              <span role="columnheader">Timestamp</span>
              <span role="columnheader">IP address</span>
            </div>
            {rows.map((log) => (
              <div className={styles.row} role="row" key={log.id}>
                <span role="cell">
                  <span className={styles.eventName}>
                    <ScrollText size={14} />
                    {auditEventLabel(log.event)}
                  </span>
                </span>
                <span role="cell">
                  {log.actor ? (
                    <span className={styles.actor}>
                      <strong>{log.actor.name}</strong>
                      <span>{log.actor.email}</span>
                    </span>
                  ) : (
                    <span className={styles.system}>System</span>
                  )}
                </span>
                <span role="cell">
                  {log.tenant ? (
                    <span className={styles.ref}>{log.tenant.name}</span>
                  ) : log.application ? (
                    <span className={styles.ref}>{log.application.hospitalName}</span>
                  ) : (
                    <span className={styles.muted}>—</span>
                  )}
                </span>
                <span role="cell" className={styles.date}>
                  {log.createdAt ? formatDateTime(log.createdAt) : '—'}
                </span>
                <span role="cell" className={styles.mono}>
                  {log.ipAddress ?? '—'}
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {!logs.isLoading && !logs.isError && rows.length > 0 ? (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          total={total}
          onPageChange={setPage}
          itemLabel="events"
        />
      ) : null}
    </div>
  );
}

function formatDateTime(value: string): string {
  const date = new Date(value);
  return `${formatDate(value)} · ${date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}`;
}

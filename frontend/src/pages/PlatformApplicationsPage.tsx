import { useQuery } from '@tanstack/react-query';
import { Eye } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { canAccessPlatform, platformRouteRoles } from '../auth/roleAccess';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { DataStatePanel } from '../components/DataStatePanel';
import { Pagination } from '../components/Pagination';
import { SkeletonRows } from '../components/Skeleton';
import { StatusPill } from '../components/StatusPill';
import { useAuth } from '../context/AuthContext';
import { platformEndpoints } from '../lib/platform/api';
import { formatDate } from '../utils/format';
import styles from './PlatformApplicationsPage.module.scss';

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'under_review', label: 'Under review' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
];

/**
 * Hospital applications queue. Lists the real applications submitted to the
 * platform and links into the review flow for unprocessed records.
 */
export function PlatformApplicationsPage() {
  const { platformUser } = useAuth();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const params: Record<string, string> = { page: String(page), per_page: '10' };
  if (status) params.status = status;

  const applications = useQuery({
    queryKey: ['platform', 'applications', params],
    queryFn: async () => (await platformEndpoints.hospitalApplications(params)).data,
    enabled: canAccessPlatform(platformUser?.role, platformRouteRoles.hospitalApplications),
  });

  const rows = applications.data?.data ?? [];
  const total = applications.data?.meta?.total ?? 0;
  const totalPages = applications.data?.meta?.last_page ?? 1;
  const currentPage = applications.data?.meta?.current_page ?? 1;

  const onStatusChange = (value: string) => {
    setStatus(value);
    setPage(1);
  };

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Platform administration</span>
          <h1>Hospital applications</h1>
          <p>Review hospitals that have applied to join Vee-Care.</p>
        </div>
      </header>

      <div className={styles.filters}>
        <select
          className={styles.select}
          value={status}
          onChange={(event) => onStatusChange(event.target.value)}
          aria-label="Filter by status"
        >
          {STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </div>

      {applications.isLoading ? (
        <Card><SkeletonRows rows={6} /></Card>
      ) : applications.isError ? (
        <DataStatePanel
          title="Unable to load applications"
          description="The application queue could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void applications.refetch()}>Try again</Button>}
        />
      ) : rows.length === 0 ? (
        <DataStatePanel
          title={status ? 'No applications with this status' : 'No applications yet'}
          description={status ? 'Try a different status filter.' : 'Public hospital applications appear here as soon as they are submitted.'}
        />
      ) : (
        <Card className={styles.tableCard}>
          <div className={styles.table}>
            <div className={styles.tableHead} role="row">
              <span role="columnheader">Hospital</span>
              <span role="columnheader">Applicant</span>
              <span role="columnheader">Email</span>
              <span role="columnheader">Submitted</span>
              <span role="columnheader">Status</span>
              <span role="columnheader" className={styles.actionsCol}>Actions</span>
            </div>
            {rows.map((application) => {
              const actionable = application.status === 'pending' || application.status === 'under_review';
              return (
                <div className={styles.row} role="row" key={application.id}>
                  <span role="cell">
                    <Link to={`/platform/applications/${application.id}`} className={styles.name}>
                      {application.hospitalName}
                    </Link>
                  </span>
                  <span role="cell">{application.contactName}</span>
                  <span role="cell" className={styles.email}>{application.contactEmail}</span>
                  <span role="cell" className={styles.date}>{formatDate(application.createdAt)}</span>
                  <span role="cell">
                    <StatusPill status={application.status} kind="application" />
                  </span>
                  <span role="cell" className={styles.actionsCol}>
                    <Link to={`/platform/applications/${application.id}`} className={styles.view}>
                      <Eye size={15} />
                      {actionable ? 'Review' : 'View'}
                    </Link>
                  </span>
                </div>
              );
            })}
          </div>
        </Card>
      )}

      {!applications.isLoading && !applications.isError && rows.length > 0 ? (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          total={total}
          onPageChange={setPage}
          itemLabel="applications"
        />
      ) : null}
    </div>
  );
}

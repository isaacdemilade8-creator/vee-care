import { useQuery } from '@tanstack/react-query';
import { Eye, Search } from 'lucide-react';
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
import { getPlatformDomain } from '../lib/tenant/hostname';
import type { TenantStatus } from '../lib/tenant/types';
import { formatDate } from '../utils/format';
import styles from './PlatformHospitalsPage.module.scss';

const STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'active', label: 'Active' },
  { value: 'provisioning', label: 'Provisioning' },
  { value: 'pending', label: 'Pending' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'failed', label: 'Failed' },
];

/**
 * Platform hospital list: server-side search, status filtering and
 * pagination over the real tenant registry. Never renders database details.
 */
export function PlatformHospitalsPage() {
  const { platformUser } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const params: Record<string, string> = { page: String(page), per_page: '10' };
  if (search.trim()) params.search = search.trim();
  if (status) params.status = status;

  const tenants = useQuery({
    queryKey: ['platform', 'tenants', params],
    queryFn: async () => (await platformEndpoints.tenants(params)).data,
    enabled: canAccessPlatform(platformUser?.role, platformRouteRoles.tenants),
  });

  const rows = tenants.data?.data ?? [];
  const total = tenants.data?.meta?.total ?? 0;
  const totalPages = tenants.data?.meta?.last_page ?? 1;
  const currentPage = tenants.data?.meta?.current_page ?? 1;
  const platformDomain = getPlatformDomain();

  const onSearchChange = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const onStatusChange = (value: string) => {
    setStatus(value);
    setPage(1);
  };

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Platform administration</span>
          <h1>Hospitals</h1>
          <p>Every hospital on the Vee-Care platform and its lifecycle status.</p>
        </div>
      </header>

      <div className={styles.filters}>
        <div className={styles.search}>
          <Search size={16} />
          <input
            type="search"
            placeholder="Search hospitals…"
            value={search}
            onChange={(event) => onSearchChange(event.target.value)}
            aria-label="Search hospitals"
          />
        </div>
        <select
          className={styles.select}
          value={status}
          onChange={(event) => onStatusChange(event.target.value)}
          aria-label="Filter by status"
        >
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </div>

      {tenants.isLoading ? (
        <Card><SkeletonRows rows={6} /></Card>
      ) : tenants.isError ? (
        <DataStatePanel
          title="Unable to load hospitals"
          description="The hospital registry could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void tenants.refetch()}>Try again</Button>}
        />
      ) : rows.length === 0 ? (
        <DataStatePanel
          title={search || status ? 'No hospitals match your filters' : 'No hospitals yet'}
          description={search || status ? 'Try adjusting the search or status filter.' : 'Approved hospital applications appear here once provisioned.'}
        />
      ) : (
        <Card className={styles.tableCard}>
          <div className={styles.table}>
            <div className={styles.tableHead} role="row">
              <span role="columnheader">Hospital</span>
              <span role="columnheader">Subdomain</span>
              <span role="columnheader">Status</span>
              <span role="columnheader">Created</span>
              <span role="columnheader" className={styles.actionsCol}>Actions</span>
            </div>
            {rows.map((tenant) => (
              <div className={styles.row} role="row" key={tenant.id}>
                <span role="cell">
                  <Link to={`/platform/hospitals/${tenant.id}`} className={styles.name}>
                    {tenant.name}
                  </Link>
                </span>
                <span role="cell" className={styles.mono}>
                  {tenant.slug}.{platformDomain}
                </span>
                <span role="cell">
                  <StatusPill status={tenant.status as TenantStatus} kind="tenant" />
                </span>
                <span role="cell" className={styles.date}>
                  {tenant.created_at ? formatDate(tenant.created_at) : '—'}
                </span>
                <span role="cell" className={styles.actionsCol}>
                  <Link to={`/platform/hospitals/${tenant.id}`} className={styles.view}>
                    <Eye size={15} />
                    View
                  </Link>
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {!tenants.isLoading && !tenants.isError && rows.length > 0 ? (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          total={total}
          onPageChange={setPage}
          itemLabel="hospitals"
        />
      ) : null}
    </div>
  );
}

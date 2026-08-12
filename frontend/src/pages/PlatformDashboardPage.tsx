import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, ArrowRight, Building2, ClipboardList, ShieldCheck } from 'lucide-react';
import { Link } from 'react-router-dom';
import { canAccessPlatform, platformRouteRoles } from '../auth/roleAccess';
import { Button } from '../components/Button';
import { Card, StatCard } from '../components/Card';
import { DataStatePanel } from '../components/DataStatePanel';
import { SkeletonRows } from '../components/Skeleton';
import { StatusPill } from '../components/StatusPill';
import { useAuth } from '../context/AuthContext';
import { platformEndpoints } from '../lib/platform/api';
import { getPlatformDomain } from '../lib/tenant/hostname';
import { formatDate } from '../utils/format';
import styles from './PlatformDashboardPage.module.scss';

/**
 * Platform dashboard: real platform statistics plus the most recent hospital
 * applications and hospitals. Everything comes from a single summary request;
 * no per-hospital round trips.
 */
export function PlatformDashboardPage() {
  const { platformUser } = useAuth();

  const summary = useQuery({
    queryKey: ['platform', 'summary'],
    queryFn: async () => (await platformEndpoints.summary()).data,
    enabled: canAccessPlatform(platformUser?.role, platformRouteRoles.overview),
  });

  const hospitals = summary.data?.hospitals;
  const applications = summary.data?.applications;
  const recentApplications = summary.data?.recentApplications ?? [];
  const recentTenants = summary.data?.recentTenants ?? [];
  const platformDomain = getPlatformDomain();

  const isEmpty =
    summary.data &&
    (hospitals?.total ?? 0) === 0 &&
    (applications?.total ?? 0) === 0;

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Platform administration</span>
          <h1>Dashboard</h1>
          <p>Overview of the hospitals and applications on Vee-Care.</p>
        </div>
      </header>

      {summary.isLoading ? (
        <div className={styles.skeletons}>
          <div className={styles.cardGrid}>
            {[0, 1, 2, 3].map((i) => (
              <Card key={i}><div className={styles.cardSkeleton} /></Card>
            ))}
          </div>
          <Card><SkeletonRows rows={4} /></Card>
        </div>
      ) : summary.isError ? (
        <DataStatePanel
          title="Unable to load the dashboard"
          description="The platform could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void summary.refetch()}>Try again</Button>}
        />
      ) : isEmpty ? (
        <DataStatePanel
          title="No hospitals or applications yet"
          description="Hospitals that apply to join Vee-Care will appear here once applications start coming in."
        />
      ) : (
        <>
          <section className={styles.cardGrid} aria-label="Platform statistics">
            <StatCard label="Total hospitals" value={hospitals?.total ?? 0} icon={Building2} />
            <StatCard label="Active hospitals" value={hospitals?.active ?? 0} icon={ShieldCheck} />
            <StatCard label="Pending applications" value={applications?.pending ?? 0} icon={ClipboardList} />
            <StatCard label="Suspended hospitals" value={hospitals?.suspended ?? 0} icon={AlertTriangle} />
          </section>

          <section className={styles.section}>
            <div className={styles.sectionHead}>
              <div>
                <span>Recent activity</span>
                <h2>Hospital applications</h2>
              </div>
              <Link to="/platform/applications" className={styles.more}>
                View all <ArrowRight size={15} />
              </Link>
            </div>
            <Card>
              {recentApplications.length === 0 ? (
                <div className={styles.empty}>No applications yet.</div>
              ) : (
                <div className={styles.list}>
                  {recentApplications.map((application) => (
                    <Link
                      key={application.id}
                      to={`/platform/applications/${application.id}`}
                      className={styles.listRow}
                    >
                      <span className={styles.listMain}>
                        <strong>{application.hospitalName}</strong>
                        <span className={styles.listSub}>{application.contactName}</span>
                      </span>
                      <span className={styles.listDate}>{formatDate(application.createdAt)}</span>
                      <StatusPill status={application.status} kind="application" />
                    </Link>
                  ))}
                </div>
              )}
            </Card>
          </section>

          <section className={styles.section}>
            <div className={styles.sectionHead}>
              <div>
                <span>Active hospitals</span>
                <h2>Recently created hospitals</h2>
              </div>
              <Link to="/platform/hospitals" className={styles.more}>
                View all <ArrowRight size={15} />
              </Link>
            </div>
            <Card>
              {recentTenants.length === 0 ? (
                <div className={styles.empty}>No hospitals yet.</div>
              ) : (
                <div className={styles.list}>
                  {recentTenants.map((tenant) => (
                    <Link key={tenant.id} to={`/platform/hospitals/${tenant.id}`} className={styles.listRow}>
                      <span className={styles.listMain}>
                        <strong>{tenant.name}</strong>
                        <span className={styles.listSub}>
                          {tenant.slug}.{platformDomain}
                        </span>
                      </span>
                      <span className={styles.listDate}>
                        {tenant.created_at ? formatDate(tenant.created_at) : ''}
                      </span>
                      <StatusPill status={tenant.status} kind="tenant" />
                    </Link>
                  ))}
                </div>
              )}
            </Card>
          </section>
        </>
      )}
    </div>
  );
}

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Building2, CheckCircle2, PauseCircle } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { canAccessPlatform, platformRouteRoles } from '../auth/roleAccess';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { DataStatePanel } from '../components/DataStatePanel';
import { Modal } from '../components/Modal';
import { SkeletonRows } from '../components/Skeleton';
import { StatusPill } from '../components/StatusPill';
import { useAuth } from '../context/AuthContext';
import { platformEndpoints } from '../lib/platform/api';
import { getPlatformDomain } from '../lib/tenant/hostname';
import type { TenantStatus } from '../lib/tenant/types';
import { formatDateLong } from '../utils/format';
import styles from './PlatformHospitalDetailPage.module.scss';

/**
 * Hospital detail: safe overview of a single tenant plus lifecycle actions
 * (activate / suspend) wired to the existing tenant-update API. Database
 * details returned by the API are deliberately never rendered here.
 */
export function PlatformHospitalDetailPage() {
  const { id } = useParams();
  const tenantId = Number(id);
  const { platformUser } = useAuth();
  const queryClient = useQueryClient();
  const [confirmAction, setConfirmAction] = useState<'activate' | 'suspend' | null>(null);

  const tenant = useQuery({
    queryKey: ['platform', 'tenants', tenantId],
    queryFn: async () => (await platformEndpoints.tenant(tenantId)).data,
    enabled: Number.isFinite(tenantId) && canAccessPlatform(platformUser?.role, platformRouteRoles.tenants),
  });

  const hospital = tenant.data?.data;

  const updateStatus = useMutation({
    mutationFn: async (status: 'active' | 'suspended') => {
      if (!Number.isFinite(tenantId)) {
        throw new Error('Invalid hospital id.');
      }
      await platformEndpoints.updateTenant(tenantId, { status });
    },
    onSuccess: () => {
      toast.success('Hospital status updated.');
      setConfirmAction(null);
      void queryClient.invalidateQueries({ queryKey: ['platform', 'tenants'] });
      void queryClient.invalidateQueries({ queryKey: ['platform', 'summary'] });
    },
  });

  const primaryDomain = hospital?.domains.find((domain) => domain.is_primary)?.domain;
  const subdomain = primaryDomain ?? (hospital ? `${hospital.slug}.${getPlatformDomain()}` : '—');
  const settings = hospital?.settings;

  if (tenant.isLoading) {
    return (
      <div className={styles.page}>
        <BackLink />
        <Card><SkeletonRows rows={5} /></Card>
      </div>
    );
  }

  if (tenant.isError || !hospital) {
    return (
      <div className={styles.page}>
        <BackLink />
        <DataStatePanel
          title="Unable to load hospital"
          description="This hospital could not be found or the platform could not be reached."
          action={
            <Button variant="secondary" onClick={() => void tenant.refetch()}>Try again</Button>
          }
        />
      </div>
    );
  }

  const isActive = hospital.status === 'active';
  const isSuspended = hospital.status === 'suspended';

  return (
    <div className={styles.page}>
      <BackLink />

      <header className={styles.heading}>
        <div className={styles.titleRow}>
          <div className={styles.titleIcon}><Building2 size={20} /></div>
          <div>
            <span>Platform administration</span>
            <h1>{hospital.name}</h1>
            <p className={styles.subdomain}>{subdomain}</p>
          </div>
        </div>
        <div className={styles.headingActions}>
          <StatusPill status={hospital.status as TenantStatus} kind="tenant" />
          {isSuspended ? (
            <Button variant="primary" onClick={() => setConfirmAction('activate')} disabled={updateStatus.isPending}>
              <CheckCircle2 size={16} /> Activate hospital
            </Button>
          ) : isActive ? (
            <Button variant="secondary" onClick={() => setConfirmAction('suspend')} disabled={updateStatus.isPending}>
              <PauseCircle size={16} /> Suspend hospital
            </Button>
          ) : null}
        </div>
      </header>

      <div className={styles.grid}>
        <Card>
          <h2 className={styles.cardTitle}>Overview</h2>
          <dl className={styles.info}>
            <InfoRow label="Type" value={capitalize(hospital.type)} />
            <InfoRow label="Plan" value={capitalize(hospital.plan)} />
            <InfoRow label="Currency" value={hospital.currency} />
            <InfoRow label="Status" value={statusLabel(hospital.status)} />
            <InfoRow label="Created" value={hospital.created_at ? formatDateLong(hospital.created_at) : '—'} />
          </dl>
        </Card>

        <Card>
          <h2 className={styles.cardTitle}>Domains</h2>
          {hospital.domains.length === 0 ? (
            <p className={styles.muted}>No custom domains registered.</p>
          ) : (
            <ul className={styles.domains}>
              {hospital.domains.map((domain) => (
                <li key={domain.id} className={styles.domainRow}>
                  <span className={styles.mono}>{domain.domain}</span>
                  {domain.is_primary ? <span className={styles.primaryBadge}>Primary</span> : null}
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card className={styles.configCard}>
          <h2 className={styles.cardTitle}>Configuration</h2>
          {settings && Object.keys(settings).length > 0 ? (
            <dl className={styles.info}>
              {Object.entries(settings).map(([key, value]) => (
                <InfoRow key={key} label={capitalize(key)} value={value == null ? '—' : String(value)} />
              ))}
            </dl>
          ) : (
            <p className={styles.muted}>No configuration saved yet.</p>
          )}
        </Card>
      </div>

      {confirmAction ? (
        <Modal
          title={confirmAction === 'suspend' ? 'Suspend hospital' : 'Activate hospital'}
          onClose={() => setConfirmAction(null)}
        >
          {confirmAction === 'suspend' ? (
            <>
              <p className={styles.modalLead}>
                Suspending <strong>{hospital.name}</strong> immediately blocks all access to the
                hospital, its staff and its patients. The data is not deleted.
              </p>
              <p className={styles.modalNote}>Do you want to continue?</p>
            </>
          ) : (
            <p className={styles.modalLead}>
              Reactivating <strong>{hospital.name}</strong> restores hospital access. The hospital
              becomes available again on its tenant subdomain.
            </p>
          )}
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setConfirmAction(null)} disabled={updateStatus.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              className={confirmAction === 'suspend' ? styles.dangerButton : undefined}
              onClick={() => updateStatus.mutate(confirmAction === 'activate' ? 'active' : 'suspended')}
              disabled={updateStatus.isPending}
            >
              {updateStatus.isPending ? 'Working…' : confirmAction === 'suspend' ? 'Suspend hospital' : 'Activate hospital'}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

function BackLink() {
  return (
    <Link to="/platform/hospitals" className={styles.back}>
      <ArrowLeft size={16} /> Hospitals
    </Link>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className={styles.infoRow}>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  );
}

function capitalize(value: string): string {
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : '—';
}

function statusLabel(status: string): string {
  return status.replace(/_/g, ' ');
}

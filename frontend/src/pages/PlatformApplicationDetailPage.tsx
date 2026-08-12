import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Building2, CheckCircle2, ClipboardCopy, Clock, Send, XCircle } from 'lucide-react';
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
import type { PlatformInvitation } from '../lib/platform/types';
import { getPlatformDomain } from '../lib/tenant/hostname';
import { formatDateLong } from '../utils/format';
import styles from './PlatformApplicationDetailPage.module.scss';

/**
 * Application review. Moves a public application through the lifecycle
 * (pending -> under_review -> approved | rejected) against the real control
 * plane. Approving provisions the tenant and surfaces the single-use
 * invitation exactly once — it is never persisted on the client.
 */
export function PlatformApplicationDetailPage() {
  const { id } = useParams();
  const applicationId = Number(id);
  const { platformUser } = useAuth();
  const queryClient = useQueryClient();
  const [confirmApprove, setConfirmApprove] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [invitation, setInvitation] = useState<PlatformInvitation | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  const canReview = canAccessPlatform(platformUser?.role, platformRouteRoles.hospitalApplications);

  const application = useQuery({
    queryKey: ['platform', 'applications', applicationId],
    queryFn: async () => (await platformEndpoints.hospitalApplication(applicationId)).data,
    enabled: Number.isFinite(applicationId) && canReview,
  });

  const app = application.data?.data;

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['platform', 'applications'] });
    void queryClient.invalidateQueries({ queryKey: ['platform', 'summary'] });
  };

  const startReview = useMutation({
    mutationFn: async () => {
      await platformEndpoints.reviewApplication(applicationId);
    },
    onSuccess: () => {
      toast.success('Application marked as under review.');
      invalidate();
    },
  });

  const approve = useMutation({
    mutationFn: async () => {
      const response = await platformEndpoints.approveApplication(applicationId);
      return response.data.invitation ?? null;
    },
    onSuccess: (invite) => {
      setConfirmApprove(false);
      if (invite) {
        setInvitation(invite);
      }
      toast.success('Application approved. The tenant has been provisioned.');
      invalidate();
    },
  });

  const reject = useMutation({
    mutationFn: async () => {
      await platformEndpoints.rejectApplication(applicationId, reason.trim() || undefined);
    },
    onSuccess: () => {
      toast.success('Application rejected.');
      setRejectOpen(false);
      setReason('');
      invalidate();
    },
  });

  const copy = async (value: string, key: string) => {
    try {
      await navigator.clipboard.writeText(value);
    } catch {
      const textarea = document.createElement('textarea');
      textarea.value = value;
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
    }
    setCopiedKey(key);
    window.setTimeout(() => setCopiedKey(null), 2000);
  };

  if (application.isLoading) {
    return (
      <div className={styles.page}>
        <BackLink />
        <Card><SkeletonRows rows={6} /></Card>
      </div>
    );
  }

  if (application.isError || !app) {
    return (
      <div className={styles.page}>
        <BackLink />
        <DataStatePanel
          title="Unable to load application"
          description="This application could not be found or the platform could not be reached."
          action={<Button variant="secondary" onClick={() => void application.refetch()}>Try again</Button>}
        />
      </div>
    );
  }

  const isTerminal = app.status === 'approved' || app.status === 'rejected';
  const actionable = !isTerminal && !approve.isPending && !reject.isPending;
  const subdomain = `${app.slug}.${getPlatformDomain()}`;

  return (
    <div className={styles.page}>
      <BackLink />

      <header className={styles.heading}>
        <div className={styles.titleRow}>
          <div className={styles.titleIcon}><Building2 size={20} /></div>
          <div>
            <span>Application review</span>
            <h1>{app.hospitalName}</h1>
            <p className={styles.subdomain}>{subdomain}</p>
          </div>
        </div>
        <div className={styles.headingActions}>
          <StatusPill status={app.status} kind="application" />
          {actionable ? (
            <>
              {app.status === 'pending' ? (
                <Button
                  variant="secondary"
                  onClick={() => startReview.mutate()}
                  disabled={startReview.isPending}
                >
                  <Clock size={16} /> Start review
                </Button>
              ) : null}
              <Button variant="primary" onClick={() => setConfirmApprove(true)}>
                <CheckCircle2 size={16} /> Approve
              </Button>
              <Button variant="ghost" className={styles.dangerGhost} onClick={() => setRejectOpen(true)}>
                <XCircle size={16} /> Reject
              </Button>
            </>
          ) : null}
        </div>
      </header>

      {invitation ? <InvitationCard invitation={invitation} copiedKey={copiedKey} onCopy={copy} /> : null}

      <div className={styles.grid}>
        <Card>
          <h2 className={styles.cardTitle}>Hospital details</h2>
          <dl className={styles.info}>
            <InfoRow label="Type" value={capitalize(app.type)} />
            <InfoRow label="Requested subdomain" value={subdomain} />
            <InfoRow label="Submitted" value={formatDateLong(app.createdAt)} />
            {app.reviewedAt ? <InfoRow label="Reviewed" value={formatDateLong(app.reviewedAt)} /> : null}
          </dl>
          {app.description ? (
            <>
              <h3 className={styles.sectionTitle}>Why they applied</h3>
              <p className={styles.description}>{app.description}</p>
            </>
          ) : null}
        </Card>

        <Card>
          <h2 className={styles.cardTitle}>Applicant</h2>
          <dl className={styles.info}>
            <InfoRow label="Name" value={app.contactName} />
            <InfoRow label="Email" value={app.contactEmail} />
            <InfoRow label="Phone" value={app.contactPhone ?? '—'} />
          </dl>
        </Card>

        {app.status === 'approved' && app.tenant ? (
          <Card>
            <h2 className={styles.cardTitle}>Provisioned tenant</h2>
            <p className={styles.muted}>
              This application was approved and a tenant was provisioned for it.
            </p>
            <dl className={styles.info}>
              <InfoRow label="Hospital" value={app.tenant.name} />
              <InfoRow label="Status" value={capitalize(app.tenant.status)} />
            </dl>
            <div className={styles.actionRow}>
              <Link to={`/platform/hospitals/${app.tenant.id}`} className={styles.linkButton}>
                Open hospital
              </Link>
            </div>
            {app.invitation ? (
              <p className={styles.inviteNote}>
                Administrator invitation sent to <strong>{app.invitation.email}</strong>
                {app.invitation.usedAt ? ' and already used.' : ` (expires ${app.invitation.expiresAt ? formatDateLong(app.invitation.expiresAt) : 'soon'}).`}
              </p>
            ) : null}
          </Card>
        ) : null}

        {app.status === 'rejected' ? (
          <Card>
            <h2 className={styles.cardTitle}>Rejection</h2>
            <p className={styles.muted}>
              {app.reviewNotes
                ? `This application was rejected: ${app.reviewNotes}`
                : 'This application was rejected by a platform reviewer.'}
            </p>
          </Card>
        ) : null}
      </div>

      {confirmApprove ? (
        <Modal title="Approve this application?" onClose={() => setConfirmApprove(false)}>
          <p className={styles.modalLead}>
            Approving <strong>{app.hospitalName}</strong> provisions its tenant subdomain and
            emails the administrator a single-use invitation. The application can no longer be
            rejected afterwards.
          </p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setConfirmApprove(false)} disabled={approve.isPending}>
              Cancel
            </Button>
            <Button variant="primary" onClick={() => approve.mutate()} disabled={approve.isPending}>
              {approve.isPending ? 'Approving…' : 'Approve application'}
            </Button>
          </div>
        </Modal>
      ) : null}

      {rejectOpen ? (
        <Modal title="Reject this application?" onClose={() => setRejectOpen(false)}>
          <p className={styles.modalLead}>
            Rejecting <strong>{app.hospitalName}</strong> frees its subdomain for another
            applicant. This cannot be undone.
          </p>
          <label className={styles.field}>
            <span>Reason (optional, shared with the applicant)</span>
            <textarea
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              rows={4}
              maxLength={1000}
              placeholder="Why is this application being rejected?"
            />
          </label>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setRejectOpen(false)} disabled={reject.isPending}>
              Cancel
            </Button>
            <Button variant="primary" className={styles.dangerButton} onClick={() => reject.mutate()} disabled={reject.isPending}>
              {reject.isPending ? 'Rejecting…' : 'Reject application'}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

function BackLink() {
  return (
    <Link to="/platform/applications" className={styles.back}>
      <ArrowLeft size={16} /> Applications
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

function InvitationCard({
  invitation,
  copiedKey,
  onCopy,
}: {
  invitation: PlatformInvitation;
  copiedKey: string | null;
  onCopy: (value: string, key: string) => void;
}) {
  return (
    <Card className={styles.inviteCard}>
      <div className={styles.inviteHeader}>
        <Send size={18} />
        <div>
          <h2>Invitation ready</h2>
          <p>Sent to {invitation.email}. This is shown only once — copy it now.</p>
        </div>
      </div>
      <div className={styles.inviteRow}>
        <div>
          <span>Accept link</span>
          <code>{invitation.acceptUrl}</code>
        </div>
        <Button
          variant="secondary"
          onClick={() => void onCopy(invitation.acceptUrl, 'acceptUrl')}
          className={copiedKey === 'acceptUrl' ? styles.copied : undefined}
        >
          <ClipboardCopy size={15} /> {copiedKey === 'acceptUrl' ? 'Copied' : 'Copy'}
        </Button>
      </div>
      <div className={styles.inviteRow}>
        <div>
          <span>One-time token</span>
          <code>{invitation.token}</code>
        </div>
        <Button
          variant="secondary"
          onClick={() => void onCopy(invitation.token, 'token')}
          className={copiedKey === 'token' ? styles.copied : undefined}
        >
          <ClipboardCopy size={15} /> {copiedKey === 'token' ? 'Copied' : 'Copy'}
        </Button>
      </div>
      <p className={styles.inviteExpiry}>
        Expires {invitation.expiresAt ? formatDateLong(invitation.expiresAt) : 'soon'}.
      </p>
    </Card>
  );
}

function capitalize(value: string): string {
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : '—';
}

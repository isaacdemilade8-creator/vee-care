import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardCopy, Eye, Send, ShieldCheck, UserPlus, X } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { canAccessPlatform, platformRouteRoles } from '../auth/roleAccess';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { DataStatePanel } from '../components/DataStatePanel';
import { SelectField, TextField } from '../components/FormField';
import { Modal } from '../components/Modal';
import { Pagination } from '../components/Pagination';
import { SkeletonRows } from '../components/Skeleton';
import { StatusPill } from '../components/StatusPill';
import { useAuth } from '../context/AuthContext';
import { platformEndpoints } from '../lib/platform/api';
import { platformRoleLabel } from '../lib/platform/status';
import type { PendingPlatformUserInvitation, PlatformInvitationResult } from '../lib/platform/types';
import type { PlatformRole } from '../types';
import { getValidationErrors } from '../utils/apiError';
import { formatDate, formatDateLong } from '../utils/format';
import styles from './PlatformUsersPage.module.scss';

const ROLE_OPTIONS: Array<{ value: PlatformRole; label: string }> = [
  { value: 'platform_super_admin', label: 'Super admin' },
  { value: 'platform_admin', label: 'Admin' },
];

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

/**
 * Platform user directory. Lists the real control-plane users with server-side
 * search, role/status filtering and pagination, plus outstanding invitations.
 * Inviting returns a single-use token that is shown exactly once.
 */
export function PlatformUsersPage() {
  const { platformUser } = useAuth();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteName, setInviteName] = useState('');
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRole, setInviteRole] = useState<PlatformRole>('platform_admin');
  const [inviteErrors, setInviteErrors] = useState<Record<string, string>>({});
  const [invitation, setInvitation] = useState<PlatformInvitationResult | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);
  const [pendingRevoke, setPendingRevoke] = useState<PendingPlatformUserInvitation | null>(null);

  const actorIsSuper = platformUser?.role === 'platform_super_admin';
  const inviteRoleOptions = actorIsSuper
    ? ROLE_OPTIONS
    : ROLE_OPTIONS.filter((option) => option.value === 'platform_admin');

  const params: Record<string, string> = { page: String(page), per_page: '10' };
  if (search.trim()) params.search = search.trim();
  if (role) params.role = role;
  if (status) params.status = status;

  const users = useQuery({
    queryKey: ['platform', 'users', params],
    queryFn: async () => (await platformEndpoints.users(params)).data,
    enabled: canAccessPlatform(platformUser?.role, platformRouteRoles.users),
  });

  const rows = users.data?.data ?? [];
  const pending = users.data?.pending ?? [];
  const total = users.data?.meta?.total ?? 0;
  const totalPages = users.data?.meta?.last_page ?? 1;
  const currentPage = users.data?.meta?.current_page ?? 1;

  // The invitation token is embedded in the acceptance URL, which points at
  // the frontend page; that page then calls the (POST-only) API internally.
  const frontendAcceptUrl = invitation
    ? `${window.location.origin}/platform/invitations/${invitation.token}/accept`
    : null;

  const onSearchChange = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const onRoleChange = (value: string) => {
    setRole(value);
    setPage(1);
  };

  const onStatusChange = (value: string) => {
    setStatus(value);
    setPage(1);
  };

  const openInvite = () => {
    setInviteName('');
    setInviteEmail('');
    setInviteRole('platform_admin');
    setInviteErrors({});
    setInviteOpen(true);
  };

  const inviteMutation = useMutation({
    mutationFn: async (payload: { name: string; email: string; role: PlatformRole }) => {
      const response = await platformEndpoints.inviteUser(payload);
      return response.data.invitation;
    },
    onSuccess: (result) => {
      toast.success('Invitation sent.');
      setInviteOpen(false);
      setInvitation(result);
      void queryClient.invalidateQueries({ queryKey: ['platform', 'users'] });
    },
    onError: (error) => setInviteErrors(getValidationErrors(error)),
  });

  const revokeMutation = useMutation({
    mutationFn: async (id: number) => {
      await platformEndpoints.revokeUserInvitation(id);
    },
    onSuccess: () => {
      toast.success('Invitation revoked.');
      setPendingRevoke(null);
      void queryClient.invalidateQueries({ queryKey: ['platform', 'users'] });
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

  const submitInvite = () => {
    inviteMutation.mutate({ name: inviteName.trim(), email: inviteEmail.trim(), role: inviteRole });
  };

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Platform administration</span>
          <h1>Platform users</h1>
          <p>Administrators of the Vee-Care platform and their access.</p>
        </div>
        <Button variant="primary" onClick={openInvite}>
          <UserPlus size={16} /> Invite user
        </Button>
      </header>

      {invitation ? (
        <Card className={styles.inviteCard}>
          <div className={styles.inviteHeader}>
            <Send size={18} />
            <div>
              <h2>Invitation ready</h2>
              <p>Sent to {invitation.email}. Shown only once — copy it now.</p>
            </div>
            <Button variant="ghost" onClick={() => setInvitation(null)}>Done</Button>
          </div>
          <div className={styles.inviteRow}>
            <div>
              <span>Accept link</span>
              <code>{frontendAcceptUrl}</code>
            </div>
            <Button
              variant="secondary"
              onClick={() => frontendAcceptUrl && void copy(frontendAcceptUrl, 'acceptUrl')}
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
              onClick={() => void copy(invitation.token, 'token')}
              className={copiedKey === 'token' ? styles.copied : undefined}
            >
              <ClipboardCopy size={15} /> {copiedKey === 'token' ? 'Copied' : 'Copy'}
            </Button>
          </div>
          <p className={styles.inviteExpiry}>
            Expires {invitation.expiresAt ? formatDateLong(invitation.expiresAt) : 'soon'}.
          </p>
        </Card>
      ) : null}

      <div className={styles.filters}>
        <div className={styles.search}>
          <input
            type="search"
            placeholder="Search users by name or email…"
            value={search}
            onChange={(event) => onSearchChange(event.target.value)}
            aria-label="Search platform users"
          />
        </div>
        <select
          className={styles.select}
          value={role}
          onChange={(event) => onRoleChange(event.target.value)}
          aria-label="Filter by role"
        >
          <option value="">All roles</option>
          {ROLE_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
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

      {users.isLoading ? (
        <Card><SkeletonRows rows={6} /></Card>
      ) : users.isError ? (
        <DataStatePanel
          title="Unable to load platform users"
          description="The user directory could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void users.refetch()}>Try again</Button>}
        />
      ) : rows.length === 0 ? (
        <DataStatePanel
          title={search || role || status ? 'No users match your filters' : 'No platform users yet'}
          description={search || role || status ? 'Try adjusting the search or filters.' : 'Invited administrators appear here once they join.'}
        />
      ) : (
        <Card className={styles.tableCard}>
          <div className={styles.table}>
            <div className={styles.tableHead} role="row">
              <span role="columnheader">User</span>
              <span role="columnheader">Role</span>
              <span role="columnheader">Status</span>
              <span role="columnheader">Created</span>
              <span role="columnheader" className={styles.actionsCol}>Actions</span>
            </div>
            {rows.map((user) => (
              <div className={styles.row} role="row" key={user.id}>
                <span role="cell">
                  <Link to={`/platform/users/${user.id}`} className={styles.name}>
                    {user.name}
                  </Link>
                  <span className={styles.email}>{user.email}</span>
                </span>
                <span role="cell" className={styles.role}>
                  <ShieldCheck size={14} />
                  {platformRoleLabel[user.role]}
                </span>
                <span role="cell">
                  <StatusPill status={user.status} kind="user" />
                </span>
                <span role="cell" className={styles.date}>
                  {formatDate(user.createdAt)}
                </span>
                <span role="cell" className={styles.actionsCol}>
                  <Link to={`/platform/users/${user.id}`} className={styles.view}>
                    <Eye size={15} />
                    View
                  </Link>
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {!users.isLoading && !users.isError && rows.length > 0 ? (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          total={total}
          onPageChange={setPage}
          itemLabel="users"
        />
      ) : null}

      {!users.isLoading && !users.isError && pending.length > 0 ? (
        <Card className={styles.pendingCard}>
          <div className={styles.pendingHead}>
            <div>
              <span>Outstanding invites</span>
              <h2>Pending invitations</h2>
            </div>
          </div>
          <div className={styles.pendingList}>
            {pending.map((invite) => {
              const canRevoke =
                platformUser?.role === 'platform_super_admin' || invite.role === 'platform_admin';
              return (
                <div className={styles.pendingRow} key={invite.id}>
                  <div className={styles.pendingMain}>
                    <strong>{invite.email}</strong>
                    <span>
                      {platformRoleLabel[invite.role]} · invited by {invite.inviter?.name ?? 'System'} ·
                      expires {invite.expiresAt ? formatDate(invite.expiresAt) : 'soon'}
                    </span>
                  </div>
                  {canRevoke ? (
                    <Button variant="ghost" className={styles.revoke} onClick={() => setPendingRevoke(invite)}>
                      <X size={15} /> Revoke
                    </Button>
                  ) : null}
                </div>
              );
            })}
          </div>
        </Card>
      ) : null}

      {inviteOpen ? (
        <Modal title="Invite a platform user" onClose={() => setInviteOpen(false)}>
          <p className={styles.modalLead}>
            No password is set here — the invitee receives a single-use link and creates their own
            password when they accept.
          </p>
          <div className={styles.form}>
            <TextField
              label="Full name"
              value={inviteName}
              onChange={(event) => setInviteName(event.target.value)}
              placeholder="e.g. Jordan Smith"
              maxLength={255}
              error={inviteErrors.name}
            />
            <TextField
              label="Work email"
              type="email"
              value={inviteEmail}
              onChange={(event) => setInviteEmail(event.target.value)}
              placeholder="e.g. jordan@vee-care.test"
              maxLength={255}
              error={inviteErrors.email}
            />
            <SelectField
              label="Platform role"
              value={inviteRole}
              onChange={(event) => setInviteRole(event.target.value as PlatformRole)}
              error={inviteErrors.role}
            >
              {inviteRoleOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>
          </div>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setInviteOpen(false)} disabled={inviteMutation.isPending}>
              Cancel
            </Button>
            <Button variant="primary" onClick={submitInvite} disabled={inviteMutation.isPending}>
              <UserPlus size={16} /> {inviteMutation.isPending ? 'Inviting…' : 'Send invitation'}
            </Button>
          </div>
        </Modal>
      ) : null}

      {pendingRevoke ? (
        <Modal title="Revoke this invitation?" onClose={() => setPendingRevoke(null)}>
          <p className={styles.modalLead}>
            Revoking the invitation to <strong>{pendingRevoke.email}</strong> invalidates its accept
            link. They can be invited again later if needed.
          </p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setPendingRevoke(null)} disabled={revokeMutation.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              className={styles.dangerButton}
              onClick={() => revokeMutation.mutate(pendingRevoke.id)}
              disabled={revokeMutation.isPending}
            >
              {revokeMutation.isPending ? 'Revoking…' : 'Revoke invitation'}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

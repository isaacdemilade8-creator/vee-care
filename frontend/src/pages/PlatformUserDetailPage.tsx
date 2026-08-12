import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, PauseCircle, Save, ShieldCheck, UserCog } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { canAccessPlatform, platformRouteRoles } from '../auth/roleAccess';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { DataStatePanel } from '../components/DataStatePanel';
import { SelectField, TextField } from '../components/FormField';
import { Modal } from '../components/Modal';
import { SkeletonRows } from '../components/Skeleton';
import { StatusPill } from '../components/StatusPill';
import { useAuth } from '../context/AuthContext';
import { platformEndpoints } from '../lib/platform/api';
import { platformRoleLabel, userStatusLabel } from '../lib/platform/status';
import type { PlatformUser } from '../lib/platform/types';
import type { PlatformRole } from '../types';
import { getValidationErrors } from '../utils/apiError';
import { formatDateLong } from '../utils/format';
import styles from './PlatformUserDetailPage.module.scss';

const ROLE_OPTIONS: Array<{ value: PlatformRole; label: string }> = [
  { value: 'platform_super_admin', label: 'Super admin' },
  { value: 'platform_admin', label: 'Admin' },
];

/**
 * Platform user detail: safe overview of a single control-plane user with the
 * lifecycle actions (activate / deactivate, role change, details edit) wired
 * to the real platform-user API. The frontend only shows actions the backend
 * permits — the server remains the source of truth for authorization.
 */
export function PlatformUserDetailPage() {
  const { id } = useParams();
  const userId = Number(id);
  const { platformUser } = useAuth();
  const queryClient = useQueryClient();
  const [confirmDeactivate, setConfirmDeactivate] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [editName, setEditName] = useState('');
  const [editEmail, setEditEmail] = useState('');
  const [editErrors, setEditErrors] = useState<Record<string, string>>({});

  const detail = useQuery({
    queryKey: ['platform', 'users', userId],
    queryFn: async () => (await platformEndpoints.user(userId)).data,
    enabled: Number.isFinite(userId) && canAccessPlatform(platformUser?.role, platformRouteRoles.users),
  });

  const record = detail.data?.data;

  const actorIsSuper = platformUser?.role === 'platform_super_admin';
  const isSelf = record != null && platformUser?.id === record.id;
  const canManage = actorIsSuper || record?.role === 'platform_admin';
  const canChangeRole = actorIsSuper && !isSelf;
  const canDeactivate = Boolean(record?.isActive) && canManage && !isSelf;
  const canActivate = Boolean(record && !record.isActive) && canManage;

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['platform', 'users'] });
  };

  const updateRole = useMutation({
    mutationFn: async (role: PlatformRole) => {
      await platformEndpoints.updateUser(userId, { role });
    },
    onSuccess: () => {
      toast.success('Role updated.');
      invalidate();
    },
  });

  const deactivate = useMutation({
    mutationFn: async () => {
      await platformEndpoints.deactivateUser(userId);
    },
    onSuccess: () => {
      toast.success('User deactivated and their sessions revoked.');
      setConfirmDeactivate(false);
      invalidate();
    },
  });

  const activate = useMutation({
    mutationFn: async () => {
      await platformEndpoints.activateUser(userId);
    },
    onSuccess: () => {
      toast.success('User activated.');
      invalidate();
    },
  });

  const editUser = useMutation({
    mutationFn: async (payload: { name: string; email: string }) => {
      await platformEndpoints.updateUser(userId, payload);
    },
    onSuccess: () => {
      toast.success('User details updated.');
      setEditOpen(false);
      setEditErrors({});
      invalidate();
    },
    onError: (error) => setEditErrors(getValidationErrors(error)),
  });

  const openEdit = () => {
    if (!record) {
      return;
    }
    setEditName(record.name);
    setEditEmail(record.email);
    setEditErrors({});
    setEditOpen(true);
  };

  if (detail.isLoading) {
    return (
      <div className={styles.page}>
        <BackLink />
        <Card><SkeletonRows rows={5} /></Card>
      </div>
    );
  }

  if (detail.isError || !record) {
    return (
      <div className={styles.page}>
        <BackLink />
        <DataStatePanel
          title="Unable to load user"
          description="This platform user could not be found or the platform could not be reached."
          action={<Button variant="secondary" onClick={() => void detail.refetch()}>Try again</Button>}
        />
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <BackLink />

      <header className={styles.heading}>
        <div className={styles.titleRow}>
          <div className={styles.titleIcon}><ShieldCheck size={20} /></div>
          <div>
            <span>Platform administration</span>
            <h1>{record.name}</h1>
            <p className={styles.subdomain}>{record.email}</p>
          </div>
        </div>
        <div className={styles.headingActions}>
          <StatusPill status={record.status} kind="user" />
          {canActivate ? (
            <Button variant="primary" onClick={() => activate.mutate()} disabled={activate.isPending}>
              <CheckCircle2 size={16} /> Activate user
            </Button>
          ) : canDeactivate ? (
            <Button
              variant="secondary"
              className={styles.deactivateButton}
              onClick={() => setConfirmDeactivate(true)}
              disabled={deactivate.isPending}
            >
              <PauseCircle size={16} /> Deactivate user
            </Button>
          ) : null}
        </div>
      </header>

      <div className={styles.grid}>
        <Card>
          <h2 className={styles.cardTitle}>Overview</h2>
          <dl className={styles.info}>
            <InfoRow label="Role" value={platformRoleLabel[record.role]} />
            <InfoRow label="Status" value={userStatusLabel[record.status]} />
            <InfoRow label="Created" value={formatDateLong(record.createdAt)} />
            <InfoRow label="Updated" value={formatDateLong(record.updatedAt)} />
          </dl>
        </Card>

        <Card>
          <h2 className={styles.cardTitle}>Manage access</h2>
          {canChangeRole ? (
            <>
              <RoleEditor
                record={record}
                onSave={(role) => updateRole.mutate(role)}
                saving={updateRole.isPending}
              />
              <p className={styles.muted}>Changing the role takes effect immediately.</p>
            </>
          ) : null}

          {canManage ? (
            <div className={styles.editRow}>
              <Button variant="secondary" onClick={openEdit}>
                <UserCog size={16} /> Edit details
              </Button>
            </div>
          ) : null}

          {!canManage ? (
            <p className={styles.muted}>
              Platform admins can only manage other admins. This user cannot be modified here.
            </p>
          ) : null}

          {isSelf ? (
            <p className={styles.muted}>You cannot change your own role or deactivate your own account.</p>
          ) : null}
        </Card>
      </div>

      {confirmDeactivate ? (
        <Modal title="Deactivate this user?" onClose={() => setConfirmDeactivate(false)}>
          <p className={styles.modalLead}>
            Deactivating <strong>{record.name}</strong> immediately blocks their platform access and
            revokes all of their sessions. They can be reactivated later.
          </p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setConfirmDeactivate(false)} disabled={deactivate.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              className={styles.dangerButton}
              onClick={() => deactivate.mutate()}
              disabled={deactivate.isPending}
            >
              {deactivate.isPending ? 'Deactivating…' : 'Deactivate user'}
            </Button>
          </div>
        </Modal>
      ) : null}

      {editOpen ? (
        <Modal title="Edit platform user" onClose={() => setEditOpen(false)}>
          <div className={styles.form}>
            <TextField
              label="Full name"
              value={editName}
              onChange={(event) => setEditName(event.target.value)}
              maxLength={255}
              error={editErrors.name}
            />
            <TextField
              label="Email"
              type="email"
              value={editEmail}
              onChange={(event) => setEditEmail(event.target.value)}
              maxLength={255}
              error={editErrors.email}
            />
          </div>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setEditOpen(false)} disabled={editUser.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              onClick={() => editUser.mutate({ name: editName.trim(), email: editEmail.trim() })}
              disabled={editUser.isPending}
            >
              <Save size={16} /> {editUser.isPending ? 'Saving…' : 'Save changes'}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

function RoleEditor({
  record,
  onSave,
  saving,
}: {
  record: PlatformUser;
  onSave: (role: PlatformRole) => void;
  saving: boolean;
}) {
  const [value, setValue] = useState<PlatformRole>(record.role);
  const changed = value !== record.role;

  return (
    <div className={styles.roleEditor}>
      <SelectField
        label="Platform role"
        value={value}
        onChange={(event) => setValue(event.target.value as PlatformRole)}
      >
        {ROLE_OPTIONS.map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </SelectField>
      <Button variant="primary" onClick={() => onSave(value)} disabled={!changed || saving}>
        <Save size={15} /> {saving ? 'Saving…' : 'Save role'}
      </Button>
    </div>
  );
}

function BackLink() {
  return (
    <Link to="/platform/users" className={styles.back}>
      <ArrowLeft size={16} /> Platform users
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

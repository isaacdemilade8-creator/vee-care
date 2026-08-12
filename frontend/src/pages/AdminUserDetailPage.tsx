import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, PauseCircle, Save, Stethoscope, UserCog } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { DataStatePanel } from '../components/DataStatePanel';
import { SelectField, TextField } from '../components/FormField';
import { Modal } from '../components/Modal';
import { SkeletonRows } from '../components/Skeleton';
import { StatusPill } from '../components/StatusPill';
import { specialtyDepartmentOptions } from '../constants/specialties';
import { useAuth } from '../context/AuthContext';
import { getTenantConfiguration, isTenantRoleAssignable } from '../lib/tenant/configuration';
import { MANAGED_ROLE_OPTIONS, tenantRoleLabel } from '../lib/tenant/roles';
import { endpoints } from '../services/endpoints';
import type { AdminUser, Role } from '../types';
import { getValidationErrors } from '../utils/apiError';
import { formatDateLong } from '../utils/format';
import styles from './AdminUserDetailPage.module.scss';

/**
 * Hospital user detail: safe overview of a single tenant user with the
 * lifecycle actions (activate / deactivate, role change, details edit) wired
 * to the real hospital-admin API. The frontend only shows actions the backend
 * permits — the server remains the source of truth for authorization.
 */
export function AdminUserDetailPage() {
  const { id } = useParams();
  const userId = Number(id);
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [confirmDeactivate, setConfirmDeactivate] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [editName, setEditName] = useState('');
  const [editSpecialty, setEditSpecialty] = useState('');
  const [editPhone, setEditPhone] = useState('');
  const [editBranch, setEditBranch] = useState('');
  const [editErrors, setEditErrors] = useState<Record<string, string>>({});

  const detail = useQuery({
    queryKey: ['admin-users', userId],
    enabled: Number.isFinite(userId),
    queryFn: async () => (await endpoints.adminUser(userId)).data,
  });

  const { data: organization } = useQuery({
    queryKey: ['admin-organization'],
    enabled: Number.isFinite(userId),
    queryFn: async () => (await endpoints.adminOrganization()).data,
  });

  const { data: configuration } = useQuery({
    queryKey: ['tenant-configuration'],
    queryFn: getTenantConfiguration,
    staleTime: 30_000,
  });

  const record = detail.data;
  const branches = organization?.branches ?? [];

  const isSelf = record != null && user?.id === record.id;
  const isHospitalAdmin = record?.role === 'hospital_admin';
  const canManage = Boolean(record) && !isHospitalAdmin;
  const canChangeRole = canManage;
  const canDeactivate = Boolean(record?.isActive) && !isSelf;
  const canActivate = Boolean(record && !record.isActive) && !isSelf;
  const roleOptions = MANAGED_ROLE_OPTIONS.filter((option) => isTenantRoleAssignable(configuration, option.value));

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['admin-users'] });
  };

  const updateRole = useMutation({
    mutationFn: async (role: Role) => {
      await endpoints.updateAdminUser(userId, { role });
    },
    onSuccess: () => {
      toast.success('Role updated.');
      invalidate();
    },
  });

  const deactivate = useMutation({
    mutationFn: async () => {
      await endpoints.deactivateAdminUser(userId);
    },
    onSuccess: () => {
      toast.success('User deactivated and their sessions revoked.');
      setConfirmDeactivate(false);
      invalidate();
    },
  });

  const activate = useMutation({
    mutationFn: async () => {
      await endpoints.activateAdminUser(userId);
    },
    onSuccess: () => {
      toast.success('User activated.');
      invalidate();
    },
  });

  const editUser = useMutation({
    mutationFn: async (payload: { name: string; specialty: string; phone: string; branch_id: number | null }) => {
      await endpoints.updateAdminUser(userId, payload);
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
    setEditSpecialty(record.specialty ?? '');
    setEditPhone(record.phone ?? '');
    setEditBranch(record.branchId != null ? String(record.branchId) : '');
    setEditErrors({});
    setEditOpen(true);
  };

  const saveEdit = () => {
    if (!editName.trim()) {
      setEditErrors({ name: 'Full name is required' });
      return;
    }
    editUser.mutate({
      name: editName.trim(),
      specialty: editSpecialty.trim(),
      phone: editPhone.trim(),
      branch_id: editBranch ? Number(editBranch) : null,
    });
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
          description="This user could not be found in this hospital or the server could not be reached."
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
          <div className={styles.titleIcon}><Stethoscope size={20} /></div>
          <div>
            <span>Hospital operations</span>
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
            <InfoRow label="Role" value={tenantRoleLabel[record.role]} />
            <InfoRow label="Status" value={record.status === 'active' ? 'Active' : 'Inactive'} />
            <InfoRow label="Specialty" value={record.specialty || '—'} />
            <InfoRow label="Phone" value={record.phone || '—'} />
            <InfoRow label="Branch" value={record.branchName || '—'} />
            <InfoRow label="Created" value={record.createdAt ? formatDateLong(record.createdAt) : '—'} />
          </dl>
        </Card>

        <Card>
          <h2 className={styles.cardTitle}>Manage access</h2>
          {canChangeRole ? (
            <>
              <RoleEditor
                record={record}
                options={roleOptions}
                onSave={(role) => updateRole.mutate(role)}
                saving={updateRole.isPending}
              />
              <p className={styles.muted}>Changing the role takes effect immediately.</p>
            </>
          ) : null}

          {isHospitalAdmin ? (
            <p className={styles.muted}>
              Hospital admin accounts cannot be reassigned or demoted from the admin surface.
            </p>
          ) : null}

          {canManage ? (
            <div className={styles.editRow}>
              <Button variant="secondary" onClick={openEdit}>
                <UserCog size={16} /> Edit details
              </Button>
            </div>
          ) : null}

          {isSelf ? (
            <p className={styles.muted}>You cannot deactivate your own account.</p>
          ) : null}
        </Card>
      </div>

      {confirmDeactivate ? (
        <Modal title="Deactivate this user?" onClose={() => setConfirmDeactivate(false)}>
          <p className={styles.modalLead}>
            Deactivating <strong>{record.name}</strong> immediately blocks their hospital access and
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
        <Modal title="Edit hospital user" onClose={() => setEditOpen(false)}>
          <div className={styles.form}>
            <TextField
              label="Full name"
              value={editName}
              onChange={(event) => {
                setEditName(event.target.value);
                if (editErrors.name) setEditErrors((prev) => ({ ...prev, name: '' }));
              }}
              maxLength={255}
              error={editErrors.name}
            />
            <SelectField
              label="Specialty / Department"
              value={editSpecialty}
              onChange={(event) => setEditSpecialty(event.target.value)}
            >
              <option value="">No specialty</option>
              {specialtyDepartmentOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>
            <TextField
              label="Phone"
              type="tel"
              value={editPhone}
              onChange={(event) => setEditPhone(event.target.value)}
              placeholder="e.g. +1 (555) 123-4567"
            />
            {branches.length ? (
              <SelectField
                label="Branch"
                value={editBranch}
                onChange={(event) => setEditBranch(event.target.value)}
              >
                <option value="">No branch</option>
                {branches.map((branch) => (
                  <option key={branch.id} value={String(branch.id)}>{branch.name}</option>
                ))}
              </SelectField>
            ) : null}
            {editErrors.role || editErrors.branch_id ? (
              <p className={styles.muted}>{editErrors.role ?? editErrors.branch_id}</p>
            ) : null}
          </div>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setEditOpen(false)} disabled={editUser.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              onClick={saveEdit}
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
  options,
  onSave,
  saving,
}: {
  record: AdminUser;
  options: Array<{ value: Role; label: string }>;
  onSave: (role: Role) => void;
  saving: boolean;
}) {
  const [value, setValue] = useState<Role>(record.role);
  const changed = value !== record.role;

  return (
    <div className={styles.roleEditor}>
      <SelectField
        label="Role"
        value={value}
        onChange={(event) => setValue(event.target.value as Role)}
      >
        {options.map((option) => (
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
    <Link to="/admin/users" className={styles.back}>
      <ArrowLeft size={16} /> Users
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

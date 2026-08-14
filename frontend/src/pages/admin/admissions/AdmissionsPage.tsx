import { useMutation, useQueryClient } from '@tanstack/react-query';
import { LogOut, Pencil, Plus, Trash2 } from 'lucide-react';
import type { ChangeEvent } from 'react';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { Button } from '../../../components/Button';
import { Card } from '../../../components/Card';
import { DataStatePanel } from '../../../components/DataStatePanel';
import { SelectField, TextAreaField, TextField } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { SkeletonRows } from '../../../components/Skeleton';
import { useAdmissions, useAdminUsers, useBedAvailability, useDepartments, useRooms, useWards } from '../../../hooks/useApi';
import { endpoints } from '../../../services/endpoints';
import { toOptions } from '../structure/structure';
import { CellText, StructureStatusPill } from '../structure/StructurePage';
import styles from '../structure/StructurePage.module.scss';
import formStyles from './AdmissionsPage.module.scss';
import type { Admission, AdmissionStatus } from '../../../types';
import { formatDate } from '../../../utils/format';
import { getValidationErrors } from '../../../utils/apiError';

const STATUS_FILTER: Array<{ value: '' | AdmissionStatus; label: string }> = [
  { value: '', label: 'All statuses' },
  { value: 'admitted', label: 'Admitted' },
  { value: 'discharged', label: 'Discharged' },
];

interface ConfirmTarget {
  kind: 'discharge' | 'delete';
  admission: Admission;
}

const EMPTY_FORM = {
  patient_id: '',
  department_id: '',
  ward_id: '',
  room_id: '',
  bed_id: '',
  practitioner_id: '',
  reason: '',
  notes: '',
  admitted_at: '',
};

type AdmissionForm = typeof EMPTY_FORM;

export function AdmissionsPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState<AdmissionForm>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const [detail, setDetail] = useState<Admission | null>(null);
  const [editing, setEditing] = useState<Admission | null>(null);
  const [confirm, setConfirm] = useState<ConfirmTarget | null>(null);

  const params: Record<string, string> = { page: String(page), per_page: '10' };
  if (search.trim()) params.search = search.trim();
  if (status) params.status = status;

  const { data, isLoading, isError, refetch } = useAdmissions(params);
  const admissions = data?.data ?? [];
  const total = data?.meta?.total ?? 0;
  const totalPages = data?.meta?.last_page ?? 1;
  const currentPage = data?.meta?.current_page ?? 1;

  const patients = useAdminUsers({ role: 'patient', per_page: '100' });
  const doctors = useAdminUsers({ role: 'doctor', per_page: '100' });
  const nurses = useAdminUsers({ role: 'nurse', per_page: '100' });
  const patientOptions = toOptions(patients.data?.data ?? []);
  const practitionerOptions = [...toOptions(doctors.data?.data ?? []), ...toOptions(nurses.data?.data ?? [])];

  const departments = useDepartments({ per_page: '100' });
  const departmentOptions = toOptions(departments.data?.data ?? []);

  const wardFilters: Record<string, string> = { per_page: '100' };
  if (form.department_id) wardFilters.department_id = form.department_id;
  const wards = useWards(wardFilters);
  const wardOptions = toOptions(wards.data?.data ?? []);

  const roomFilters: Record<string, string> = { per_page: '100' };
  if (form.ward_id) roomFilters.ward_id = form.ward_id;
  const rooms = useRooms(roomFilters);
  const roomOptions = toOptions(rooms.data?.data ?? []);

  const bedFilters: Record<string, string> = {};
  if (form.room_id) bedFilters.room_id = form.room_id;
  const availableBeds = useBedAvailability(bedFilters, Boolean(form.room_id));
  const bedOptions = (availableBeds.data?.data ?? []).map((bed) => ({
    value: String(bed.id),
    label: `${bed.bedNumber}${bed.room ? ` · ${bed.room.name}` : ''}`,
  }));

  const invalidate = () =>
    queryClient.invalidateQueries({
      queryKey: ['admin-admissions'],
      refetchType: 'all',
    });

  const refreshBedData = () =>
    void queryClient.invalidateQueries({
      queryKey: ['admin-beds-availability', 'admin-beds'],
      refetchType: 'all',
    });

  const setField = (name: keyof AdmissionForm) => (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
    setForm((prev) => ({ ...prev, [name]: event.target.value }));
    if (formErrors[name]) {
      setFormErrors((prev) => ({ ...prev, [name]: '' }));
    }
  };

  const clearAfter = (fields: Array<keyof AdmissionForm>) => {
    setForm((prev) => {
      const next = { ...prev };
      for (const field of fields) next[field] = '';
      return next;
    });
  };

  const onDepartmentChange: ChangeEventHandler = (event) => {
    setField('department_id')(event);
    clearAfter(['ward_id', 'room_id', 'bed_id']);
  };
  const onWardChange: ChangeEventHandler = (event) => {
    setField('ward_id')(event);
    clearAfter(['room_id', 'bed_id']);
  };
  const onRoomChange: ChangeEventHandler = (event) => {
    setField('room_id')(event);
    clearAfter(['bed_id']);
  };

  const openCreate = () => {
    setForm(EMPTY_FORM);
    setFormErrors({});
    setCreateOpen(true);
  };

  const submitCreate = () => {
    const errors: Record<string, string> = {};
    if (!form.patient_id) errors.patient_id = 'Patient is required';
    if (!form.ward_id) errors.ward_id = 'Ward is required';
    if (!form.room_id) errors.room_id = 'Room is required';
    if (!form.bed_id) errors.bed_id = 'Bed is required';
    setFormErrors(errors);
    if (Object.keys(errors).length) {
      return;
    }

    const payload: Record<string, unknown> = {
      patient_id: Number(form.patient_id),
      department_id: form.department_id ? Number(form.department_id) : null,
      ward_id: Number(form.ward_id),
      room_id: Number(form.room_id),
      bed_id: Number(form.bed_id),
      practitioner_id: form.practitioner_id ? Number(form.practitioner_id) : null,
      reason: form.reason || null,
      notes: form.notes || null,
    };
    if (form.admitted_at) payload.admitted_at = form.admitted_at;

    create.mutate(payload);
  };

  const create = useMutation({
    mutationFn: (payload: Record<string, unknown>) => endpoints.createAdmission(payload),
    onSuccess: async (response) => {
      await invalidate();
      refreshBedData();
      toast.success('Patient admitted.');
      setCreateOpen(false);
      setDetail(response.data.data);
    },
    onError: (error) => setFormErrors(getValidationErrors(error)),
  });

  const update = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Record<string, unknown> }) => endpoints.updateAdmission(id, payload),
    onSuccess: async (response) => {
      await invalidate();
      toast.success('Admission updated.');
      setEditing(null);
      setDetail(response.data.data);
    },
  });

  const discharge = useMutation({
    mutationFn: (admission: Admission) => endpoints.dischargeAdmission(admission.id),
    onSuccess: async (response) => {
      await invalidate();
      refreshBedData();
      toast.success(`${response.data.data.patient?.name ?? 'Patient'} discharged.`);
      setConfirm(null);
      setDetail(response.data.data);
    },
  });

  const remove = useMutation({
    mutationFn: (admission: Admission) => endpoints.deleteAdmission(admission.id),
    onSuccess: async () => {
      await invalidate();
      refreshBedData();
      toast.success('Admission deleted.');
      setConfirm(null);
      setDetail(null);
    },
  });

  const isBusy = create.isPending || update.isPending || discharge.isPending || remove.isPending;

  const editValues = (admission: Admission) => ({
    practitioner_id: admission.practitioner ? String(admission.practitioner.id) : '',
    reason: admission.reason ?? '',
    notes: admission.notes ?? '',
  });

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Inpatient care</span>
          <h1>Admissions</h1>
          <p>Track patients currently admitted and their assigned beds. Discharging releases the bed for the next patient.</p>
        </div>
        <Button variant="primary" onClick={openCreate} disabled={isBusy}>
          <Plus size={16} /> New admission
        </Button>
      </header>

      <div className={styles.filters}>
        <div className={styles.search}>
          <input
            type="search"
            placeholder="Search patients, emails or patient numbers…"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            aria-label="Search admissions"
          />
        </div>
        <select
          className={styles.select}
          value={status}
          onChange={(event) => {
            setStatus(event.target.value);
            setPage(1);
          }}
          aria-label="Filter by status"
        >
          {STATUS_FILTER.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </div>

      {isLoading ? (
        <Card><SkeletonRows rows={6} /></Card>
      ) : isError ? (
        <DataStatePanel
          title="Unable to load admissions"
          description="The admission list could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void refetch()}>Try again</Button>}
        />
      ) : admissions.length === 0 ? (
        <DataStatePanel
          title={search || status ? 'No admissions match your filters' : 'No admissions yet'}
          description={search || status ? 'Try adjusting the search or status filter.' : 'Admit the first patient to get started.'}
        />
      ) : (
        <Card className={styles.tableCard}>
          <div className={styles.table}>
            <div className={styles.tableHead} role="row" style={{ gridTemplateColumns: '2fr 1.1fr 1fr 1.2fr 1fr 0.8fr 1fr' }}>
              <span role="columnheader">Patient</span>
              <span role="columnheader">Ward</span>
              <span role="columnheader">Room</span>
              <span role="columnheader">Bed</span>
              <span role="columnheader">Practitioner</span>
              <span role="columnheader">Admitted</span>
              <span role="columnheader" className={styles.actionsCol}>Status</span>
            </div>
            {admissions.map((admission) => (
              <div
                className={styles.row}
                role="row"
                key={admission.id}
                style={{ gridTemplateColumns: '2fr 1.1fr 1fr 1.2fr 1fr 0.8fr 1fr', cursor: 'pointer' }}
                onClick={() => setDetail(admission)}
              >
                <span role="cell"><CellText title={admission.patient?.name ?? '—'} sub={admission.patient?.email} /></span>
                <span role="cell"><CellText title={admission.ward?.name ?? '—'} sub={admission.department?.name} /></span>
                <span role="cell">{admission.room?.name ?? '—'}</span>
                <span role="cell"><CellText title={admission.bed?.bedNumber ?? '—'} sub={admission.bed?.status} /></span>
                <span role="cell">{admission.practitioner?.name ?? <span className="muted">—</span>}</span>
                <span role="cell" className={styles.date}>{admission.admittedAt ? formatDate(admission.admittedAt) : '—'}</span>
                <span role="cell" className={styles.actionsCol}>
                  <StructureStatusPill value={admission.status} />
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {!isLoading && !isError && admissions.length > 0 ? (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          total={total}
          onPageChange={setPage}
          itemLabel="admissions"
        />
      ) : null}

      {createOpen ? (
        <Modal title="New admission" onClose={() => setCreateOpen(false)}>
          <div className={formStyles.formGrid}>
            <SelectField label="Patient" name="patient_id" value={form.patient_id} onChange={setField('patient_id')} error={formErrors.patient_id} required>
              <option value="">Select a patient…</option>
              {patientOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>

            <SelectField label="Practitioner (optional)" name="practitioner_id" value={form.practitioner_id} onChange={setField('practitioner_id')} error={formErrors.practitioner_id}>
              <option value="">None</option>
              {practitionerOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>

            <SelectField label="Department" name="department_id" value={form.department_id} onChange={onDepartmentChange}>
              <option value="">Select a department…</option>
              {departmentOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>

            <SelectField label="Ward" name="ward_id" value={form.ward_id} onChange={onWardChange} error={formErrors.ward_id} required>
              <option value="">Select a ward…</option>
              {wardOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>

            <SelectField label="Room" name="room_id" value={form.room_id} onChange={onRoomChange} error={formErrors.room_id} required>
              <option value="">Select a room…</option>
              {roomOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>

            <SelectField label="Available bed" name="bed_id" value={form.bed_id} onChange={setField('bed_id')} error={formErrors.bed_id} required>
              <option value="">{form.room_id ? 'No beds available…' : 'Select a room first…'}</option>
              {bedOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>
          </div>

          <div className={styles.form}>
            <TextField label="Admitted at (optional)" name="admitted_at" type="datetime-local" value={form.admitted_at} onChange={setField('admitted_at')} />
            <TextField label="Reason" name="reason" value={form.reason} onChange={setField('reason')} placeholder="e.g. Chest pain observation" maxLength={255} />
            <TextAreaField label="Notes" name="notes" value={form.notes} onChange={setField('notes')} placeholder="Care notes for the ward…" rows={3} />
          </div>

          <p className={styles.hint}>The bed must be available and belong to the selected room. Admitting a patient marks it occupied.</p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setCreateOpen(false)} disabled={create.isPending}>
              Cancel
            </Button>
            <Button variant="primary" onClick={submitCreate} disabled={create.isPending}>
              {create.isPending ? 'Admitting…' : 'Admit patient'}
            </Button>
          </div>
        </Modal>
      ) : null}

      {detail ? (
        <Modal title={`Admission #${detail.id}`} onClose={() => setDetail(null)}>
          <div className={formStyles.detailGrid}>
            <div>
              <span className={formStyles.detailLabel}>Patient</span>
              <span className={formStyles.detailValue}>{detail.patient?.name ?? '—'}</span>
              <span className={formStyles.detailSub}>{detail.patient?.email}</span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Status</span>
              <span><StructureStatusPill value={detail.status} /></span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Ward</span>
              <span className={formStyles.detailValue}>{detail.ward?.name ?? '—'}</span>
              <span className={formStyles.detailSub}>{detail.department?.name}</span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Room / Bed</span>
              <span className={formStyles.detailValue}>{detail.room?.name ?? '—'} · {detail.bed?.bedNumber ?? '—'}</span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Practitioner</span>
              <span className={formStyles.detailValue}>{detail.practitioner?.name ?? '—'}</span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Admitted</span>
              <span className={formStyles.detailValue}>{detail.admittedAt ? formatDate(detail.admittedAt) : '—'}</span>
              {detail.dischargedAt ? <span className={formStyles.detailSub}>Discharged {formatDate(detail.dischargedAt)}</span> : null}
            </div>
            {detail.reason ? (
              <div>
                <span className={formStyles.detailLabel}>Reason</span>
                <span className={formStyles.detailValue}>{detail.reason}</span>
              </div>
            ) : null}
            {detail.notes ? (
              <div>
                <span className={formStyles.detailLabel}>Notes</span>
                <span className={formStyles.detailValue}>{detail.notes}</span>
              </div>
            ) : null}
          </div>

          <div className={styles.modalActions}>
            {detail.status === 'admitted' ? (
              <Button
                variant="secondary"
                className={formStyles.confirmButton}
                disabled={isBusy}
                onClick={() => setConfirm({ kind: 'discharge', admission: detail })}
              >
                <LogOut size={15} /> Discharge
              </Button>
            ) : null}
            <Button
              variant="ghost"
              disabled={isBusy}
              onClick={() => {
                setEditing(detail);
                setForm({ ...EMPTY_FORM, ...editValues(detail) });
                setFormErrors({});
              }}
            >
              <Pencil size={15} /> Edit
            </Button>
            <Button
              variant="ghost"
              className={styles.deleteButton}
              disabled={isBusy}
              onClick={() => setConfirm({ kind: 'delete', admission: detail })}
            >
              <Trash2 size={15} /> Delete
            </Button>
            <Button variant="primary" onClick={() => setDetail(null)}>
              Close
            </Button>
          </div>
        </Modal>
      ) : null}

      {editing ? (
        <Modal title={`Edit admission #${editing.id}`} onClose={() => setEditing(null)}>
          <div className={styles.form}>
            <SelectField label="Practitioner (optional)" name="practitioner_id" value={form.practitioner_id} onChange={setField('practitioner_id')} error={formErrors.practitioner_id}>
              <option value="">None</option>
              {practitionerOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>
            <TextField label="Reason" name="reason" value={form.reason} onChange={setField('reason')} maxLength={255} />
            <TextAreaField label="Notes" name="notes" value={form.notes} onChange={setField('notes')} rows={3} />
          </div>
          <p className={styles.hint}>Only metadata can be edited here — transferring a patient between beds is done by discharging and re-admitting.</p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setEditing(null)} disabled={update.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              onClick={() =>
                update.mutate({
                  id: editing.id,
                  payload: {
                    practitioner_id: form.practitioner_id ? Number(form.practitioner_id) : null,
                    reason: form.reason || null,
                    notes: form.notes || null,
                  },
                })
              }
              disabled={update.isPending}
            >
              {update.isPending ? 'Saving…' : 'Save changes'}
            </Button>
          </div>
        </Modal>
      ) : null}

      {confirm ? (
        <Modal
          title={confirm.kind === 'discharge' ? 'Discharge patient?' : 'Delete admission?'}
          onClose={() => setConfirm(null)}
        >
          <p className={styles.deleteLead}>
            {confirm.kind === 'discharge' ? (
              <>
                Discharge <strong>{confirm.admission.patient?.name ?? 'this patient'}</strong> from bed{' '}
                <strong>{confirm.admission.bed?.bedNumber ?? '—'}</strong>? The bed will be released and the admission history kept.
              </>
            ) : (
              <>
                Deleting admission <strong>#{confirm.admission.id}</strong> for{' '}
                <strong>{confirm.admission.patient?.name ?? 'this patient'}</strong> is permanent.
                {confirm.admission.status === 'admitted' ? ' The bed will be released.' : ''}
              </>
            )}
          </p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setConfirm(null)} disabled={isBusy}>
              Cancel
            </Button>
            <Button
              variant="primary"
              className={confirm.kind === 'delete' ? styles.dangerButton : formStyles.confirmButton}
              onClick={() => (confirm.kind === 'discharge' ? discharge.mutate(confirm.admission) : remove.mutate(confirm.admission))}
              disabled={isBusy}
            >
              {isBusy
                ? confirm.kind === 'discharge' ? 'Discharging…' : 'Deleting…'
                : confirm.kind === 'discharge' ? 'Discharge' : 'Delete'}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

type ChangeEventHandler = (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => void;

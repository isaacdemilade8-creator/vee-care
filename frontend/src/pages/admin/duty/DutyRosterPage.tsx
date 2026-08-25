import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Ban, Plus, Trash2 } from 'lucide-react';
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
import { useAdminUsers, useDepartments, useDuties, useShifts, useWards } from '../../../hooks/useApi';
import { endpoints } from '../../../services/endpoints';
import { toOptions } from '../structure/structure';
import { CellText, StructureStatusPill } from '../structure/StructurePage';
import styles from '../structure/StructurePage.module.scss';
import formStyles from './DutyPages.module.scss';
import type { DutyAssignment, DutyStatus } from '../../../types';
import { formatDate } from '../../../utils/format';
import { getValidationErrors } from '../../../utils/apiError';

const STATUS_FILTER: Array<{ value: '' | DutyStatus; label: string }> = [
  { value: '', label: 'All statuses' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
];

const EMPTY_FORM = {
  practitioner_id: '',
  shift_id: '',
  department_id: '',
  ward_id: '',
  duty_date: '',
  notes: '',
};

type DutyForm = typeof EMPTY_FORM;

type ChangeEventHandler = (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => void;

/**
 * Hospital duty roster: who works which shift on which date. Assignments are
 * created here and cancelled (kept for history) rather than silently deleted;
 * the backend refuses overlapping duties for the same practitioner.
 */
export function DutyRosterPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState<DutyForm>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [detail, setDetail] = useState<DutyAssignment | null>(null);
  const [confirm, setConfirm] = useState<{ kind: 'cancel' | 'delete'; duty: DutyAssignment } | null>(null);

  const params: Record<string, string> = { page: String(page), per_page: '10' };
  if (search.trim()) params.search = search.trim();
  if (status) params.status = status;

  const { data, isLoading, isError, refetch } = useDuties(params);
  const duties = data?.data ?? [];
  const total = data?.meta?.total ?? 0;
  const totalPages = data?.meta?.last_page ?? 1;
  const currentPage = data?.meta?.current_page ?? 1;

  const doctors = useAdminUsers({ role: 'doctor', per_page: '100' });
  const nurses = useAdminUsers({ role: 'nurse', per_page: '100' });
  const pharmacists = useAdminUsers({ role: 'pharmacist', per_page: '100' });
  const practitionerOptions = [
    ...toOptions(doctors.data?.data ?? []),
    ...toOptions(nurses.data?.data ?? []),
    ...toOptions(pharmacists.data?.data ?? []),
  ];

  const shifts = useShifts({ per_page: '100', status: 'active' });
  const shiftOptions = toOptions(shifts.data?.data ?? []);

  const departments = useDepartments({ per_page: '100' });
  const departmentOptions = toOptions(departments.data?.data ?? []);

  const wardFilters: Record<string, string> = { per_page: '100' };
  if (form.department_id) wardFilters.department_id = form.department_id;
  const wards = useWards(wardFilters);
  const wardOptions = toOptions(wards.data?.data ?? []);

  const invalidate = () =>
    void queryClient.invalidateQueries({
      queryKey: ['admin-duties'],
      refetchType: 'all',
    });

  const refreshCurrentDuty = () =>
    void queryClient.invalidateQueries({
      queryKey: ['admin-duties-current'],
      refetchType: 'all',
    });

  const setField = (name: keyof DutyForm): ChangeEventHandler => (event) => {
    setForm((prev) => ({ ...prev, [name]: event.target.value }));
    if (formErrors[name]) {
      setFormErrors((prev) => ({ ...prev, [name]: '' }));
    }
  };

  const onDepartmentChange: ChangeEventHandler = (event) => {
    setField('department_id')(event);
    setForm((prev) => ({ ...prev, ward_id: '' }));
  };

  const openCreate = () => {
    setForm(EMPTY_FORM);
    setFormErrors({});
    setCreateOpen(true);
  };

  const submitCreate = () => {
    const errors: Record<string, string> = {};
    if (!form.practitioner_id) errors.practitioner_id = 'Practitioner is required';
    if (!form.shift_id) errors.shift_id = 'Shift is required';
    if (!form.department_id) errors.department_id = 'Department is required';
    if (!form.duty_date) errors.duty_date = 'Duty date is required';
    setFormErrors(errors);
    if (Object.keys(errors).length) {
      return;
    }

    create.mutate({
      practitioner_id: Number(form.practitioner_id),
      shift_id: Number(form.shift_id),
      department_id: Number(form.department_id),
      ward_id: form.ward_id ? Number(form.ward_id) : null,
      duty_date: form.duty_date,
      notes: form.notes || null,
    });
  };

  const create = useMutation({
    mutationFn: (payload: Record<string, unknown>) => endpoints.createDuty(payload),
    onSuccess: async () => {
      await invalidate();
      refreshCurrentDuty();
      toast.success('Practitioner assigned to shift.');
      setCreateOpen(false);
    },
    onError: (error) => setFormErrors(getValidationErrors(error)),
  });

  const cancel = useMutation({
    mutationFn: (duty: DutyAssignment) => endpoints.updateDuty(duty.id, { status: 'cancelled' }),
    onSuccess: async () => {
      await invalidate();
      refreshCurrentDuty();
      toast.success('Duty cancelled. The record is kept for history.');
      setConfirm(null);
      setDetail(null);
    },
  });

  const remove = useMutation({
    mutationFn: (duty: DutyAssignment) => endpoints.deleteDuty(duty.id),
    onSuccess: async () => {
      await invalidate();
      refreshCurrentDuty();
      toast.success('Duty assignment deleted.');
      setConfirm(null);
      setDetail(null);
    },
  });

  const isBusy = create.isPending || cancel.isPending || remove.isPending;

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Hospital operations</span>
          <h1>Duty Roster</h1>
          <p>Schedule practitioners onto shifts. A practitioner cannot hold two overlapping duties; history is never rewritten.</p>
        </div>
        <Button variant="primary" onClick={openCreate} disabled={isBusy}>
          <Plus size={16} /> New assignment
        </Button>
      </header>

      <div className={styles.filters}>
        <div className={styles.search}>
          <input
            type="search"
            placeholder="Search practitioners…"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            aria-label="Search duties"
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
          title="Unable to load duty assignments"
          description="The duty roster could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void refetch()}>Try again</Button>}
        />
      ) : duties.length === 0 ? (
        <DataStatePanel
          title={search || status ? 'No assignments match your filters' : 'No duty assignments yet'}
          description={search || status ? 'Try adjusting the search or status filter.' : 'Assign the first practitioner to a shift to get started.'}
        />
      ) : (
        <Card className={styles.tableCard}>
          <div className={styles.table}>
            <div className={styles.tableHead} role="row" style={{ gridTemplateColumns: '1.7fr 1.3fr 1.4fr 0.9fr 0.9fr' }}>
              <span role="columnheader">Practitioner</span>
              <span role="columnheader">Shift</span>
              <span role="columnheader">Location</span>
              <span role="columnheader">Date</span>
              <span role="columnheader" className={styles.actionsCol}>Status</span>
            </div>
            {duties.map((duty) => (
              <div
                className={styles.row}
                role="row"
                key={duty.id}
                style={{ gridTemplateColumns: '1.7fr 1.3fr 1.4fr 0.9fr 0.9fr', cursor: 'pointer' }}
                onClick={() => setDetail(duty)}
              >
                <span role="cell">
                  <CellText title={duty.practitioner?.name ?? '—'} sub={duty.practitioner?.role.replace(/_/g, ' ')} />
                </span>
                <span role="cell">
                  <CellText title={duty.shift?.name ?? '—'} sub={duty.shift ? `${duty.shift.startTime} – ${duty.shift.endTime}` : undefined} />
                </span>
                <span role="cell">
                  <CellText title={duty.ward?.name ?? 'No ward'} sub={duty.department?.name} />
                </span>
                <span role="cell" className={styles.date}>{duty.dutyDate ? formatDate(duty.dutyDate) : '—'}</span>
                <span role="cell" className={styles.actionsCol}>
                  <StructureStatusPill value={duty.status === 'completed' ? 'active' : duty.status === 'cancelled' ? 'inactive' : 'reserved'} />
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {!isLoading && !isError && duties.length > 0 ? (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          total={total}
          onPageChange={setPage}
          itemLabel="assignments"
        />
      ) : null}

      {createOpen ? (
        <Modal title="New duty assignment" onClose={() => setCreateOpen(false)}>
          <div className={formStyles.formGrid}>
            <SelectField label="Practitioner" name="practitioner_id" value={form.practitioner_id} onChange={setField('practitioner_id')} error={formErrors.practitioner_id} required>
              <option value="">Select a practitioner…</option>
              {practitionerOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>

            <SelectField label="Shift" name="shift_id" value={form.shift_id} onChange={setField('shift_id')} error={formErrors.shift_id} required>
              <option value="">Select an active shift…</option>
              {shiftOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>

            <SelectField label="Department" name="department_id" value={form.department_id} onChange={onDepartmentChange} error={formErrors.department_id} required>
              <option value="">Select a department…</option>
              {departmentOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>

            <SelectField label="Ward (optional)" name="ward_id" value={form.ward_id} onChange={setField('ward_id')} error={formErrors.ward_id}>
              <option value="">{form.department_id ? 'None' : 'Select a department first…'}</option>
              {wardOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </SelectField>
          </div>

          <div className={styles.form}>
            <TextField label="Duty date" name="duty_date" type="date" value={form.duty_date} onChange={setField('duty_date')} error={formErrors.duty_date} required />
            <TextAreaField label="Notes" name="notes" value={form.notes} onChange={setField('notes')} placeholder="Handover notes for this duty…" rows={3} maxLength={1000} />
          </div>

          <p className={styles.hint}>The practitioner must not already hold an overlapping duty on this date.</p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setCreateOpen(false)} disabled={create.isPending}>
              Cancel
            </Button>
            <Button variant="primary" onClick={submitCreate} disabled={create.isPending}>
              {create.isPending ? 'Assigning…' : 'Assign practitioner'}
            </Button>
          </div>
        </Modal>
      ) : null}

      {detail ? (
        <Modal title={`Duty assignment #${detail.id}`} onClose={() => setDetail(null)}>
          <div className={formStyles.detailGrid}>
            <div>
              <span className={formStyles.detailLabel}>Practitioner</span>
              <span className={formStyles.detailValue}>{detail.practitioner?.name ?? '—'}</span>
              <span className={formStyles.detailSub}>{detail.practitioner?.role.replace(/_/g, ' ')}</span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Status</span>
              <span><StructureStatusPill value={detail.status === 'completed' ? 'active' : detail.status === 'cancelled' ? 'inactive' : 'reserved'} /></span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Shift</span>
              <span className={formStyles.detailValue}>{detail.shift?.name ?? '—'}</span>
              <span className={formStyles.detailSub}>{detail.shift ? `${detail.shift.startTime} – ${detail.shift.endTime}` : ''}</span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Location</span>
              <span className={formStyles.detailValue}>{detail.ward?.name ?? 'No ward'}</span>
              <span className={formStyles.detailSub}>{detail.department?.name}</span>
            </div>
            <div>
              <span className={formStyles.detailLabel}>Date</span>
              <span className={formStyles.detailValue}>{detail.dutyDate ? formatDate(detail.dutyDate) : '—'}</span>
            </div>
            {detail.notes ? (
              <div>
                <span className={formStyles.detailLabel}>Notes</span>
                <span className={formStyles.detailValue}>{detail.notes}</span>
              </div>
            ) : null}
          </div>

          <div className={styles.modalActions}>
            {detail.status === 'scheduled' ? (
              <Button
                variant="secondary"
                className={formStyles.confirmButton}
                disabled={isBusy}
                onClick={() => setConfirm({ kind: 'cancel', duty: detail })}
              >
                <Ban size={15} /> Cancel duty
              </Button>
            ) : null}
            {detail.status === 'scheduled' ? (
              <Button
                variant="ghost"
                className={styles.deleteButton}
                disabled={isBusy}
                onClick={() => setConfirm({ kind: 'delete', duty: detail })}
              >
                <Trash2 size={15} /> Delete
              </Button>
            ) : null}
            <Button variant="primary" onClick={() => setDetail(null)}>
              Close
            </Button>
          </div>
        </Modal>
      ) : null}

      {confirm ? (
        <Modal
          title={confirm.kind === 'cancel' ? 'Cancel duty?' : 'Delete assignment?'}
          onClose={() => setConfirm(null)}
        >
          <p className={styles.deleteLead}>
            {confirm.kind === 'cancel' ? (
              <>
                Cancel the duty of <strong>{confirm.duty.practitioner?.name ?? 'this practitioner'}</strong> on{' '}
                <strong>{formatDate(confirm.duty.dutyDate)}</strong>? The record is kept for history but frees the slot.
              </>
            ) : (
              <>
                Deleting the assignment of <strong>{confirm.duty.practitioner?.name ?? 'this practitioner'}</strong> on{' '}
                <strong>{formatDate(confirm.duty.dutyDate)}</strong> is permanent and only possible before the shift has started.
              </>
            )}
          </p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setConfirm(null)} disabled={isBusy}>
              Keep it
            </Button>
            <Button
              variant="primary"
              className={confirm.kind === 'delete' ? styles.dangerButton : formStyles.confirmButton}
              onClick={() => (confirm.kind === 'cancel' ? cancel.mutate(confirm.duty) : remove.mutate(confirm.duty))}
              disabled={isBusy}
            >
              {isBusy ? (confirm.kind === 'cancel' ? 'Cancelling…' : 'Deleting…') : confirm.kind === 'cancel' ? 'Cancel duty' : 'Delete'}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

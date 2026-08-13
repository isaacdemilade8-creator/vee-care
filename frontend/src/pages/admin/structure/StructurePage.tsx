import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Power, Trash2 } from 'lucide-react';
import type { ChangeEvent, ReactNode } from 'react';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { Button } from '../../../components/Button';
import { Card } from '../../../components/Card';
import { DataStatePanel } from '../../../components/DataStatePanel';
import { SelectField, TextAreaField, TextField } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { SkeletonRows } from '../../../components/Skeleton';
import type { Paginated } from '../../../types';
import { getValidationErrors } from '../../../utils/apiError';
import styles from './StructurePage.module.scss';

export interface StructureField {
  name: string;
  label: string;
  type: 'text' | 'number' | 'select' | 'textarea';
  required: boolean;
  nullable?: boolean;
  options?: Array<{ value: string; label: string }>;
  placeholder?: string;
  maxLength?: number;
  min?: number;
  max?: number;
  hint?: string;
}

export interface StructureFilter {
  key: string;
  options: Array<{ value: string; label: string }>;
}

export interface StructureColumn<T> {
  label: string;
  render: (row: T) => ReactNode;
}

export interface StructureConfig<T extends { id: number }> {
  breadcrumb: string;
  title: string;
  description: string;
  singular: string;
  plural: string;
  createLabel: string;
  createTitle: string;
  editTitle: string;
  searchPlaceholder: string;
  gridColumns: string;
  queryKey: string[];
  columns: Array<StructureColumn<T>>;
  fields: StructureField[];
  filters?: StructureFilter[];
  list: (params?: Record<string, string>) => Promise<Paginated<T>>;
  create: (payload: Record<string, unknown>) => Promise<unknown>;
  update: (id: number, payload: Record<string, unknown>) => Promise<unknown>;
  remove: (id: number) => Promise<unknown>;
  /** Whether lifecycle toggling uses `is_active` (beds) or `status`. */
  toggleField?: 'status' | 'is_active';
  /** When set, delete is disabled while the row reports this many dependents. */
  dependentCountField?: keyof T;
}

const STATUS_META: Record<string, { label: string; cls: string }> = {
  active: { label: 'Active', cls: 'pillActive' },
  inactive: { label: 'Inactive', cls: 'pillInactive' },
  available: { label: 'Available', cls: 'pillAvailable' },
  occupied: { label: 'Occupied', cls: 'pillOccupied' },
  reserved: { label: 'Reserved', cls: 'pillReserved' },
  unavailable: { label: 'Unavailable', cls: 'pillUnavailable' },
};

export function StructureStatusPill({ value }: { value: string }) {
  const meta = STATUS_META[value] ?? { label: value.replace(/_/g, ' '), cls: 'pillDefault' };
  return (
    <span className={`${styles.pill} ${styles[meta.cls]}`}>
      <span className={styles.pillDot} aria-hidden="true" />
      {meta.label}
    </span>
  );
}

/** Primary/sub text pair for table cells (e.g. a name plus its parent). */
export function CellText({ title, sub }: { title: ReactNode; sub?: ReactNode }) {
  return (
    <>
      <span className={styles.name}>{title}</span>
      {sub ? <span className={styles.sub}>{sub}</span> : null}
    </>
  );
}

/**
 * Generic CRUD surface for the hospital structure entities (departments,
 * wards, rooms, beds). Each entity page supplies a configuration; the shared
 * component renders the filter bar, paginated table, create/edit modal, and
 * the lifecycle toggle / delete actions.
 */
export function StructurePage<T extends { id: number; isActive: boolean }>({ config }: { config: StructureConfig<T> }) {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [page, setPage] = useState(1);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<T | null>(null);
  const [form, setForm] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [deleteTarget, setDeleteTarget] = useState<T | null>(null);

  const params: Record<string, string> = { page: String(page), per_page: '10' };
  if (search.trim()) params.search = search.trim();
  for (const [key, value] of Object.entries(filters)) {
    if (value) params[key] = value;
  }

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: [...config.queryKey, params],
    queryFn: () => config.list(params),
  });

  const rows = data?.data ?? [];
  const total = data?.meta?.total ?? 0;
  const totalPages = data?.meta?.last_page ?? 1;
  const currentPage = data?.meta?.current_page ?? 1;

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: config.queryKey });

  const openCreate = () => {
    const initial: Record<string, string> = {};
    for (const field of config.fields) {
      if (field.type === 'select' && !field.nullable && field.options?.length) {
        initial[field.name] = field.options[0].value;
      } else {
        initial[field.name] = '';
      }
    }
    setForm(initial);
    setEditing(null);
    setErrors({});
    setModalOpen(true);
  };

  const openEdit = (row: T) => {
    const initial: Record<string, string> = {};
    for (const field of config.fields) {
      const value = (row as unknown as Record<string, unknown>)[field.name];
      initial[field.name] = value === null || value === undefined ? '' : String(value);
    }
    setForm(initial);
    setEditing(row);
    setErrors({});
    setModalOpen(true);
  };

  const buildPayload = (): Record<string, unknown> => {
    const payload: Record<string, unknown> = {};
    for (const field of config.fields) {
      const value = form[field.name] ?? '';
      if (field.type === 'number') {
        payload[field.name] = value === '' ? null : Number(value);
      } else if (value === '' && field.nullable) {
        payload[field.name] = null;
      } else {
        payload[field.name] = value;
      }
    }
    return payload;
  };

  const submit = () => {
    const newErrors: Record<string, string> = {};
    for (const field of config.fields) {
      const value = form[field.name] ?? '';
      if (field.required && !value.trim()) {
        newErrors[field.name] = `${field.label} is required`;
      }
      if (field.type === 'number' && value !== '') {
        const num = Number(value);
        if (!Number.isFinite(num)) {
          newErrors[field.name] = `${field.label} must be a number`;
        } else if (field.min !== undefined && num < field.min) {
          newErrors[field.name] = `Must be at least ${field.min}`;
        } else if (field.max !== undefined && num > field.max) {
          newErrors[field.name] = `Must be at most ${field.max}`;
        }
      }
    }
    setErrors(newErrors);
    if (Object.keys(newErrors).length) {
      return;
    }

    save.mutate({ id: editing?.id, payload: buildPayload() });
  };

  const save = useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: Record<string, unknown> }) =>
      id === undefined ? config.create(payload) : config.update(id, payload),
    onSuccess: async () => {
      await invalidate();
      toast.success(`${config.singular} ${editing ? 'updated' : 'created'}.`);
      setModalOpen(false);
    },
    onError: (error) => setErrors(getValidationErrors(error)),
  });

  const toggle = useMutation({
    mutationFn: (row: T) => {
      const payload = config.toggleField === 'is_active'
        ? { is_active: !row.isActive }
        : { status: row.isActive ? 'inactive' : 'active' };
      return config.update(row.id, payload);
    },
    onSuccess: async () => {
      await invalidate();
      toast.success('Status updated.');
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => config.remove(id),
    onSuccess: async () => {
      await invalidate();
      toast.success(`${config.singular} deleted.`);
      setDeleteTarget(null);
    },
  });

  const dependentCount = (row: T): number => {
    if (config.dependentCountField === undefined) {
      return 0;
    }
    return Number((row as unknown as Record<string, number>)[String(config.dependentCountField)] ?? 0);
  };

  const isBusy = save.isPending || toggle.isPending;

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>{config.breadcrumb}</span>
          <h1>{config.title}</h1>
          <p>{config.description}</p>
        </div>
        <Button variant="primary" onClick={openCreate}>
          <Plus size={16} /> {config.createLabel}
        </Button>
      </header>

      <div className={styles.filters}>
        <div className={styles.search}>
          <input
            type="search"
            placeholder={config.searchPlaceholder}
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            aria-label="Search"
          />
        </div>
        {config.filters?.map((filter) => (
          <select
            key={filter.key}
            className={styles.select}
            value={filters[filter.key] ?? ''}
            onChange={(event) => {
              setFilters((prev) => ({ ...prev, [filter.key]: event.target.value }));
              setPage(1);
            }}
            aria-label={`Filter by ${filter.key}`}
          >
            {filter.options.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        ))}
      </div>

      {isLoading ? (
        <Card><SkeletonRows rows={6} /></Card>
      ) : isError ? (
        <DataStatePanel
          title={`Unable to load ${config.plural}`}
          description="The hospital structure could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void refetch()}>Try again</Button>}
        />
      ) : rows.length === 0 ? (
        <DataStatePanel
          title={search || Object.values(filters).some(Boolean) ? `No ${config.plural} match your filters` : `No ${config.plural} yet`}
          description={search || Object.values(filters).some(Boolean) ? 'Try adjusting the search or filters.' : `Create the first ${config.singular.toLowerCase()} to get started.`}
        />
      ) : (
        <Card className={styles.tableCard}>
          <div className={styles.table}>
            <div className={styles.tableHead} role="row" style={{ gridTemplateColumns: config.gridColumns }}>
              {config.columns.map((column, index) => (
                <span role="columnheader" key={index}>{column.label}</span>
              ))}
              <span role="columnheader" className={styles.actionsCol}>Actions</span>
            </div>
            {rows.map((row) => (
              <div className={styles.row} role="row" key={row.id} style={{ gridTemplateColumns: config.gridColumns }}>
                {config.columns.map((column, index) => (
                  <span role="cell" key={index}>{column.render(row)}</span>
                ))}
                <span role="cell" className={styles.actionsCol}>
                  <Button
                    variant="ghost"
                    className={styles.iconButton}
                    disabled={isBusy}
                    onClick={() => toggle.mutate(row)}
                    title={row.isActive ? 'Deactivate' : 'Activate'}
                    aria-label={row.isActive ? 'Deactivate' : 'Activate'}
                  >
                    <Power size={15} />
                  </Button>
                  <Button
                    variant="ghost"
                    className={styles.iconButton}
                    disabled={isBusy}
                    onClick={() => openEdit(row)}
                    title="Edit"
                    aria-label="Edit"
                  >
                    <Pencil size={15} />
                  </Button>
                  <Button
                    variant="ghost"
                    className={`${styles.iconButton} ${styles.deleteButton}`}
                    disabled={isBusy || dependentCount(row) > 0}
                    onClick={() => setDeleteTarget(row)}
                    title={dependentCount(row) > 0 ? 'Deactivate instead — it still has records' : 'Delete'}
                    aria-label="Delete"
                  >
                    <Trash2 size={15} />
                  </Button>
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {!isLoading && !isError && rows.length > 0 ? (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          total={total}
          onPageChange={setPage}
          itemLabel={config.plural}
        />
      ) : null}

      {modalOpen ? (
        <Modal title={editing ? config.editTitle : config.createTitle} onClose={() => setModalOpen(false)}>
          <div className={styles.form}>
            {config.fields.map((field) => {
              const shared = {
                label: field.label,
                name: field.name,
                value: form[field.name] ?? '',
                error: errors[field.name],
                onChange: (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
                  setForm((prev) => ({ ...prev, [field.name]: event.target.value }));
                  if (errors[field.name]) setErrors((prev) => ({ ...prev, [field.name]: '' }));
                },
              };

              if (field.type === 'select') {
                return (
                  <SelectField key={field.name} {...shared} required={field.required}>
                    {field.nullable ? <option value="">None</option> : null}
                    {field.options?.map((option) => (
                      <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                  </SelectField>
                );
              }

              if (field.type === 'textarea') {
                return <TextAreaField key={field.name} {...shared} placeholder={field.placeholder} maxLength={field.maxLength} rows={3} />;
              }

              return (
                <TextField
                  key={field.name}
                  {...shared}
                  type={field.type === 'number' ? 'number' : 'text'}
                  placeholder={field.placeholder}
                  maxLength={field.maxLength}
                  min={field.min}
                  max={field.max}
                  required={field.required}
                />
              );
            })}
          </div>
          {config.fields.filter((field) => field.hint).map((field) => (
            <p className={styles.hint} key={field.name}>{field.hint}</p>
          ))}
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setModalOpen(false)} disabled={save.isPending}>
              Cancel
            </Button>
            <Button variant="primary" onClick={submit} disabled={save.isPending}>
              {save.isPending ? 'Saving…' : editing ? 'Save changes' : `Create ${config.singular.toLowerCase()}`}
            </Button>
          </div>
        </Modal>
      ) : null}

      {deleteTarget ? (
        <Modal title={`Delete ${config.singular.toLowerCase()}?`} onClose={() => setDeleteTarget(null)}>
          <p className={styles.deleteLead}>
            Deleting <strong>{String((deleteTarget as unknown as Record<string, unknown>).name ?? 'this record')}</strong>{' '}
            is permanent. If it has any {config.plural} records below it, the deletion will be refused — deactivate it instead.
          </p>
          <div className={styles.modalActions}>
            <Button variant="ghost" onClick={() => setDeleteTarget(null)} disabled={remove.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              className={styles.dangerButton}
              onClick={() => remove.mutate(deleteTarget.id)}
              disabled={remove.isPending}
            >
              {remove.isPending ? 'Deleting…' : 'Delete'}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

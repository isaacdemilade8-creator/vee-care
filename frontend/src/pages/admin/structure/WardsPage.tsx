import { CellText, StructurePage, StructureStatusPill, type StructureConfig } from './StructurePage';
import { toOptions } from './structure';
import type { Ward, WardType } from '../../../types';
import { useDepartments } from '../../../hooks/useApi';
import { endpoints } from '../../../services/endpoints';
import { formatDate } from '../../../utils/format';

const STATUS_FILTER = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

const WARD_TYPES: Array<{ value: WardType; label: string }> = [
  { value: 'general', label: 'General' },
  { value: 'private', label: 'Private' },
  { value: 'semi_private', label: 'Semi-private' },
  { value: 'isolation', label: 'Isolation' },
  { value: 'icu', label: 'ICU' },
  { value: 'maternity', label: 'Maternity' },
  { value: 'pediatric', label: 'Pediatric' },
  { value: 'surgical', label: 'Surgical' },
  { value: 'emergency', label: 'Emergency' },
];

function typeLabel(value: WardType | null | undefined): string {
  return WARD_TYPES.find((option) => option.value === value)?.label ?? '—';
}

export function WardsPage() {
  const departments = useDepartments({ per_page: '100' });
  const departmentOptions = toOptions(departments.data?.data ?? []);

  const config: StructureConfig<Ward> = {
    breadcrumb: 'Hospital operations',
    title: 'Wards',
    description: 'Group rooms under wards, optionally linked to a department. Ward capacity covers all its rooms.',
    singular: 'Ward',
    plural: 'wards',
    createLabel: 'New ward',
    createTitle: 'New ward',
    editTitle: 'Edit ward',
    searchPlaceholder: 'Search wards…',
    gridColumns: '2fr 1fr 0.7fr 0.6fr 0.6fr 0.8fr 0.9fr 0.8fr',
    queryKey: ['admin-wards'],
    columns: [
      {
        label: 'Ward',
        render: (row) => (
          <CellText
            title={row.name}
            sub={row.department ? `in ${row.department.name}` : 'No department'}
          />
        ),
      },
      {
        label: 'Type',
        render: (row) => <span>{typeLabel(row.type)}</span>,
      },
      {
        label: 'Capacity',
        render: (row) => <span>{row.capacity}</span>,
      },
      {
        label: 'Rooms',
        render: (row) => <span>{row.roomsCount ?? 0}</span>,
      },
      {
        label: 'Beds',
        render: (row) => <span>{row.bedsCount ?? 0}</span>,
      },
      {
        label: 'Status',
        render: (row) => <StructureStatusPill value={row.status} />,
      },
      {
        label: 'Created',
        render: (row) => <span className="date">{row.createdAt ? formatDate(row.createdAt) : '—'}</span>,
      },
    ],
    fields: [
      {
        name: 'department_id',
        label: 'Department',
        type: 'select',
        required: false,
        nullable: true,
        options: departmentOptions,
      },
      { name: 'name', label: 'Ward name', type: 'text', required: true, placeholder: 'e.g. Cardiology Ward', maxLength: 255 },
      {
        name: 'type',
        label: 'Ward type',
        type: 'select',
        required: false,
        nullable: true,
        options: WARD_TYPES,
      },
      {
        name: 'capacity',
        label: 'Capacity (beds)',
        type: 'number',
        required: true,
        min: 0,
        max: 10000,
        hint: 'Total beds the ward can hold across all rooms.',
      },
    ],
    filters: [
      { key: 'department_id', options: [{ value: '', label: 'All departments' }, ...departmentOptions] },
      { key: 'status', options: STATUS_FILTER },
    ],
    list: async (params) => (await endpoints.wards(params)).data,
    create: (payload) => endpoints.createWard(payload),
    update: (id, payload) => endpoints.updateWard(id, payload),
    remove: (id) => endpoints.deleteWard(id),
    dependentCountField: 'roomsCount',
  };

  return <StructurePage config={config} />;
}

import { CellText, StructurePage, StructureStatusPill, type StructureConfig } from '../structure/StructurePage';
import type { Shift } from '../../../types';
import { endpoints } from '../../../services/endpoints';
import { formatDate } from '../../../utils/format';

/**
 * Row projected for the shared structure table: `isActive` drives the
 * lifecycle toggle and the snake_case time aliases let the edit modal prefill
 * from the camelCase API resource while submitting snake_case payloads.
 */
type ShiftRow = Shift & { isActive: boolean; start_time: string; end_time: string };

const toRow = (shift: Shift): ShiftRow => ({
  ...shift,
  isActive: shift.status === 'active',
  start_time: shift.startTime,
  end_time: shift.endTime,
});

const STATUS_FILTER = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

const config: StructureConfig<ShiftRow> = {
  breadcrumb: 'Hospital operations',
  title: 'Shifts',
  description: 'Define reusable working periods. An end earlier than the start spans midnight (e.g. Night 22:00 → 06:00).',
  singular: 'Shift',
  plural: 'shifts',
  createLabel: 'New shift',
  createTitle: 'New shift',
  editTitle: 'Edit shift',
  searchPlaceholder: 'Search shifts…',
  gridColumns: '2fr 1.1fr 0.7fr 0.8fr 0.9fr',
  queryKey: ['admin-shifts'],
  columns: [
    {
      label: 'Shift',
      render: (row) => (
        <CellText
          title={row.name}
          sub={row.description || 'No description'}
        />
      ),
    },
    {
      label: 'Hours',
      render: (row) => (
        <span className="date">
          {row.startTime} – {row.endTime}
        </span>
      ),
    },
    {
      label: 'Duties',
      render: (row) => <span>{row.dutiesCount ?? 0}</span>,
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
    { name: 'name', label: 'Shift name', type: 'text', required: true, placeholder: 'e.g. Morning', maxLength: 100 },
    { name: 'start_time', label: 'Start time', type: 'text', required: true, placeholder: 'e.g. 08:00', maxLength: 5 },
    {
      name: 'end_time',
      label: 'End time',
      type: 'text',
      required: true,
      placeholder: 'e.g. 16:00',
      maxLength: 5,
      hint: 'Times use 24-hour HH:MM hospital-local wall clock.',
    },
    { name: 'description', label: 'Description', type: 'textarea', required: false, placeholder: 'What is this shift for?', maxLength: 1000 },
  ],
  filters: [{ key: 'status', options: STATUS_FILTER }],
  list: async (params) => {
    const page = await endpoints.shifts(params);
    return { ...page.data, data: page.data.data.map(toRow) };
  },
  create: (payload) => endpoints.createShift(payload),
  update: (id, payload) => endpoints.updateShift(id, payload),
  remove: (id) => endpoints.deleteShift(id),
  dependentCountField: 'dutiesCount',
};

export function ShiftsPage() {
  return <StructurePage config={config} />;
}

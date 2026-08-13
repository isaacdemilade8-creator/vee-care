import { CellText, StructurePage, StructureStatusPill, type StructureConfig } from './StructurePage';
import { toOptions } from './structure';
import type { Room } from '../../../types';
import { useWards } from '../../../hooks/useApi';
import { endpoints } from '../../../services/endpoints';
import { formatDate } from '../../../utils/format';

const STATUS_FILTER = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

export function RoomsPage() {
  const wards = useWards({ per_page: '100' });
  const wardOptions = toOptions(wards.data?.data ?? []);

  const config: StructureConfig<Room> = {
    breadcrumb: 'Hospital operations',
    title: 'Rooms',
    description: 'Create rooms within a ward. A room holds up to its capacity in beds, and the ward capacity covers all its rooms.',
    singular: 'Room',
    plural: 'rooms',
    createLabel: 'New room',
    createTitle: 'New room',
    editTitle: 'Edit room',
    searchPlaceholder: 'Search rooms…',
    gridColumns: '2fr 0.8fr 0.7fr 0.9fr 1fr 0.8fr',
    queryKey: ['admin-rooms'],
    columns: [
      {
        label: 'Room',
        render: (row) => (
          <CellText
            title={row.name}
            sub={row.ward ? `in ${row.ward.name}` : 'No ward'}
          />
        ),
      },
      {
        label: 'Capacity',
        render: (row) => <span>{row.capacity}</span>,
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
        name: 'ward_id',
        label: 'Ward',
        type: 'select',
        required: true,
        options: wardOptions,
      },
      { name: 'name', label: 'Room name', type: 'text', required: true, placeholder: 'e.g. 101', maxLength: 255 },
      {
        name: 'capacity',
        label: 'Capacity (beds)',
        type: 'number',
        required: true,
        min: 1,
        max: 10000,
        hint: 'The room cannot exceed the remaining capacity of its ward.',
      },
    ],
    filters: [
      { key: 'ward_id', options: [{ value: '', label: 'All wards' }, ...wardOptions] },
      { key: 'status', options: STATUS_FILTER },
    ],
    list: async (params) => (await endpoints.rooms(params)).data,
    create: (payload) => endpoints.createRoom(payload),
    update: (id, payload) => endpoints.updateRoom(id, payload),
    remove: (id) => endpoints.deleteRoom(id),
    dependentCountField: 'bedsCount',
  };

  return <StructurePage config={config} />;
}

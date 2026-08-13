import { CellText, StructurePage, StructureStatusPill, type StructureConfig } from './StructurePage';
import { toOptions } from './structure';
import type { Bed, BedStatus } from '../../../types';
import { useRooms } from '../../../hooks/useApi';
import { endpoints } from '../../../services/endpoints';
import { formatDate } from '../../../utils/format';

const STATUS_FILTER: Array<{ value: '' | BedStatus; label: string }> = [
  { value: '', label: 'All statuses' },
  { value: 'available', label: 'Available' },
  { value: 'occupied', label: 'Occupied' },
  { value: 'reserved', label: 'Reserved' },
  { value: 'unavailable', label: 'Unavailable' },
];

export function BedsPage() {
  const rooms = useRooms({ per_page: '100' });
  const roomOptions = toOptions(rooms.data?.data ?? []);

  const config: StructureConfig<Bed> = {
    breadcrumb: 'Hospital operations',
    title: 'Beds',
    description: 'Track individual beds within a room. Occupied or reserved beds cannot be deactivated.',
    singular: 'Bed',
    plural: 'beds',
    createLabel: 'New bed',
    createTitle: 'New bed',
    editTitle: 'Edit bed',
    searchPlaceholder: 'Search bed numbers…',
    gridColumns: '2fr 1fr 0.8fr 1fr 0.8fr',
    queryKey: ['admin-beds'],
    columns: [
      {
        label: 'Bed',
        render: (row) => (
          <CellText
            title={row.bedNumber}
            sub={
              row.room
                ? `${row.room.name}${row.room.ward ? ` · ${row.room.ward.name}` : ''}`
                : 'No room'
            }
          />
        ),
      },
      {
        label: 'Status',
        render: (row) => <StructureStatusPill value={row.status} />,
      },
      {
        label: 'Active',
        render: (row) => <StructureStatusPill value={row.isActive ? 'active' : 'inactive'} />,
      },
      {
        label: 'Created',
        render: (row) => <span className="date">{row.createdAt ? formatDate(row.createdAt) : '—'}</span>,
      },
    ],
    fields: [
      {
        name: 'room_id',
        label: 'Room',
        type: 'select',
        required: true,
        options: roomOptions,
      },
      { name: 'bed_number', label: 'Bed number', type: 'text', required: true, placeholder: 'e.g. 101A', maxLength: 60 },
      {
        name: 'status',
        label: 'Operational status',
        type: 'select',
        required: true,
        options: STATUS_FILTER.filter((option): option is { value: BedStatus; label: string } => option.value !== ''),
      },
    ],
    filters: [
      { key: 'room_id', options: [{ value: '', label: 'All rooms' }, ...roomOptions] },
      { key: 'status', options: STATUS_FILTER },
    ],
    list: async (params) => (await endpoints.beds(params)).data,
    create: (payload) => endpoints.createBed(payload),
    update: (id, payload) => endpoints.updateBed(id, payload),
    remove: (id) => endpoints.deleteBed(id),
    toggleField: 'is_active',
  };

  return <StructurePage config={config} />;
}

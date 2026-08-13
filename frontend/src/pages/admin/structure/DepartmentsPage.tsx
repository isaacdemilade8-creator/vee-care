import { CellText, StructurePage, StructureStatusPill, type StructureConfig } from './StructurePage';
import type { Department } from '../../../types';
import { endpoints } from '../../../services/endpoints';
import { formatDate } from '../../../utils/format';

const STATUS_FILTER = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

const config: StructureConfig<Department> = {
  breadcrumb: 'Hospital operations',
  title: 'Departments',
  description: 'Organize the hospital into clinical departments. A department can be deactivated but only deleted once empty.',
  singular: 'Department',
  plural: 'departments',
  createLabel: 'New department',
  createTitle: 'New department',
  editTitle: 'Edit department',
  searchPlaceholder: 'Search departments…',
  gridColumns: '2fr 0.8fr 0.9fr 1fr 0.7fr',
  queryKey: ['admin-departments'],
  columns: [
    {
      label: 'Department',
      render: (row) => (
        <CellText
          title={row.name}
          sub={row.description || 'No description'}
        />
      ),
    },
    {
      label: 'Wards',
      render: (row) => <span>{row.wardsCount ?? 0}</span>,
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
    { name: 'name', label: 'Department name', type: 'text', required: true, placeholder: 'e.g. Cardiology', maxLength: 255 },
    { name: 'description', label: 'Description', type: 'textarea', required: false, placeholder: 'What does this department do?', maxLength: 2000 },
  ],
  filters: [{ key: 'status', options: STATUS_FILTER }],
  list: async (params) => (await endpoints.departments(params)).data,
  create: (payload) => endpoints.createDepartment(payload),
  update: (id, payload) => endpoints.updateDepartment(id, payload),
  remove: (id) => endpoints.deleteDepartment(id),
  dependentCountField: 'wardsCount',
};

export function DepartmentsPage() {
  return <StructurePage config={config} />;
}

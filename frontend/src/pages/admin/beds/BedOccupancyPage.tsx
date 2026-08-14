import { useQuery } from '@tanstack/react-query';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { Button } from '../../../components/Button';
import { Card } from '../../../components/Card';
import { DataStatePanel } from '../../../components/DataStatePanel';
import { Pagination } from '../../../components/Pagination';
import { SkeletonRows } from '../../../components/Skeleton';
import { useDepartments } from '../../../hooks/useApi';
import { endpoints } from '../../../services/endpoints';
import { toOptions } from '../structure/structure';
import { StructureStatusPill } from '../structure/StructurePage';
import styles from '../structure/StructurePage.module.scss';
import occStyles from './BedOccupancyPage.module.scss';
import type { WardOccupancy } from '../../../types';

export function BedOccupancyPage() {
  const [departmentId, setDepartmentId] = useState('');
  const [page, setPage] = useState(1);
  const [expanded, setExpanded] = useState<Set<number>>(new Set());

  const departments = useDepartments({ per_page: '100' });
  const departmentOptions = toOptions(departments.data?.data ?? []);

  const params: Record<string, string> = { page: String(page), per_page: '10' };
  if (departmentId) params.department_id = departmentId;

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['admin-occupancy', params],
    queryFn: async () => (await endpoints.wardOccupancy(params)).data,
  });

  const wards = data?.data ?? [];
  const total = data?.meta?.total ?? 0;
  const totalPages = data?.meta?.last_page ?? 1;
  const currentPage = data?.meta?.current_page ?? 1;

  const toggle = (id: number) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const bedLabel = (status: WardOccupancy['rooms'][number]['beds'][number]['status']) => status;

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Inpatient care</span>
          <h1>Bed dashboard</h1>
          <p>Live occupancy per ward: capacity, available, occupied, reserved and unavailable beds — including the room and bed layout.</p>
        </div>
      </header>

      <div className={styles.filters}>
        <select
          className={styles.select}
          value={departmentId}
          onChange={(event) => {
            setDepartmentId(event.target.value);
            setPage(1);
          }}
          aria-label="Filter by department"
        >
          <option value="">All departments</option>
          {departmentOptions.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </div>

      {isLoading ? (
        <Card><SkeletonRows rows={6} /></Card>
      ) : isError ? (
        <DataStatePanel
          title="Unable to load bed occupancy"
          description="The occupancy summary could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void refetch()}>Try again</Button>}
        />
      ) : wards.length === 0 ? (
        <DataStatePanel
          title="No wards found"
          description={departmentId ? 'This department has no wards.' : 'Create wards in Hospital structure to see their occupancy.'}
        />
      ) : (
        wards.map((ward) => (
          <Card key={ward.id} className={occStyles.wardCard}>
            <button type="button" className={occStyles.wardHeader} onClick={() => toggle(ward.id)} aria-expanded={expanded.has(ward.id)}>
              <div className={occStyles.wardTitle}>
                <ChevronDown size={16} className={expanded.has(ward.id) ? occStyles.expanded : ''} />
                <span className={styles.name}>{ward.name}</span>
                <span className={styles.sub}>{ward.department?.name ?? 'No department'}</span>
              </div>
              <div className={occStyles.stats}>
                <span className={occStyles.stat}>
                  <strong>{ward.totalBeds}</strong> beds
                </span>
                <span className={`${occStyles.stat} ${occStyles.available}`}>
                  <strong>{ward.availableBeds}</strong> available
                </span>
                <span className={`${occStyles.stat} ${occStyles.occupied}`}>
                  <strong>{ward.occupiedBeds}</strong> occupied
                </span>
                <span className={`${occStyles.stat} ${occStyles.reserved}`}>
                  <strong>{ward.reservedBeds}</strong> reserved
                </span>
                <span className={`${occStyles.stat} ${occStyles.unavailable}`}>
                  <strong>{ward.unavailableBeds}</strong> unavailable
                </span>
                <span className={occStyles.stat}>
                  <strong>{ward.capacity}</strong> capacity
                </span>
              </div>
            </button>

            {expanded.has(ward.id) ? (
              <div className={styles.tableCard}>
                <div className={styles.table}>
                  <div className={styles.tableHead} role="row" style={{ gridTemplateColumns: '1.5fr 0.8fr 1fr' }}>
                    <span role="columnheader">Room</span>
                    <span role="columnheader">Capacity</span>
                    <span role="columnheader">Beds</span>
                  </div>
                  {ward.rooms.length === 0 ? (
                    <div className={styles.row} role="row" style={{ gridTemplateColumns: '1fr' }}>
                      <span role="cell">No rooms in this ward yet.</span>
                    </div>
                  ) : (
                    ward.rooms.map((room) => (
                      <div className={styles.row} role="row" key={room.id} style={{ gridTemplateColumns: '1.5fr 0.8fr 1fr' }}>
                        <span role="cell" className={styles.name}>{room.name}</span>
                        <span role="cell">{room.capacity}</span>
                        <span role="cell" className={occStyles.bedList}>
                          {room.beds.length === 0 ? (
                            <span className={styles.date}>No beds yet</span>
                          ) : (
                            room.beds.map((bed) => (
                              <span key={bed.id} className={occStyles.bedChip}>
                                <StructureStatusPill value={bed.status} />
                                <span className={bedLabel(bed.status) === 'available' ? styles.name : styles.date}>
                                  {bed.bedNumber}
                                </span>
                              </span>
                            ))
                          )}
                        </span>
                      </div>
                    ))
                  )}
                </div>
              </div>
            ) : null}
          </Card>
        ))
      )}

      {!isLoading && !isError && wards.length > 0 ? (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          total={total}
          onPageChange={setPage}
          itemLabel="wards"
        />
      ) : null}
    </div>
  );
}

import { RefreshCw } from 'lucide-react';
import { Button } from '../../../components/Button';
import { Card } from '../../../components/Card';
import { DataStatePanel } from '../../../components/DataStatePanel';
import { SkeletonRows } from '../../../components/Skeleton';
import { useCurrentDuties } from '../../../hooks/useApi';
import { CellText } from '../structure/StructurePage';
import styles from '../structure/StructurePage.module.scss';
import formStyles from './DutyPages.module.scss';

/**
 * Live "who is on duty right now" board. The backend derives the answer from
 * duty dates, shift windows and the hospital's configured timezone; this page
 * renders the grouped teams and offers a manual refresh.
 */
export function CurrentDutyPage() {
  const { data, isLoading, isError, refetch, isFetching, dataUpdatedAt } = useCurrentDuties();
  const groups = data?.data ?? [];

  return (
    <div className={styles.page}>
      <header className={styles.heading}>
        <div>
          <span>Hospital operations</span>
          <h1>Current Duty</h1>
          <p>Practitioners whose shift window covers this moment, grouped by ward.</p>
        </div>
        <Button variant="secondary" onClick={() => void refetch()} disabled={isFetching}>
          <RefreshCw size={15} /> {isFetching ? 'Refreshing…' : 'Refresh'}
        </Button>
      </header>

      {data?.meta ? (
        <p className={formStyles.metaLine}>
          <span>As of {new Date(data.meta.asOf).toLocaleString()}</span>
          <span>·</span>
          <span>{data.meta.timezone}</span>
          <span>·</span>
          <span>{data.meta.date}</span>
          {dataUpdatedAt ? (
            <>
              <span>·</span>
              <span>updated {new Date(dataUpdatedAt).toLocaleTimeString()}</span>
            </>
          ) : null}
        </p>
      ) : null}

      {isLoading ? (
        <Card><SkeletonRows rows={4} /></Card>
      ) : isError ? (
        <DataStatePanel
          title="Unable to load the current duty board"
          description="The duty lookup could not be reached. Check your connection and try again."
          action={<Button variant="secondary" onClick={() => void refetch()}>Try again</Button>}
        />
      ) : groups.length === 0 ? (
        <DataStatePanel
          title="Nobody is on duty right now"
          description="Assign practitioners to shifts on the duty roster and they will appear here while their shift window is active."
        />
      ) : (
        <div className={formStyles.groupGrid}>
          {groups.map((group) => (
            <Card key={`${group.department?.id ?? 0}-${group.ward?.id ?? 0}-${group.shift.id}`} className={formStyles.groupCard}>
              <div className={formStyles.groupHeader}>
                <CellText
                  title={group.shift.name}
                  sub={`${group.shift.startTime} – ${group.shift.endTime}`}
                />
                <span className={formStyles.groupWhere}>
                  {group.ward ? group.ward.name : 'No ward'}
                  {group.department ? ` · ${group.department.name}` : ''}
                </span>
              </div>
              <div className={formStyles.teamList}>
                {group.practitioners.map((practitioner) => (
                  <div key={practitioner.id} className={formStyles.teamMember}>
                    <span className={formStyles.memberName}>{practitioner.name}</span>
                    <span className={formStyles.memberRole}>{practitioner.role.replace(/_/g, ' ')}</span>
                  </div>
                ))}
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

import styles from './Skeleton.module.scss';

export function SkeletonRows({ rows = 4 }: { rows?: number }) {
  return (
    <div className={styles.stack}>
      {Array.from({ length: rows }).map((_, index) => (
        <div className={styles.row} key={index} />
      ))}
    </div>
  );
}

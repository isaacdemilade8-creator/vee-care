import { ChevronLeft, ChevronRight } from 'lucide-react';
import styles from './Pagination.module.scss';

function getPageNumbers(current: number, total: number): (number | null)[] {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

  const pages: (number | null)[] = [];

  if (current <= 4) {
    for (let i = 1; i <= Math.min(5, total); i++) pages.push(i);
    if (total > 5) { pages.push(null); pages.push(total); }
  } else if (current >= total - 3) {
    pages.push(1);
    if (total > 5) pages.push(null);
    for (let i = Math.max(total - 4, 2); i <= total; i++) pages.push(i);
  } else {
    pages.push(1);
    pages.push(null);
    pages.push(current - 1);
    pages.push(current);
    pages.push(current + 1);
    pages.push(null);
    pages.push(total);
  }

  return pages;
}

interface PaginationProps {
  currentPage: number;
  totalPages: number;
  total: number;
  onPageChange: (page: number) => void;
  itemLabel?: string;
}

export function Pagination({ currentPage, totalPages, total, onPageChange, itemLabel = 'items' }: PaginationProps) {
  if (totalPages <= 1) {
    return null;
  }

  const pages = getPageNumbers(currentPage, totalPages);

  return (
    <div className={styles.pagination}>
      <div className={styles.pages}>
        <button
          type="button"
          className={styles.arrow}
          disabled={currentPage <= 1}
          onClick={() => onPageChange(Math.max(1, currentPage - 1))}
          aria-label="Previous page"
        >
          <ChevronLeft size={16} />
        </button>

        {pages.map((p, i) =>
          p === null ? (
            <span key={`ellipsis-${i}`} className={styles.ellipsis}>&hellip;</span>
          ) : (
            <button
              key={p}
              type="button"
              className={`${styles.pageNum} ${p === currentPage ? styles.pageNumActive : ''}`}
              onClick={() => onPageChange(p)}
              aria-label={`Page ${p}`}
              aria-current={p === currentPage ? 'page' : undefined}
            >
              {p}
            </button>
          ),
        )}

        <button
          type="button"
          className={styles.arrow}
          disabled={currentPage >= totalPages}
          onClick={() => onPageChange(Math.min(totalPages, currentPage + 1))}
          aria-label="Next page"
        >
          <ChevronRight size={16} />
        </button>
      </div>
      <span className={styles.info}>
        {total} {itemLabel}
      </span>
    </div>
  );
}

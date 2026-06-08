import type { ReactNode } from 'react';
import { X } from 'lucide-react';
import styles from './Modal.module.scss';

export function Modal({ title, children, onClose }: { title: string; children: ReactNode; onClose: () => void }) {
  return (
    <div className={styles.backdrop} role="dialog" aria-modal="true">
      <div className={styles.modal}>
        <header>
          <h2>{title}</h2>
          <button aria-label="Close" onClick={onClose}>
            <X size={18} />
          </button>
        </header>
        {children}
      </div>
    </div>
  );
}

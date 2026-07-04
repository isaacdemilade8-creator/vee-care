import { CreditCard, HeartPulse } from 'lucide-react';
import type { PatientCard } from '../types';
import styles from './VirtualCard.module.scss';

interface VirtualCardProps {
  card: PatientCard;
}

export function VirtualCard({ card }: VirtualCardProps) {
  const expiresText = card.expiresAt
    ? new Date(card.expiresAt).toLocaleDateString(undefined, { month: 'short', year: 'numeric' })
    : 'N/A';

  return (
    <div className={`${styles.card} ${card.status !== 'active' ? styles.inactive : ''}`}>
      <div className={styles.cardInner}>
        <div className={styles.cardFront}>
          <div className={styles.cardHeader}>
            <div className={styles.brand}>
              <HeartPulse size={22} />
              <span>Vee-care</span>
            </div>
            <CreditCard size={22} className={styles.chip} />
          </div>
          <div className={styles.cardNumber}>{card.cardNumber}</div>
          <div className={styles.cardMeta}>
            <div className={styles.cardHolder}>
              <span>Card holder</span>
              <strong>{card.patient?.name ?? 'Patient'}</strong>
            </div>
            <div className={styles.cardExpiry}>
              <span>Expires</span>
              <strong>{expiresText}</strong>
            </div>
          </div>
          {card.status !== 'active' ? (
            <div className={styles.statusOverlay}>
              <span className={styles.statusBadge}>{card.status}</span>
            </div>
          ) : null}
        </div>
      </div>
      {card.issuer ? (
        <p className={styles.issuerNote}>
          Issued by {card.issuer.name} &middot; {new Date(card.issuedAt).toLocaleDateString()}
        </p>
      ) : null}
    </div>
  );
}

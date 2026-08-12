import { CreditCard } from 'lucide-react';
import { useTenantName } from '../context/TenantContext';
import type { PatientCard } from '../types';
import styles from './VirtualCard.module.scss';
import { TenantBrandLogo } from './tenant/TenantBrandLogo';

interface VirtualCardProps {
  card: PatientCard;
}

export function VirtualCard({ card }: VirtualCardProps) {
  const tenantName = useTenantName();
  const expiresText = card.expiresAt
    ? new Date(card.expiresAt).toLocaleDateString(undefined, { month: 'short', year: 'numeric' })
    : 'N/A';

  return (
    <div className={`${styles.card} ${card.status !== 'active' ? styles.inactive : ''}`}>
      <div className={styles.cardInner}>
        <div className={styles.cardFront}>
          <div className={styles.cardHeader}>
            <div className={styles.brand}>
              <TenantBrandLogo size={22} alt={tenantName} />
              <span>{tenantName}</span>
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

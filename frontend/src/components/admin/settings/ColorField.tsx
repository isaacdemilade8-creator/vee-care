import { RotateCcw } from 'lucide-react';
import styles from './ColorField.module.scss';

const FALLBACK_HEX = '#0f766e';

function toHex(value: string | null): string {
  return typeof value === 'string' && /^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(value)
    ? value
    : FALLBACK_HEX;
}

/**
 * Branding colour editor. A native colour picker sits behind the swatch, the
 * raw value is shown beside it, and the reset button returns the field to
 * "inherit Vee-Care default" (stored as null).
 */
export function ColorField({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string | null;
  onChange: (value: string | null) => void;
}) {
  return (
    <div className={styles.field}>
      <label className={styles.swatchWrap}>
        <input type="color" value={toHex(value)} onChange={(event) => onChange(event.target.value)} />
        <span className={styles.swatch} style={{ background: value ?? FALLBACK_HEX }} />
      </label>
      <span className={styles.info}>
        <strong>{label}</strong>
        <code>{value ? value.toUpperCase() : 'Inherit Vee-Care default'}</code>
      </span>
      {value ? (
        <button type="button" className={styles.reset} onClick={() => onChange(null)} aria-label={`Reset ${label}`}>
          <RotateCcw size={14} />
        </button>
      ) : null}
    </div>
  );
}

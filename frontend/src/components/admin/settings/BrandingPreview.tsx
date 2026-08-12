import { HeartPulse } from 'lucide-react';
import type { CSSProperties } from 'react';
import { effectiveBranding } from '../../../lib/tenant/configuration';
import type { TenantConfigurationBranding } from '../../../lib/tenant/configuration';
import styles from './BrandingPreview.module.scss';

/**
 * Live preview of the effective hospital identity. Values shown here are
 * exactly what a visitor sees once nulls inherit the Vee-Care defaults. The
 * branding only styles this preview surface — it never touches the document.
 */
export function BrandingPreview({ branding, name }: { branding: TenantConfigurationBranding; name: string }) {
  const effective = effectiveBranding(branding);

  const previewStyle = {
    '--pv-accent': effective.primary_color ?? '#0f766e',
    '--pv-font': effective.font_family === 'system' ? undefined : effective.font_family ?? undefined,
  } as CSSProperties;

  return (
    <div className={styles.preview} style={previewStyle}>
      <div className={styles.brand}>
        {effective.logo ? <img src={effective.logo} alt="" className={styles.logo} /> : <HeartPulse size={22} />}
        <strong>{name || 'Your Hospital'}</strong>
      </div>
      <p className={styles.text}>
        This is how your hospital appears to patients — colours, font and logo applied live as you edit.
      </p>
      <div className={styles.button}>Book an appointment</div>
      <div className={styles.row}>
        <span className={styles.chip}>Doctors</span>
        <span className={styles.chip}>Services</span>
        <span className={styles.chip}>Contact</span>
      </div>
    </div>
  );
}

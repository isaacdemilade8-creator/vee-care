import { BrandingPreview } from '../../../components/admin/settings/BrandingPreview';
import { ColorField } from '../../../components/admin/settings/ColorField';
import { LogoField } from '../../../components/admin/settings/LogoField';
import { Card } from '../../../components/Card';
import { SelectField } from '../../../components/FormField';
import { useHospitalSettings } from '../../../layouts/HospitalSettingsLayout';
import { TENANT_FONTS } from '../../../lib/tenant/configuration';
import styles from './HospitalSettings.module.scss';

export function HospitalBrandingPage() {
  const { config, updateBranding } = useHospitalSettings();

  if (!config) {
    return null;
  }

  return (
    <div className={styles.page}>
      <Card>
        <header className={styles.pageHeader}>
          <p>Appearance</p>
          <h3>Branding</h3>
          <span>Controls how your hospital looks to patients and visitors.</span>
        </header>
        <div className={styles.cardGrid}>
          <LogoField label="Logo" value={config.branding.logo} onChange={(value) => updateBranding('logo', value)} />
          <LogoField label="Favicon" value={config.branding.favicon} onChange={(value) => updateBranding('favicon', value)} />
        </div>
      </Card>

      <Card>
        <header className={styles.pageHeader}>
          <p>Colours</p>
          <h3>Colour palette</h3>
          <span>Leave a colour untouched to inherit the Vee-Care default.</span>
        </header>
        <div className={styles.cardGrid}>
          <ColorField
            label="Primary colour"
            value={config.branding.primary_color}
            onChange={(value) => updateBranding('primary_color', value)}
          />
          <ColorField
            label="Secondary colour"
            value={config.branding.secondary_color}
            onChange={(value) => updateBranding('secondary_color', value)}
          />
          <ColorField
            label="Accent colour"
            value={config.branding.accent_color}
            onChange={(value) => updateBranding('accent_color', value)}
          />
        </div>
      </Card>

      <Card>
        <header className={styles.pageHeader}>
          <p>Typography</p>
          <h3>Font family</h3>
          <span>The font used across your hospital's pages.</span>
        </header>
        <SelectField
          label="Hospital font"
          value={config.branding.font_family ?? ''}
          onChange={(event) => updateBranding('font_family', event.target.value || null)}
        >
          <option value="">Inherit Vee-Care default</option>
          {TENANT_FONTS.map((font) => (
            <option key={font} value={font}>
              {font === 'system' ? 'System UI font' : font}
            </option>
          ))}
        </SelectField>
      </Card>

      <Card>
        <header className={styles.pageHeader}>
          <p>Live preview</p>
          <h3>Preview</h3>
          <span>Your changes apply here immediately as you edit.</span>
        </header>
        <BrandingPreview branding={config.branding} name={config.name} />
      </Card>
    </div>
  );
}

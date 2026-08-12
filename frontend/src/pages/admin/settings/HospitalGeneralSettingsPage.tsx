import { Card } from '../../../components/Card';
import { SelectField, TextField } from '../../../components/FormField';
import { useHospitalSettings } from '../../../layouts/HospitalSettingsLayout';
import {
  TENANT_DATE_FORMATS,
  TENANT_LOCALES,
  TENANT_TIME_FORMATS,
  TENANT_TIMEZONES,
} from '../../../lib/tenant/configuration';
import styles from './HospitalSettings.module.scss';

export function HospitalGeneralSettingsPage() {
  const { config, updateSetting } = useHospitalSettings();

  if (!config) {
    return null;
  }

  return (
    <div className={styles.page}>
      <Card>
        <header className={styles.pageHeader}>
          <p>Preferences</p>
          <h3>General settings</h3>
          <span>Regional defaults applied across your hospital's experience.</span>
        </header>
        <div className={styles.formGrid}>
          <SelectField
            label="Language (locale)"
            value={config.settings.locale}
            onChange={(event) => updateSetting('locale', event.target.value)}
          >
            {TENANT_LOCALES.map((locale) => (
              <option key={locale} value={locale}>
                {locale === 'en' ? 'English' : locale}
              </option>
            ))}
          </SelectField>

          <SelectField
            label="Timezone"
            value={config.settings.timezone}
            onChange={(event) => updateSetting('timezone', event.target.value)}
          >
            {TENANT_TIMEZONES.map((timezone) => (
              <option key={timezone} value={timezone}>
                {timezone.replace(/_/g, ' ')}
              </option>
            ))}
          </SelectField>

          <SelectField
            label="Date format"
            value={config.settings.date_format}
            onChange={(event) => updateSetting('date_format', event.target.value)}
          >
            {TENANT_DATE_FORMATS.map((format) => (
              <option key={format} value={format}>
                {format}
              </option>
            ))}
          </SelectField>

          <SelectField
            label="Time format"
            value={config.settings.time_format}
            onChange={(event) => updateSetting('time_format', event.target.value)}
          >
            {TENANT_TIME_FORMATS.map((format) => (
              <option key={format} value={format}>
                {format}
              </option>
            ))}
          </SelectField>

          <TextField
            label="Default appointment duration (minutes)"
            type="number"
            min={5}
            max={240}
            step={5}
            value={config.settings.default_appointment_duration}
            onChange={(event) => {
              const value = event.target.value;
              if (value !== '') {
                updateSetting('default_appointment_duration', Number(value));
              }
            }}
          />
        </div>
        <p className={styles.formHint}>Appointment duration must stay between 5 and 240 minutes.</p>
      </Card>
    </div>
  );
}

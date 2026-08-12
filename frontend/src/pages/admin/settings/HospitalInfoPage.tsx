import { Card } from '../../../components/Card';
import { TextField } from '../../../components/FormField';
import { useHospitalSettings } from '../../../layouts/HospitalSettingsLayout';
import styles from './HospitalSettings.module.scss';

export function HospitalInfoPage() {
  const { config, updateName } = useHospitalSettings();

  if (!config) {
    return null;
  }

  return (
    <div className={styles.page}>
      <Card>
        <header className={styles.pageHeader}>
          <p>Details</p>
          <h3>Hospital information</h3>
          <span>How your hospital is named across the platform.</span>
        </header>
        <TextField
          label="Hospital name"
          value={config.name}
          minLength={2}
          maxLength={255}
          onChange={(event) => updateName(event.target.value)}
        />
        <p className={styles.formHint}>
          Changing the name updates your brand everywhere: header, page title and the public hospital page.
        </p>
      </Card>
    </div>
  );
}

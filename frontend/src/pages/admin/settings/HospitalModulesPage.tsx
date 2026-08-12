import { ToggleSwitch } from '../../../components/admin/settings/ToggleSwitch';
import { Card } from '../../../components/Card';
import { useHospitalSettings } from '../../../layouts/HospitalSettingsLayout';
import styles from './HospitalSettings.module.scss';

export function HospitalModulesPage() {
  const { config, toggleModule } = useHospitalSettings();

  if (!config) {
    return null;
  }

  const entries = Object.entries(config.modules);

  return (
    <div className={styles.page}>
      <Card>
        <header className={styles.pageHeader}>
          <p>Capabilities</p>
          <h3>Modules</h3>
          <span>Enable or disable the modules available at your hospital. Required modules stay on.</span>
        </header>
        <div className={styles.list}>
          {entries.map(([key, entry]) => (
            <div className={styles.listRow} key={key}>
              <div className={styles.listInfo}>
                <strong>{entry.name}</strong>
                <span>{entry.description}</span>
              </div>
              <div className={styles.listMeta}>
                {entry.required ? <span className={styles.required}>Required</span> : null}
                <ToggleSwitch
                  label={`Enable ${entry.name}`}
                  checked={entry.enabled}
                  onChange={(next) => toggleModule(key, next)}
                  disabled={entry.required}
                />
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}

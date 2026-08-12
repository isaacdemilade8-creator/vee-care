import { ToggleSwitch } from '../../../components/admin/settings/ToggleSwitch';
import { Card } from '../../../components/Card';
import { useHospitalSettings } from '../../../layouts/HospitalSettingsLayout';
import styles from './HospitalSettings.module.scss';

export function HospitalRolesPage() {
  const { config, toggleRole } = useHospitalSettings();

  if (!config) {
    return null;
  }

  const entries = Object.entries(config.roles);

  return (
    <div className={styles.page}>
      <Card>
        <header className={styles.pageHeader}>
          <p>Access</p>
          <h3>Roles</h3>
          <span>Control which roles can sign in at your hospital. Core roles stay on.</span>
        </header>
        <div className={styles.list}>
          {entries.map(([key, entry]) => (
            <div className={styles.listRow} key={key}>
              <div className={styles.listInfo}>
                <strong>{entry.label}</strong>
                <span>
                  {entry.required
                    ? 'This role is part of the core hospital team and cannot be disabled.'
                    : 'Switch this role off to prevent new sign-ins from it.'}
                </span>
              </div>
              <div className={styles.listMeta}>
                {entry.required ? <span className={styles.required}>Required</span> : null}
                <ToggleSwitch
                  label={`Enable ${entry.label}`}
                  checked={entry.enabled}
                  onChange={(next) => toggleRole(key, next)}
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

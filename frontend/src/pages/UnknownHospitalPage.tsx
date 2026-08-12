import { Link } from 'react-router-dom';
import { Building2 } from 'lucide-react';
import { useTenant } from '../context/TenantContext';
import styles from './UnknownHospitalPage.module.scss';

/**
 * Shown when a tenant-looking host (e.g. nosuch.vee-care.test) does not resolve
 * to any tenant on the platform.
 */
export function UnknownHospitalPage() {
  const { host } = useTenant();

  return (
    <main className={styles.page}>
      <Building2 size={40} />
      <h1>Hospital not found</h1>
      <p>
        No hospital is registered for <strong>{host.hostname}</strong>.
      </p>
      <Link to="/">Back to vee-care</Link>
    </main>
  );
}

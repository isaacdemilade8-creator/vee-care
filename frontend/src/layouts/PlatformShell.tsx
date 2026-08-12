import { Building2, ClipboardList, LayoutDashboard, LogOut, ScrollText, Users } from 'lucide-react';
import { Navigate, NavLink, Outlet } from 'react-router-dom';
import { canAccessPlatform, platformRouteRoles } from '../auth/roleAccess';
import { useAuth } from '../context/AuthContext';
import styles from './PlatformShell.module.scss';

const navItems = [
  { to: '/platform', label: 'Dashboard', icon: LayoutDashboard, end: true, roles: platformRouteRoles.overview },
  { to: '/platform/hospitals', label: 'Hospitals', icon: Building2, end: false, roles: platformRouteRoles.tenants },
  { to: '/platform/applications', label: 'Applications', icon: ClipboardList, end: false, roles: platformRouteRoles.hospitalApplications },
  { to: '/platform/users', label: 'Users', icon: Users, end: false, roles: platformRouteRoles.users },
  { to: '/platform/audit-logs', label: 'Audit Logs', icon: ScrollText, end: false, roles: platformRouteRoles.auditLogs },
];

/**
 * Authenticated shell for the platform (control plane).
 *
 * Rendered on the apex platform host only and styled as Vee-Care platform
 * administration, deliberately distinct from the hospital dashboard. Uses the
 * platform's own branding, never a tenant's. Unauthenticated visitors are sent
 * to the platform login.
 */
export function PlatformShell() {
  const { isPlatformAuthenticated, platformUser, platformLogout } = useAuth();

  if (!isPlatformAuthenticated) {
    return <Navigate to="/platform/login" replace />;
  }

  const role = platformUser?.role;
  const links = navItems.filter((item) => canAccessPlatform(role, item.roles));

  return (
    <div className={styles.shell}>
      <header className={styles.topbar}>
        <div className={styles.brand}>
          <Building2 size={22} />
          <span>
            vee-care <strong>Platform</strong>
          </span>
        </div>
        <div className={styles.actions}>
          <div className={styles.user}>
            <strong>{platformUser?.name}</strong>
            <span>{platformUser?.role.replace(/_/g, ' ')}</span>
          </div>
          <button type="button" className={styles.logout} onClick={() => void platformLogout()}>
            <LogOut size={18} />
            <span>Sign out</span>
          </button>
        </div>
      </header>

      <div className={styles.body}>
        <aside className={styles.sidebar}>
          <nav className={styles.nav} aria-label="Platform sections">
            {links.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => `${styles.navLink} ${isActive ? styles.navLinkActive : ''}`}
              >
                <item.icon size={17} />
                <span>{item.label}</span>
              </NavLink>
            ))}
          </nav>
        </aside>

        <main className={styles.main}>
          <Outlet />
        </main>
      </div>
    </div>
  );
}

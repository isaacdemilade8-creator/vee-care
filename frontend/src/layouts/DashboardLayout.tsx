import { Bot, Boxes, Building2, CalendarDays, ChevronDown, ClipboardList, FileText, FlaskConical, HeartHandshake, HeartPulse, LayoutDashboard, Menu, MessageCircle, Moon, Newspaper, PackageCheck, PackagePlus, Pill, Settings, ShieldCheck, Stethoscope, UserRound, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { motion, type Variants } from 'framer-motion';
import { NotificationBell } from '../components/NotificationBell';
import { useAppSettings } from '../context/AppSettingsContext';
import { useAuth } from '../context/AuthContext';
import { useRealtimeNotifications } from '../hooks/useApi';
import { canAccess, routeRoles } from '../auth/roleAccess';
import styles from './DashboardLayout.module.scss';

const baseLinks = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, roles: undefined },
  { to: '/care-services', label: 'Care', icon: HeartHandshake, roles: routeRoles.care },
  { to: '/appointments', label: 'Appointments', icon: CalendarDays, roles: routeRoles.appointments },
  { to: '/records', label: 'Records', icon: FileText, roles: routeRoles.records },
  { to: '/chat', label: 'Chat', icon: MessageCircle, roles: routeRoles.chat },
  { to: '/profiles', label: 'Profiles', icon: UserRound, roles: routeRoles.profiles },
  { to: '/nurse/station', label: 'Nurse Station', icon: ClipboardList, roles: routeRoles.nurseStation },
  { to: '/laboratory', label: 'Laboratory', icon: FlaskConical, roles: routeRoles.laboratory },
  { to: '/pharmacy/requests', label: 'Pharmacy requests', icon: Pill, roles: routeRoles.pharmacyRequests },
  { to: '/enterprise', label: 'SaaS', icon: Building2, roles: routeRoles.enterprise },
];

const enterpriseModuleLinks = [
  { to: '/enterprise', label: 'Overview', icon: LayoutDashboard, roles: routeRoles.enterpriseOverview },
  { to: '/enterprise/modules?module=patients', label: 'Patients', icon: Users, roles: ['admin', 'doctor', 'nurse', 'super_admin'] },
  { to: '/enterprise/modules?module=ehr', label: 'EHR', icon: FileText, roles: ['admin', 'doctor', 'nurse', 'lab_technician', 'super_admin'] },
  { to: '/nurse/station', label: 'Nurse Station', icon: ClipboardList, roles: routeRoles.nurseStation },
  { to: '/enterprise/modules?module=staff', label: 'Staff', icon: UserRound, roles: ['admin', 'super_admin'] },
  { to: '/enterprise/modules?module=pharmacy', label: 'Pharmacy', icon: PackageCheck, roles: routeRoles.pharmacy },
  { to: '/pharmacy/inventory', label: 'Drug Inventory', icon: Boxes, roles: routeRoles.pharmacy },
  { to: '/pharmacy/medicines/new', label: 'Add Medicine', icon: PackagePlus, roles: routeRoles.pharmacy },
  { to: '/laboratory', label: 'Laboratory', icon: FlaskConical, roles: routeRoles.laboratory },
  { to: '/enterprise/modules?module=ai', label: 'AI Assistant', icon: Bot, roles: ['admin', 'doctor'] },
] as const;

export function DashboardLayout() {
  const { user } = useAuth();
  const { toggleDarkMode } = useAppSettings();
  const location = useLocation();
  const [moreOpen, setMoreOpen] = useState(false);
  const isEnterpriseRoute = location.pathname.startsWith('/enterprise') || location.pathname.startsWith('/pharmacy');
  const [enterpriseOpen, setEnterpriseOpen] = useState(isEnterpriseRoute);
  useRealtimeNotifications(user);
  const links = [
    ...baseLinks.filter((link) => !link.roles || canAccess(user?.role, link.roles)),
    ...(canAccess(user?.role, routeRoles.admin) ? [
      { to: '/admin', label: 'Admin', icon: ShieldCheck },
      { to: '/admin?tab=campaign', label: 'Campaign', icon: Newspaper },
    ] : []),
  ];
  const enterpriseLinks = enterpriseModuleLinks.filter((link) => canAccess(user?.role, link.roles));
  const enterpriseHome = canAccess(user?.role, routeRoles.enterpriseOverview) ? '/enterprise' : enterpriseLinks[0]?.to ?? '/dashboard';
  const primaryMobileLinks = links.slice(0, 4);
  const moreLinks = [...links.slice(4), { to: '/settings', label: 'Settings', icon: Settings }];

  useEffect(() => {
    if (isEnterpriseRoute) {
      setEnterpriseOpen(true);
    }
  }, [isEnterpriseRoute]);

  const linkClass = (to: string, isActive: boolean) => {
    if (to === '/admin') {
      return location.pathname === '/admin' && location.search !== '?tab=campaign' ? styles.active : undefined;
    }

    if (to.includes('?')) {
      const [pathname, search] = to.split('?');
      return location.pathname === pathname && location.search === `?${search}` ? styles.active : undefined;
    }

    return isActive ? styles.active : undefined;
  };
  const renderLink = ({ to, label, icon: Icon }: { to: string; label: string; icon: typeof LayoutDashboard }, onClick?: () => void, className = '') => (
    <NavLink key={to} to={to} onClick={onClick} className={({ isActive }) => `${linkClass(to, isActive) ?? ''} ${className}`.trim() || undefined}>
      <Icon size={18} />
      <span>{label}</span>
    </NavLink>
  );
  const renderEnterpriseGroup = (onLinkClick?: () => void) => (
    <div key="/enterprise" className={styles.navGroup}>
      <div className={styles.groupHeader}>
        {renderLink({ to: enterpriseHome, label: 'SaaS', icon: Building2 }, onLinkClick)}
        <button
          type="button"
          className={`${styles.collapseButton} ${enterpriseOpen ? styles.expanded : ''}`}
          onClick={() => setEnterpriseOpen((value) => !value)}
          aria-label={`${enterpriseOpen ? 'Collapse' : 'Expand'} SaaS modules`}
          aria-expanded={enterpriseOpen}
        >
          <ChevronDown size={16} />
        </button>
      </div>
      {enterpriseOpen && enterpriseLinks.length ? (
        <div className={styles.subNav}>
          {enterpriseLinks.map((subLink) => renderLink(subLink, onLinkClick, styles.subLink))}
        </div>
      ) : null}
    </div>
  );

  const navVariants: Variants = {
    hidden: {},
    visible: {
      transition: { staggerChildren: 0.04 },
    },
  };

  const navItemVariants: Variants = {
    hidden: { opacity: 0, x: -8 },
    visible: {
      opacity: 1,
      x: 0,
      transition: { duration: 0.2, ease: 'easeOut' as const },
    },
  };

  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <NavLink to="/" className={styles.brand}>
          <HeartPulse size={24} />
          <span>Vee-care</span>
        </NavLink>
        <motion.nav variants={navVariants} initial="hidden" animate="visible">
          {links.map((link) => (
            <motion.div key={link.to} variants={navItemVariants}>
              {link.to === '/enterprise' ? renderEnterpriseGroup() : renderLink(link)}
            </motion.div>
          ))}
        </motion.nav>
        <nav className={styles.mobileNav}>
          {primaryMobileLinks.map((link) => renderLink(link, () => setMoreOpen(false)))}
          <button className={moreOpen ? styles.active : ''} type="button" onClick={() => setMoreOpen((value) => !value)}>
            <Menu size={18} />
            <span>More</span>
          </button>
        </nav>
        {moreOpen ? (
          <div className={styles.moreMenu}>
            {moreLinks.map((link) => (link.to === '/enterprise' ? renderEnterpriseGroup(() => setMoreOpen(false)) : renderLink(link, () => setMoreOpen(false))))}
          </div>
        ) : null}
        <NavLink to="/settings" className={({ isActive }) => `${styles.settingsLink} ${isActive ? styles.active : ''}`}>
          <Settings size={18} />
          <span>Settings</span>
        </NavLink>
      </aside>
      <main className={styles.main}>
        <header className={styles.topbar}>
          <Link to="/profiles/me" className={styles.profileLink}>
            <p>{user?.role}</p>
            <h1>{user?.name}</h1>
          </Link>
          <div className={styles.topActions}>
            <NotificationBell />
            <button className={styles.iconButton} onClick={toggleDarkMode} aria-label="Toggle dark mode">
              <Moon size={20} />
            </button>
            <Stethoscope size={28} />
          </div>
        </header>
        <Outlet />
      </main>
    </div>
  );
}

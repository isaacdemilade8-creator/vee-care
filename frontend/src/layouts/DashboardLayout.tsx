import { Activity, Bot, Boxes, Building2, CalendarDays, ChevronDown, ClipboardList, CreditCard, FileText, FlaskConical, HeartHandshake, LayoutDashboard, Menu, MessageCircle, Moon, Newspaper, PackageCheck, PackagePlus, Pill, Settings, ShieldCheck, Stethoscope, UserRound, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { motion, type Variants } from 'framer-motion';
import { NotificationBell } from '../components/NotificationBell';
import { useAppSettings } from '../context/AppSettingsContext';
import { useAuth } from '../context/AuthContext';
import { useRealtimeNotifications } from '../hooks/useApi';
import { TenantBrandLogo } from '../components/tenant/TenantBrandLogo';
import { useTenantName } from '../context/TenantContext';
import { canAccess, routeRoles } from '../auth/roleAccess';
import { useEnabledModules, type TenantModuleKey } from '../lib/tenant/modules';
import type { Role } from '../types';
import styles from './DashboardLayout.module.scss';

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  roles?: readonly Role[];
  module?: TenantModuleKey;
}

const baseLinks: NavItem[] = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, roles: undefined },
  { to: '/care-services', label: 'Care', icon: HeartHandshake, roles: routeRoles.care },
  { to: '/appointments', label: 'Appointments', icon: CalendarDays, roles: routeRoles.appointments, module: 'appointments' },
  { to: '/records', label: 'Records', icon: FileText, roles: routeRoles.records, module: 'ehr' },
  { to: '/chat', label: 'Chat', icon: MessageCircle, roles: routeRoles.chat, module: 'messaging' },
  { to: '/profiles', label: 'Profiles', icon: UserRound, roles: routeRoles.profiles },
  { to: '/nurse/station', label: 'Nurse Station', icon: ClipboardList, roles: routeRoles.nurseStation, module: 'nurse_station' },
  { to: '/laboratory', label: 'Laboratory', icon: FlaskConical, roles: routeRoles.laboratory, module: 'laboratory' },
  { to: '/pharmacy/requests', label: 'Pharmacy requests', icon: Pill, roles: routeRoles.pharmacyRequests, module: 'pharmacy' },
  { to: '/enterprise', label: 'SaaS', icon: Building2, roles: routeRoles.enterprise, module: 'enterprise' },
];

const enterpriseModuleLinks: (NavItem & { icon: LucideIcon })[] = [
  { to: '/enterprise', label: 'Overview', icon: LayoutDashboard, roles: routeRoles.enterpriseOverview, module: 'enterprise' },
  { to: '/enterprise/modules?module=patients', label: 'Patients', icon: Users, roles: routeRoles.enterprisePatients, module: 'enterprise' },
  { to: '/enterprise/modules?module=ehr', label: 'EHR', icon: FileText, roles: routeRoles.enterpriseEhr, module: 'enterprise' },
  { to: '/nurse/station', label: 'Nurse Station', icon: ClipboardList, roles: routeRoles.nurseStation, module: 'nurse_station' },
  { to: '/enterprise/modules?module=staff', label: 'Staff', icon: UserRound, roles: routeRoles.enterpriseStaff, module: 'enterprise' },
  { to: '/enterprise/modules?module=pharmacy', label: 'Pharmacy', icon: PackageCheck, roles: routeRoles.pharmacy, module: 'pharmacy' },
  { to: '/pharmacy/inventory', label: 'Drug Inventory', icon: Boxes, roles: routeRoles.pharmacy, module: 'pharmacy' },
  { to: '/pharmacy/medicines/new', label: 'Add Medicine', icon: PackagePlus, roles: routeRoles.pharmacy, module: 'pharmacy' },
  { to: '/laboratory', label: 'Laboratory', icon: FlaskConical, roles: routeRoles.laboratory, module: 'laboratory' },
  { to: '/enterprise/modules?module=ai', label: 'AI Assistant', icon: Bot, roles: routeRoles.enterpriseAi, module: 'enterprise' },
];

export function DashboardLayout() {
  const { user } = useAuth();
  const { toggleDarkMode } = useAppSettings();
  const tenantName = useTenantName();
  const enabledModules = useEnabledModules();
  const location = useLocation();
  const [moreOpen, setMoreOpen] = useState(false);
  const isEnterpriseRoute = location.pathname.startsWith('/enterprise') || location.pathname.startsWith('/pharmacy');
  const [enterpriseOpen, setEnterpriseOpen] = useState(isEnterpriseRoute);
  const [prevEnterpriseRoute, setPrevEnterpriseRoute] = useState(isEnterpriseRoute);
  useRealtimeNotifications(user);
  const moduleAllowed = (link: NavItem) => !link.module || enabledModules.has(link.module);
  const adminLinks: NavItem[] = canAccess(user?.role, routeRoles.admin)
    ? [
        { to: '/admin', label: 'Admin', icon: ShieldCheck },
        { to: '/admin/users', label: 'Users', icon: Users },
        { to: '/admin?tab=campaign', label: 'Campaign', icon: Newspaper, module: 'blog' },
        { to: '/admin/settings', label: 'Hospital Settings', icon: Settings },
      ]
    : [];
  const links = [
    ...baseLinks.filter((link) => (!link.roles || canAccess(user?.role, link.roles)) && moduleAllowed(link)),
    ...adminLinks.filter((link) => moduleAllowed(link)),
  ];
  const enterpriseLinks = enterpriseModuleLinks.filter((link) => (!link.roles || canAccess(user?.role, link.roles)) && moduleAllowed(link));
  const enterpriseHome = canAccess(user?.role, routeRoles.enterpriseOverview) ? '/enterprise' : enterpriseLinks[0]?.to ?? '/dashboard';
  const primaryMobileLinks = links.slice(0, 4);
  const moreLinks = [
    ...links.slice(4),
    ...([{ to: '/my-card', label: 'My Card', icon: CreditCard, module: 'patient_portal' as const }, { to: '/activity-log', label: 'Activity Log', icon: Activity }, { to: '/settings', label: 'Settings', icon: Settings }].filter(
      (link) => moduleAllowed(link),
    )),
  ];

  if (isEnterpriseRoute !== prevEnterpriseRoute) {
    setPrevEnterpriseRoute(isEnterpriseRoute);
    if (isEnterpriseRoute) {
      setEnterpriseOpen(true);
    }
  }

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
  const renderLink = ({ to, label, icon: Icon }: NavItem, onClick?: () => void, className = '') => (
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
          <TenantBrandLogo size={24} alt={tenantName} />
          <span>{tenantName}</span>
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

import { BarChart3, Edit3, Newspaper } from 'lucide-react';
import { NavLink } from 'react-router-dom';
import styles from './BlogTabs.module.scss';

const tabs = [
  { to: '/blog', label: 'Feed', icon: Newspaper },
  { to: '/blog/create', label: 'Create blog', icon: Edit3 },
  { to: '/blog/analytics', label: 'Analytics', icon: BarChart3 },
];

export function BlogTabs() {
  return (
    <nav className={styles.tabs} aria-label="Blog workspace">
      {tabs.map(({ to, label, icon: Icon }) => (
        <NavLink key={to} to={to} end={to === '/blog'} className={({ isActive }) => (isActive ? styles.active : undefined)}>
          <Icon size={17} />
          <span>{label}</span>
        </NavLink>
      ))}
    </nav>
  );
}

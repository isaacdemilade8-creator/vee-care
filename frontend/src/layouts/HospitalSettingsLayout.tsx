import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import { Boxes, Building2, Check, Loader2, Palette, RotateCcw, ShieldCheck, SlidersHorizontal } from 'lucide-react';
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { DataStatePanel } from '../components/DataStatePanel';
import { useTenant } from '../context/TenantContext';
import { configurationPatch, getTenantConfiguration, patchHasChanges, updateTenantConfiguration } from '../lib/tenant/configuration';
import type { TenantConfiguration, TenantConfigurationBranding, TenantGeneralSettings } from '../lib/tenant/configuration';
import styles from './HospitalSettingsLayout.module.scss';

interface HospitalSettingsValue {
  config: TenantConfiguration | null;
  isLoading: boolean;
  isSaving: boolean;
  dirty: boolean;
  updateName: (name: string) => void;
  updateBranding: (key: keyof TenantConfigurationBranding, value: string | null) => void;
  toggleModule: (key: string, enabled: boolean) => void;
  toggleRole: (key: string, enabled: boolean) => void;
  updateSetting: <K extends keyof TenantGeneralSettings>(key: K, value: TenantGeneralSettings[K]) => void;
  save: () => void;
  reset: () => void;
}

const HospitalSettingsContext = createContext<HospitalSettingsValue | null>(null);

const settingsTabs = [
  { to: '/admin/settings', label: 'Branding', icon: Palette, end: true },
  { to: '/admin/settings/modules', label: 'Modules', icon: Boxes, end: false },
  { to: '/admin/settings/roles', label: 'Roles', icon: ShieldCheck, end: false },
  { to: '/admin/settings/general', label: 'General', icon: SlidersHorizontal, end: false },
  { to: '/admin/settings/hospital', label: 'Hospital info', icon: Building2, end: false },
] as const;

export function HospitalSettingsLayout() {
  const queryClient = useQueryClient();
  const { refresh } = useTenant();
  const [draft, setDraft] = useState<TenantConfiguration | null>(null);
  const [baseline, setBaseline] = useState<TenantConfiguration | null>(null);
  const [seededFor, setSeededFor] = useState<TenantConfiguration | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['tenant-configuration'],
    queryFn: getTenantConfiguration,
    staleTime: 30_000,
  });

  // Adjust state during render (the React-endorsed pattern for syncing state
  // to changing props/data) so the draft is seeded from the server response
  // without an effect-driven cascade.
  if (data && seededFor !== data) {
    setSeededFor(data);
    setBaseline(data);
    setDraft(data);
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!draft || !baseline) {
        return null;
      }

      const patch = configurationPatch(baseline, draft);

      return patchHasChanges(patch) ? updateTenantConfiguration(patch) : null;
    },
    onSuccess: async (result) => {
      if (!result) {
        return;
      }

      setBaseline(result);
      setSeededFor(result);
      setDraft(result);
      queryClient.setQueryData(['tenant-configuration'], result);
      await refresh();
      toast.success('Hospital settings saved');
    },
  });

  const dirty = useMemo(() => {
    if (!draft || !baseline) {
      return false;
    }

    return patchHasChanges(configurationPatch(baseline, draft));
  }, [baseline, draft]);

  const updateName = useCallback((name: string) => {
    setDraft((current) => (current ? { ...current, name } : current));
  }, []);

  const updateBranding = useCallback((key: keyof TenantConfigurationBranding, value: string | null) => {
    setDraft((current) => (current ? { ...current, branding: { ...current.branding, [key]: value } } : current));
  }, []);

  const toggleModule = useCallback((key: string, enabled: boolean) => {
    setDraft((current) => {
      const entry = current?.modules[key];
      if (!current || !entry) {
        return current;
      }

      return { ...current, modules: { ...current.modules, [key]: { ...entry, enabled } } };
    });
  }, []);

  const toggleRole = useCallback((key: string, enabled: boolean) => {
    setDraft((current) => {
      const entry = current?.roles[key];
      if (!current || !entry) {
        return current;
      }

      return { ...current, roles: { ...current.roles, [key]: { ...entry, enabled } } };
    });
  }, []);

  const updateSetting = useCallback(<K extends keyof TenantGeneralSettings>(key: K, value: TenantGeneralSettings[K]) => {
    setDraft((current) => (current ? { ...current, settings: { ...current.settings, [key]: value } } : current));
  }, []);

  const save = useCallback(() => saveMutation.mutate(), [saveMutation]);
  const reset = useCallback(() => {
    if (baseline) {
      setDraft(structuredClone(baseline));
    }
  }, [baseline]);

  // Warn before leaving the tab with unsaved changes (useBlocker needs a data
  // router, which this BrowserRouter-based app does not use).
  useEffect(() => {
    if (!dirty) {
      return;
    }

    const handler = (event: BeforeUnloadEvent) => event.preventDefault();

    window.addEventListener('beforeunload', handler);

    return () => window.removeEventListener('beforeunload', handler);
  }, [dirty]);

  const value = useMemo<HospitalSettingsValue>(
    () => ({
      config: draft,
      isLoading,
      isSaving: saveMutation.isPending,
      dirty,
      updateName,
      updateBranding,
      toggleModule,
      toggleRole,
      updateSetting,
      save,
      reset,
    }),
    [dirty, draft, isLoading, reset, save, saveMutation.isPending, toggleModule, toggleRole, updateBranding, updateName, updateSetting],
  );

  return (
    <HospitalSettingsContext.Provider value={value}>
      <div className={styles.page}>
        <header className={styles.header}>
          <p>Hospital administration</p>
          <h2>Hospital settings</h2>
        </header>

        <div className={styles.layout}>
          <nav className={styles.tabs} aria-label="Hospital settings sections">
            {settingsTabs.map((tab) => (
              <NavLink
                key={tab.to}
                to={tab.to}
                end={tab.end}
                className={({ isActive }) => `${styles.tab} ${isActive ? styles.active : ''}`}
              >
                <tab.icon size={18} />
                <span>{tab.label}</span>
              </NavLink>
            ))}
          </nav>

          <main className={styles.content}>
            {isLoading ? (
              <Card>
                <SkeletonRows rows={5} />
              </Card>
            ) : draft ? (
              <Outlet />
            ) : (
              <DataStatePanel
                title="Unable to load hospital settings"
                description="The configuration could not be fetched. Check your connection and try again."
              />
            )}
          </main>
        </div>

        {dirty ? (
          <motion.div
            className={styles.saveBar}
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.24, ease: 'easeOut' }}
          >
            <div className={styles.saveInfo}>
              <strong>Unsaved changes</strong>
              <span>Your hospital settings have not been saved yet.</span>
            </div>
            <div className={styles.saveActions}>
              <Button variant="ghost" onClick={reset} disabled={saveMutation.isPending}>
                <RotateCcw size={16} />
                Discard
              </Button>
              <Button variant="secondary" onClick={save} disabled={saveMutation.isPending}>
                {saveMutation.isPending ? <Loader2 size={16} className={styles.spin} /> : <Check size={16} />}
                Save changes
              </Button>
            </div>
          </motion.div>
        ) : null}
      </div>
    </HospitalSettingsContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useHospitalSettings(): HospitalSettingsValue {
  const context = useContext(HospitalSettingsContext);

  if (!context) {
    throw new Error('useHospitalSettings must be used inside HospitalSettingsLayout');
  }

  return context;
}

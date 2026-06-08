import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

interface AppSettings {
  darkMode: boolean;
  locale: string;
  currency: string;
  toggleDarkMode: () => void;
  setLocale: (locale: string) => void;
  setCurrency: (currency: string) => void;
}

const AppSettingsContext = createContext<AppSettings | null>(null);

export function AppSettingsProvider({ children }: { children: ReactNode }) {
  const [darkMode, setDarkMode] = useState(() => localStorage.getItem('caregrid_dark') === '1');
  const [locale, setLocaleState] = useState(() => localStorage.getItem('caregrid_locale') ?? 'en');
  const [currency, setCurrencyState] = useState(() => localStorage.getItem('caregrid_currency') ?? 'USD');

  useEffect(() => {
    document.documentElement.dataset.theme = darkMode ? 'dark' : 'light';
    localStorage.setItem('caregrid_dark', darkMode ? '1' : '0');
  }, [darkMode]);

  const value = useMemo(() => ({
    darkMode,
    locale,
    currency,
    toggleDarkMode: () => setDarkMode((value) => !value),
    setLocale: (value: string) => {
      localStorage.setItem('caregrid_locale', value);
      setLocaleState(value);
    },
    setCurrency: (value: string) => {
      localStorage.setItem('caregrid_currency', value);
      setCurrencyState(value);
    },
  }), [currency, darkMode, locale]);

  return <AppSettingsContext.Provider value={value}>{children}</AppSettingsContext.Provider>;
}

export function useAppSettings() {
  const context = useContext(AppSettingsContext);
  if (!context) {
    throw new Error('useAppSettings must be used inside AppSettingsProvider');
  }
  return context;
}

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { PLATFORM_TOKEN_KEY, PLATFORM_USER_KEY, platformEndpoints } from '../lib/platform/api';
import { TOKEN_KEY } from '../services/api';
import { endpoints } from '../services/endpoints';
import type { User } from '../types';

interface AuthContextValue {
  /** Tenant (hospital) session — isolated from the platform session. */
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  setSession: (user: UserResponse, token: string) => void;
  updateUser: (user: UserResponse) => void;
  logout: () => Promise<void>;
  /** Platform (control plane) session. */
  platformUser: User | null;
  platformToken: string | null;
  isPlatformAuthenticated: boolean;
  setPlatformSession: (user: UserResponse, token: string) => void;
  updatePlatformUser: (user: UserResponse) => void;
  platformLogout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

type UserResponse = User | { data: User };

function unwrapUser(response: UserResponse): User {
  return 'data' in response ? response.data : response;
}

function readStoredUser(key: string): User | null {
  const stored = localStorage.getItem(key);

  if (!stored) {
    return null;
  }

  try {
    return unwrapUser(JSON.parse(stored) as UserResponse);
  } catch {
    localStorage.removeItem(key);
    return null;
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const [user, setUser] = useState<User | null>(() => readStoredUser('healthtech_user'));
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_KEY));
  const [platformUser, setPlatformUser] = useState<User | null>(() => readStoredUser(PLATFORM_USER_KEY));
  const [platformToken, setPlatformToken] = useState<string | null>(() => localStorage.getItem(PLATFORM_TOKEN_KEY));

  const setSession = useCallback((nextUser: UserResponse, nextToken: string) => {
    const user = unwrapUser(nextUser);
    localStorage.setItem(TOKEN_KEY, nextToken);
    localStorage.setItem('healthtech_user', JSON.stringify(user));
    setUser(user);
    setToken(nextToken);
  }, []);

  const updateUser = useCallback((nextUser: UserResponse) => {
    const user = unwrapUser(nextUser);
    localStorage.setItem('healthtech_user', JSON.stringify(user));
    setUser(user);
  }, []);

  const logout = useCallback(async () => {
    try {
      await endpoints.logout();
    } finally {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem('healthtech_user');
      setUser(null);
      setToken(null);
      navigate('/login');
    }
  }, [navigate]);

  const setPlatformSession = useCallback((nextUser: UserResponse, nextToken: string) => {
    const user = unwrapUser(nextUser);
    localStorage.setItem(PLATFORM_TOKEN_KEY, nextToken);
    localStorage.setItem(PLATFORM_USER_KEY, JSON.stringify(user));
    setPlatformUser(user);
    setPlatformToken(nextToken);
  }, []);

  const updatePlatformUser = useCallback((nextUser: UserResponse) => {
    const user = unwrapUser(nextUser);
    localStorage.setItem(PLATFORM_USER_KEY, JSON.stringify(user));
    setPlatformUser(user);
  }, []);

  const platformLogout = useCallback(async () => {
    try {
      await platformEndpoints.logout();
    } finally {
      localStorage.removeItem(PLATFORM_TOKEN_KEY);
      localStorage.removeItem(PLATFORM_USER_KEY);
      setPlatformUser(null);
      setPlatformToken(null);
      navigate('/platform/login');
    }
  }, [navigate]);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    endpoints.me()
      .then((response) => {
        if (!cancelled) {
          updateUser(response.data);
        }
      })
      .catch(() => {
        if (!cancelled) {
          localStorage.removeItem(TOKEN_KEY);
          localStorage.removeItem('healthtech_user');
          setUser(null);
          setToken(null);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [token, updateUser]);

  useEffect(() => {
    if (!platformToken) {
      return;
    }

    let cancelled = false;

    platformEndpoints.me()
      .then((response) => {
        if (!cancelled) {
          updatePlatformUser(response.data);
        }
      })
      .catch(() => {
        if (!cancelled) {
          localStorage.removeItem(PLATFORM_TOKEN_KEY);
          localStorage.removeItem(PLATFORM_USER_KEY);
          setPlatformUser(null);
          setPlatformToken(null);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [platformToken, updatePlatformUser]);

  const value = useMemo(
    () => ({
      user,
      token,
      isAuthenticated: Boolean(token && user),
      setSession,
      updateUser,
      logout,
      platformUser,
      platformToken,
      isPlatformAuthenticated: Boolean(platformToken && platformUser),
      setPlatformSession,
      updatePlatformUser,
      platformLogout,
    }),
    [
      logout,
      platformLogout,
      platformToken,
      platformUser,
      setPlatformSession,
      setSession,
      token,
      updatePlatformUser,
      updateUser,
      user,
    ],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used inside AuthProvider');
  }
  return context;
}

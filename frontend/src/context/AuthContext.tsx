import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { TOKEN_KEY } from '../services/api';
import { endpoints } from '../services/endpoints';
import type { User } from '../types';

interface AuthContextValue {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  setSession: (user: User, token: string) => void;
  updateUser: (user: User) => void;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

type UserResponse = User | { data: User };

function unwrapUser(response: UserResponse): User {
  return 'data' in response ? response.data : response;
}

function normalizeUser(user: UserResponse): User {
  const unwrappedUser = unwrapUser(user);

  if ((unwrappedUser.role as string) === 'hospital_admin') {
    return { ...unwrappedUser, role: 'admin' };
  }

  return unwrappedUser;
}

function readStoredUser(): User | null {
  const stored = localStorage.getItem('healthtech_user');

  if (!stored) {
    return null;
  }

  try {
    return normalizeUser(JSON.parse(stored) as UserResponse);
  } catch {
    localStorage.removeItem('healthtech_user');
    return null;
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const [user, setUser] = useState<User | null>(readStoredUser);
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_KEY));

  const setSession = useCallback((nextUser: UserResponse, nextToken: string) => {
    const normalizedUser = normalizeUser(nextUser);
    localStorage.setItem(TOKEN_KEY, nextToken);
    localStorage.setItem('healthtech_user', JSON.stringify(normalizedUser));
    setUser(normalizedUser);
    setToken(nextToken);
  }, []);

  const updateUser = useCallback((nextUser: UserResponse) => {
    const normalizedUser = normalizeUser(nextUser);
    localStorage.setItem('healthtech_user', JSON.stringify(normalizedUser));
    setUser(normalizedUser);
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

  const value = useMemo(
    () => ({ user, token, isAuthenticated: Boolean(token && user), setSession, updateUser, logout }),
    [logout, setSession, token, updateUser, user],
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

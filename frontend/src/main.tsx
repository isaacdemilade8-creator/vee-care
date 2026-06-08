import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import App from './App';
import { AuthProvider } from './context/AuthContext';
import { AppSettingsProvider } from './context/AppSettingsContext';
import './styles/globals.scss';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
    },
  },
});

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AppSettingsProvider>
          <AuthProvider>
            <App />
            <Toaster position="top-right" />
          </AuthProvider>
        </AppSettingsProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
);

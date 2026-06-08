import axios from 'axios';
import toast from 'react-hot-toast';

export const TOKEN_KEY = 'healthtech_token';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000/api',
  headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const message = error.response?.data?.message ?? 'Something went wrong. Please try again.';
    if (error.response?.status !== 401) {
      toast.error(message);
    }
    return Promise.reject(error);
  },
);

import axios from 'axios';
import toast from 'react-hot-toast';
import { getApiErrorMessage } from '../utils/apiError';
import { resolveApiBaseUrl } from './apiBase';

export const TOKEN_KEY = 'healthtech_token';

export const api = axios.create({
  baseURL: resolveApiBaseUrl(),
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
    const message = getApiErrorMessage(error);
    if (error.response?.status !== 401) {
      toast.error(message);
    }
    return Promise.reject(error);
  },
);

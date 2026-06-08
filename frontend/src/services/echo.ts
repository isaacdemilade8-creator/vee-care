import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { TOKEN_KEY } from './api';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

window.Pusher = Pusher;

let echo: Echo<'pusher'> | null = null;

function apiOrigin() {
  const baseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000/api';
  return baseUrl.replace(/\/api\/?$/, '');
}

export function getEchoClient() {
  const key = import.meta.env.VITE_PUSHER_APP_KEY;

  if (!key) {
    return null;
  }

  if (echo) {
    return echo;
  }

  echo = new Echo({
    broadcaster: 'pusher',
    key,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
    forceTLS: true,
    authEndpoint: `${apiOrigin()}/broadcasting/auth`,
    auth: {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem(TOKEN_KEY) ?? ''}`,
      },
    },
  });

  return echo;
}

export function disconnectEcho() {
  echo?.disconnect();
  echo = null;
}

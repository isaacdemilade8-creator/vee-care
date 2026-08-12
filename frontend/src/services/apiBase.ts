/**
 * Resolves the API base URL shared by every HTTP client (tenant API, platform
 * API, and the realtime broadcast channel).
 *
 * `VITE_API_BASE_URL` is an explicit override for deployments that serve the
 * API from a dedicated host.
 *
 * Otherwise the base URL is derived from the page's own origin (development
 * uses the page hostname with the API port). This keeps the request Host
 * header equal to the tenant or platform subdomain the browser is on, so the
 * backend `ResolveTenant` middleware switches databases correctly. A static
 * base URL would otherwise pin every tenant subdomain to whichever host was
 * hardcoded and break tenant resolution in production.
 */
export function resolveApiBaseUrl(): string {
  const configured = import.meta.env.VITE_API_BASE_URL?.trim();

  if (configured) {
    return configured;
  }

  if (typeof window === 'undefined') {
    return '/api';
  }

  if (import.meta.env.DEV) {
    return `http://${window.location.hostname}:8000/api`;
  }

  return `${window.location.origin}/api`;
}

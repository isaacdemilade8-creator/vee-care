/**
 * Client-side mirror of the backend `TenantResolver` host classification.
 *
 * The backend stays authoritative: the `/tenant-context` endpoint decides the
 * real context (a slug may not exist, a custom domain may resolve elsewhere,
 * etc). But the browser needs a fast, synchronous answer to route the SPA
 * between the platform (control plane) shell and tenant surfaces before any
 * network request returns.
 */

export type HostKind = 'platform' | 'tenant' | 'unknown';

export interface HostContext {
  /** Raw hostname, lowercased, port and trailing dot stripped. */
  hostname: string;
  /** Apex platform domain, e.g. "vee-care.test". */
  platformDomain: string;
  /** Subdomains of the platform domain that belong to the control plane. */
  platformSubdomains: readonly string[];
  /** Loopback hosts are treated as the platform (matches backend dev behavior). */
  isLoopback: boolean;
  kind: HostKind;
  /** Tenant slug candidate when kind === 'tenant', otherwise null. */
  slug: string | null;
}

export interface ResolveHostOptions {
  platformDomain?: string;
  platformSubdomains?: readonly string[];
}

/** Must stay in sync with the backend `tenancy.platform_subdomains` config. */
export const PLATFORM_SUBDOMAINS = ['api', 'admin', 'www'] as const;

const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '0.0.0.0', '::1']);

export function getPlatformDomain(): string {
  // Optional chaining keeps this module importable under plain Node (smoke
  // tests), where `import.meta.env` is undefined.
  const meta = import.meta as unknown as { env?: Record<string, string | undefined> };
  return meta.env?.VITE_PLATFORM_DOMAIN ?? 'vee-care.test';
}

export function normalizeHostname(hostname: string): string {
  let value = hostname.trim().toLowerCase();

  if (value.startsWith('[')) {
    const closing = value.indexOf(']');
    value = closing !== -1 ? value.slice(1, closing) : value;
  } else if ((value.match(/:/g) ?? []).length === 1) {
    // "host:port" — strip the port. Bare IPv6 (::1) has multiple colons and
    // is left untouched.
    value = value.slice(0, value.indexOf(':'));
  }

  return value.replace(/\.+$/, '');
}

/**
 * Classify a hostname exactly like the backend resolver:
 *
 *  - apex and control-plane subdomains (api/admin/www) => platform
 *  - a single-level subdomain of the platform domain         => tenant candidate
 *  - anything else                                           => unknown
 */
export function resolveHostContext(
  hostname: string,
  options: ResolveHostOptions = {},
): HostContext {
  const platformDomain = normalizeHostname(options.platformDomain ?? getPlatformDomain());
  const platformSubdomains = options.platformSubdomains ?? PLATFORM_SUBDOMAINS;
  const normalized = normalizeHostname(hostname);
  const isLoopback = LOOPBACK_HOSTS.has(normalized);

  let kind: HostKind = 'unknown';
  let slug: string | null = null;

  if (isLoopback) {
    kind = 'platform';
  } else if (normalized === platformDomain) {
    kind = 'platform';
  } else if (platformDomain && normalized.endsWith(`.${platformDomain}`)) {
    const subdomain = normalized.slice(0, -(platformDomain.length + 1));

    if (subdomain !== '' && !subdomain.includes('.')) {
      if (platformSubdomains.includes(subdomain)) {
        kind = 'platform';
      } else {
        kind = 'tenant';
        slug = subdomain;
      }
    }
  }

  return {
    hostname: normalized,
    platformDomain,
    platformSubdomains: [...platformSubdomains],
    isLoopback,
    kind,
    slug,
  };
}

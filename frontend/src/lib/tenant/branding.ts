import type { TenantBranding, TenantContext } from './types';

/**
 * Applies a tenant's public branding to the document and returns the sanitized
 * values so components can render the same assets. Every value is validated —
 * nothing from the API is injected into the DOM or CSS unguarded.
 */

export const DEFAULT_TITLE = 'vee-care';

const HEX_COLOR = /^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i;
const FUNCTION_COLOR =
  /^(?:rgb|rgba|hsl|hsla)\(\s*\d{1,3}(?:\s*,\s*\d{1,3}%?){2}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i;
const FONT_FAMILY = /^[a-z][a-z0-9 ,"'-]{0,80}$/i;

export function sanitizeColor(value: string | null | undefined): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();

  return trimmed && (HEX_COLOR.test(trimmed) || FUNCTION_COLOR.test(trimmed)) ? trimmed : null;
}

export function sanitizeFontFamily(value: string | null | undefined): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();

  return trimmed && trimmed.length <= 80 && FONT_FAMILY.test(trimmed) ? trimmed : null;
}

/** Only https URLs, relative asset paths and loopback http (local dev) are allowed. */
export function sanitizeAssetUrl(value: string | null | undefined): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();

  if (!trimmed || trimmed.length > 512) {
    return null;
  }

  if (trimmed.startsWith('/') || trimmed.startsWith('./') || trimmed.startsWith('../')) {
    return trimmed;
  }

  if (/^https:\/\//i.test(trimmed)) {
    return trimmed;
  }

  // Local development serves uploaded assets over plain HTTP from the API on
  // the loopback host. Never allow arbitrary http hosts.
  if (/^http:\/\/(?:127\.0\.0\.1|localhost|0\.0\.0\.0)(?::\d+)?\//i.test(trimmed)) {
    return trimmed;
  }

  return null;
}

export interface SanitizedBranding {
  logo: string | null;
  favicon: string | null;
  primaryColor: string | null;
  secondaryColor: string | null;
  accentColor: string | null;
  fontFamily: string | null;
}

export function sanitizeBranding(branding?: TenantBranding | null): SanitizedBranding {
  return {
    logo: sanitizeAssetUrl(branding?.logo),
    favicon: sanitizeAssetUrl(branding?.favicon),
    primaryColor: sanitizeColor(branding?.primaryColor),
    secondaryColor: sanitizeColor(branding?.secondaryColor),
    accentColor: sanitizeColor(branding?.accentColor),
    fontFamily: sanitizeFontFamily(branding?.fontFamily),
  };
}

/**
 * Web fonts the design system can actually load. Mirrors the backend
 * tenant-defaults fonts allowlist; anything else never triggers a stylesheet
 * request and falls back to the system stack.
 */
const WEB_FONT_FAMILIES: Record<string, string> = {
  Inter: 'Inter',
  Roboto: 'Roboto',
  'Open Sans': 'Open+Sans',
  Poppins: 'Poppins',
  Montserrat: 'Montserrat',
};

export const SYSTEM_FONT_STACK = 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

const DEFAULT_FONT_FAMILY = 'Inter';

/** The CSS value that realises a configured family; 'system' maps to the UI stack. */
function fontCssValue(family: string): string {
  return family === 'system' ? SYSTEM_FONT_STACK : family;
}

/**
 * Ensure the effective font family's stylesheet is present. The configured
 * family wins, otherwise the Vee-Care default (Inter) is loaded so the CSS
 * fallback never silently renders in an arbitrary system font.
 */
function loadWebFont(family: string | null): void {
  const resolved = family && family !== 'system' ? family : DEFAULT_FONT_FAMILY;
  const existing = document.querySelector<HTMLLinkElement>('link[data-tenant-font]');
  const googleName = WEB_FONT_FAMILIES[resolved];

  if (!googleName) {
    existing?.remove();

    return;
  }

  const href = `https://fonts.googleapis.com/css2?family=${googleName}:wght@400;500;600;700&display=swap`;

  if (existing && existing.getAttribute('href') === href) {
    return;
  }

  existing?.remove();

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.dataset.tenantFont = 'true';
  document.head.appendChild(link);
}

let originalFaviconHref: string | null | undefined;

function setFavicon(href: string | null): void {
  const primary = document.querySelector<HTMLLinkElement>('link[rel="icon"]');

  if (originalFaviconHref === undefined) {
    originalFaviconHref = primary ? primary.getAttribute('href') : null;
  }

  if (href) {
    if (primary) {
      primary.href = href;
    } else {
      const link = document.createElement('link');
      link.rel = 'icon';
      link.dataset.tenantFavicon = 'true';
      link.href = href;
      document.head.appendChild(link);
    }
    return;
  }

  if (!primary) {
    return;
  }

  if (originalFaviconHref === null) {
    // The document shipped without an icon link — drop tenant-added ones.
    if (primary.dataset.tenantFavicon === 'true') {
      primary.remove();
    }
  } else {
    primary.setAttribute('href', originalFaviconHref);
  }
}

function setThemeColor(color: string | null): void {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');

  if (!meta) {
    return;
  }

  meta.content = color ?? '';
}

export function applyTenantBranding(tenant: TenantContext | null): SanitizedBranding | null {
  const root = document.documentElement;

  if (!tenant) {
    clearTenantBranding();
    return null;
  }

  const branding = sanitizeBranding(tenant.branding);

  if (branding.primaryColor) {
    root.style.setProperty('--app-accent', branding.primaryColor);
    // Derive the soft accent from the tenant color instead of a hardcoded tint.
    root.style.setProperty('--app-accent-soft', `color-mix(in srgb, ${branding.primaryColor} 14%, transparent)`);
  }

  if (branding.secondaryColor) {
    root.style.setProperty('--app-secondary', branding.secondaryColor);
  }

  if (branding.fontFamily) {
    root.style.setProperty('--app-font-family', fontCssValue(branding.fontFamily));
  } else {
    root.style.setProperty('--app-font-family', DEFAULT_FONT_FAMILY);
  }

  loadWebFont(branding.fontFamily);

  document.title = tenant.name ? `${tenant.name} · ${DEFAULT_TITLE}` : DEFAULT_TITLE;
  setFavicon(branding.favicon);
  setThemeColor(branding.primaryColor);

  return branding;
}

export function clearTenantBranding(): void {
  const root = document.documentElement;

  root.style.removeProperty('--app-accent');
  root.style.removeProperty('--app-accent-soft');
  root.style.removeProperty('--app-secondary');
  root.style.removeProperty('--app-font-family');

  document.querySelector<HTMLLinkElement>('link[data-tenant-font]')?.remove();

  if (document.title.includes(DEFAULT_TITLE) && document.title !== DEFAULT_TITLE) {
    document.title = DEFAULT_TITLE;
  }

  setFavicon(null);
  setThemeColor(null);
}

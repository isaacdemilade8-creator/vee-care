import { HeartPulse } from 'lucide-react';
import { useTenant } from '../../context/TenantContext';
import { sanitizeAssetUrl } from '../../lib/tenant/branding';

/**
 * Renders the active tenant's configured logo image, falling back to the
 * default Vee-Care heart mark. Used wherever the hospital/application logo is
 * displayed so a hospital's configured logo appears consistently across its
 * tenant experience. Unknown hosts and the platform get the default mark.
 *
 * The logo URL is sanitized by the same validator as the rest of the branding
 * pipeline; nothing from the API is injected into the DOM unguarded.
 */
export function TenantBrandLogo({ size = 25, alt = '' }: { size?: number; alt?: string }) {
  const { branding } = useTenant();
  const logo = sanitizeAssetUrl(branding?.logo);

  if (logo) {
    return (
      <img
        src={logo}
        alt={alt}
        style={{ height: size, width: 'auto', display: 'inline-block', objectFit: 'contain' }}
      />
    );
  }

  return <HeartPulse size={size} aria-hidden="true" />;
}

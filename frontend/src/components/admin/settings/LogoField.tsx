import { ImagePlus, Loader2, RotateCcw } from 'lucide-react';
import type { ChangeEvent } from 'react';
import { useRef, useState } from 'react';
import { endpoints } from '../../../services/endpoints';
import styles from './LogoField.module.scss';

/**
 * Image picker for branding assets (logo / favicon). Uploads through the
 * shared /uploads/images endpoint and stores the returned public URL; errors
 * are surfaced by the shared axios interceptor. A null value means "inherit
 * the Vee-Care default asset".
 */
export function LogoField({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string | null;
  onChange: (value: string | null) => void;
}) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState(false);

  const handleFile = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) {
      return;
    }

    const upload = new FormData();
    upload.append('folder', 'branding');
    upload.append('image', file);

    setUploading(true);
    try {
      const { data } = await endpoints.uploadImage(upload);
      onChange(data.url);
    } catch {
      // The shared axios interceptor already surfaced the error toast.
    } finally {
      setUploading(false);
    }
  };

  return (
    <div className={styles.field}>
      <span className={styles.preview}>
        {uploading ? (
          <Loader2 size={22} className={styles.spin} />
        ) : value ? (
          <img src={value} alt={`${label} preview`} />
        ) : (
          <ImagePlus size={22} />
        )}
      </span>
      <span className={styles.info}>
        <strong>{label}</strong>
        <code>{value ? 'Custom asset uploaded' : 'Inherit Vee-Care default'}</code>
      </span>
      <span className={styles.actions}>
        <button type="button" onClick={() => inputRef.current?.click()} disabled={uploading}>
          {uploading ? 'Uploading…' : value ? 'Replace' : 'Upload'}
        </button>
        {value ? (
          <button type="button" className={styles.reset} onClick={() => onChange(null)} disabled={uploading} aria-label={`Reset ${label}`}>
            <RotateCcw size={14} />
          </button>
        ) : null}
      </span>
      <input ref={inputRef} type="file" accept="image/*" onChange={handleFile} hidden />
    </div>
  );
}

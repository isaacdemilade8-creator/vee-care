import type { InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import styles from './FormField.module.scss';

interface BaseProps {
  label: string;
  error?: string;
}

export function TextField({ label, error, ...props }: BaseProps & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className={styles.field}>
      <span>{label}</span>
      <input {...props} />
      {error ? <small>{error}</small> : null}
    </label>
  );
}

export function SelectField({ label, error, children, ...props }: BaseProps & SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <label className={styles.field}>
      <span>{label}</span>
      <select {...props}>{children}</select>
      {error ? <small>{error}</small> : null}
    </label>
  );
}

export function TextAreaField({ label, error, ...props }: BaseProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <label className={styles.field}>
      <span>{label}</span>
      <textarea {...props} />
      {error ? <small>{error}</small> : null}
    </label>
  );
}

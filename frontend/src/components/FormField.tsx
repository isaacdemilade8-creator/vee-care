import type { InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import styles from './FormField.module.scss';

interface BaseProps {
  label: string;
  error?: string;
}

export function TextField({ label, error, ...props }: BaseProps & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className={styles.field} data-error={!!error || undefined}>
      <span>{label}</span>
      <input {...props} />
      {error ? <small>{error}</small> : null}
    </label>
  );
}

export function SelectField({ label, error, children, ...props }: BaseProps & SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <label className={styles.field} data-error={!!error || undefined}>
      <span>{label}</span>
      <select {...props}>{children}</select>
      {error ? <small>{error}</small> : null}
    </label>
  );
}

export function TextAreaField({ label, error, ...props }: BaseProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <label className={styles.field} data-error={!!error || undefined}>
      <span>{label}</span>
      <textarea {...props} />
      {error ? <small>{error}</small> : null}
    </label>
  );
}

export function CheckboxField({ label, error, className, ...props }: BaseProps & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className={`${styles.checkboxField} ${className ?? ''}`} data-error={!!error || undefined}>
      <input type="checkbox" {...props} />
      <span className={styles.checkmark} />
      <span>{label}</span>
      {error ? <small>{error}</small> : null}
    </label>
  );
}

interface RadioGroupProps {
  label: string;
  name: string;
  options: Array<{ label: string; value: string }>;
  value?: string;
  onChange?: (value: string) => void;
  error?: string;
}

export function RadioGroup({ label, name, options, value, onChange, error }: RadioGroupProps) {
  return (
    <div className={styles.radioGroup} data-error={!!error || undefined}>
      <span>{label}</span>
      {options.map((option) => (
        <label key={option.value} className={styles.radioField}>
          <input
            type="radio"
            name={name}
            value={option.value}
            checked={value === option.value}
            onChange={() => onChange?.(option.value)}
          />
          <span className={styles.radiomark} />
          <span>{option.label}</span>
        </label>
      ))}
      {error ? <small>{error}</small> : null}
    </div>
  );
}

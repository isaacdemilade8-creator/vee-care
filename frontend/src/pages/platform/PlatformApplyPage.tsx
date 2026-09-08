import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { AlertCircle, ArrowRight, Building2, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router-dom';
import { z } from 'zod';
import { Button } from '../../components/Button';
import { SelectField, TextAreaField, TextField } from '../../components/FormField';
import { platformEndpoints } from '../../lib/platform/api';
import type { HospitalApplicationSubmission } from '../../lib/platform/types';
import { getApiErrorMessage, getValidationErrors } from '../../utils/apiError';
import authStyles from '../AuthPage.module.scss';
import styles from './PlatformApplyPage.module.scss';

const slugPattern = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

const schema = z.object({
  hospital_name: z.string().trim().min(1, 'Hospital name is required').max(255),
  slug: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, 'A subdomain is required')
    .max(63, 'Subdomain must be 63 characters or fewer')
    .refine((value) => slugPattern.test(value), {
      message: 'Use only lowercase letters, numbers and hyphens, and no leading or trailing hyphens.',
    }),
  type: z.enum(['hospital', 'clinic', 'lab', 'pharmacy']),
  contact_name: z.string().trim().min(1, 'A contact name is required').max(255),
  contact_email: z.string().trim().email('Enter a valid email address').max(255),
  contact_phone: z
    .string()
    .trim()
    .max(40, 'Phone must be 40 characters or fewer')
    .optional()
    .or(z.literal('')),
  description: z.string().trim().max(2000, 'Description must be 2000 characters or fewer').optional(),
});

type ApplyFormInput = z.input<typeof schema>;
type ApplyForm = z.output<typeof schema>;

const hospitalTypeOptions = [
  { value: 'hospital', label: 'Hospital' },
  { value: 'clinic', label: 'Clinic' },
  { value: 'lab', label: 'Laboratory' },
  { value: 'pharmacy', label: 'Pharmacy' },
];

/**
 * Public platform hospital application page.
 *
 * Hospitals apply to join the Vee-Care platform here. The submission uses the
 * unauthenticated client so it works before (or without) a platform session.
 * On success the applicant is told the application is pending review and to
 * watch their contact email — no credentials or tokens are ever returned.
 */
export function PlatformApplyPage() {
  const [genericError, setGenericError] = useState<string | null>(null);
  const [submittedSlug, setSubmittedSlug] = useState<string | null>(null);
  const [submittedEmail, setSubmittedEmail] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<ApplyFormInput, unknown, ApplyForm>({
    resolver: zodResolver(schema),
    defaultValues: { type: 'hospital' },
  });

  const submit = useMutation({
    mutationFn: async (values: ApplyForm) => {
      const payload: HospitalApplicationSubmission = {
        hospital_name: values.hospital_name,
        slug: values.slug,
        type: values.type,
        contact_name: values.contact_name,
        contact_email: values.contact_email,
        contact_phone: values.contact_phone?.trim() || undefined,
        description: values.description?.trim() || undefined,
      };
      const response = await platformEndpoints.submitHospitalApplication(payload);
      return response.data.data;
    },
    onSuccess: (application) => {
      setGenericError(null);
      setSubmittedSlug(application.slug);
      setSubmittedEmail(application.contactEmail);
      reset();
    },
    onError: (error) => {
      const validation = getValidationErrors(error);

      const rateLimited = isRateLimitError(error);
      if (rateLimited) {
        setGenericError(
          'Too many requests. Please wait a moment and try again, or contact the Vee-Care team.',
        );
        return;
      }

      for (const field of [
        'hospital_name',
        'slug',
        'type',
        'contact_name',
        'contact_email',
        'contact_phone',
        'description',
      ] as const) {
        if (validation[field]) {
          setError(field, { message: validation[field] });
        }
      }

      if (Object.keys(validation).length === 0 && !rateLimited) {
        setGenericError(
          getApiErrorMessage(error, 'We could not submit your application. Please try again.'),
        );
      }
    },
  });

  const onSubmit = (values: ApplyForm) => {
    if (submit.isPending) {
      return;
    }
    setGenericError(null);
    submit.mutate(values);
  };

  return (
    <main className={authStyles.page}>
      <div className={styles.wrapper}>
        <div className={authStyles.form}>
          <span className={authStyles.brand}>
            <Building2 size={22} /> vee-care Platform
          </span>

          {submittedSlug ? (
            <div className={styles.stateCard} role="status">
              <span className={styles.successIcon}>
                <CheckCircle2 size={32} />
              </span>
              <h1>Application received</h1>
              <p className={styles.stateLead}>
                Thank you — your hospital application for{' '}
                <strong>{submittedSlug}.vee-care.test</strong> has been received.
              </p>
              <ul className={styles.stateList}>
                <li>Your application is now pending platform review.</li>
                <li>
                  Watch <strong>{submittedEmail}</strong> for updates and your setup invitation.
                </li>
                <li>Your hospital account becomes active once your application is approved.</li>
              </ul>
              <Button variant="primary" onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}>
                Apply for another hospital <ArrowRight size={16} />
              </Button>
            </div>
          ) : (
            <form className={authStyles.fields} onSubmit={handleSubmit(onSubmit)}>
              <h1>Apply to join Vee-Care</h1>
              <p>
                Register your healthcare organisation. A platform administrator will review your
                application before your hospital is activated.
              </p>

              {genericError ? (
                <div className={styles.alert} role="alert">
                  <AlertCircle size={18} />
                  <span>{genericError}</span>
                </div>
              ) : null}

              <TextField
                label="Hospital name"
                autoComplete="organization"
                maxLength={255}
                error={errors.hospital_name?.message}
                {...register('hospital_name')}
              />
              <TextField
                label="Subdomain (slug)"
                placeholder="e.g. hospital-three"
                maxLength={63}
                autoComplete="off"
                error={errors.slug?.message}
                {...register('slug')}
              />
              <SelectField label="Organisation type" error={errors.type?.message} {...register('type')}>
                {hospitalTypeOptions.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </SelectField>
              <TextField
                label="Contact name"
                autoComplete="name"
                maxLength={255}
                error={errors.contact_name?.message}
                {...register('contact_name')}
              />
              <TextField
                label="Contact email"
                type="email"
                autoComplete="email"
                maxLength={255}
                error={errors.contact_email?.message}
                {...register('contact_email')}
              />
              <TextField
                label="Contact phone (optional)"
                type="tel"
                autoComplete="tel"
                maxLength={40}
                error={errors.contact_phone?.message}
                {...register('contact_phone')}
              />
              <TextAreaField
                label="Description (optional)"
                rows={4}
                maxLength={2000}
                error={errors.description?.message}
                {...register('description')}
              />

              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Submitting application…' : 'Submit application'}
              </Button>

              <p>
                Already managing a hospital on Vee-Care? <Link to="/platform/login">Sign in</Link>
              </p>
            </form>
          )}
        </div>
      </div>
    </main>
  );
}

function isRateLimitError(error: unknown): boolean {
  if (typeof error !== 'object' || error === null) {
    return false;
  }
  const status = (error as { response?: { status?: number } }).response?.status;
  return status === 429;
}

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { AlertCircle, ArrowRight, CheckCircle2, Stethoscope } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { z } from 'zod';
import { Button } from '../components/Button';
import { TextField } from '../components/FormField';
import { useTenantName } from '../context/TenantContext';
import { endpoints } from '../services/endpoints';
import { getApiErrorMessage, getValidationErrors } from '../utils/apiError';
import authStyles from './AuthPage.module.scss';
import styles from './AdminInvitationAcceptPage.module.scss';

const schema = z
  .object({
    name: z.string().trim().max(255).optional(),
    password: z.string().min(8),
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords must match',
    path: ['password_confirmation'],
  });

type AcceptFormInput = z.input<typeof schema>;
type AcceptForm = z.output<typeof schema>;

/**
 * Public hospital-user invitation acceptance.
 *
 * Reached only via the single-use invitation link
 * (/invitations/:token/accept) embedded in the URL — the raw token is read
 * from the route, sent once with the POST to the existing API endpoint (which
 * remains POST-only) and is never persisted, displayed or logged.
 */
export function AdminInvitationAcceptPage() {
  const { token } = useParams<{ token: string }>();
  const navigate = useNavigate();
  const tenantName = useTenantName();
  const [acceptedEmail, setAcceptedEmail] = useState<string | null>(null);
  const [invalidReason, setInvalidReason] = useState<string | null>(null);
  const [genericError, setGenericError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<AcceptFormInput, unknown, AcceptForm>({
    resolver: zodResolver(schema),
  });

  const accept = useMutation({
    mutationFn: async (values: AcceptForm) => {
      const response = await endpoints.acceptAdminUserInvitation(token ?? '', values);
      return response.data;
    },
    onSuccess: ({ user }) => {
      setAcceptedEmail(user.email);
      setInvalidReason(null);
      setGenericError(null);
    },
    onError: (error) => {
      const validation = getValidationErrors(error);
      const tokenError = validation.token;

      if (tokenError) {
        setInvalidReason(tokenError);
        setGenericError(null);
        return;
      }

      for (const field of ['name', 'password', 'password_confirmation'] as const) {
        if (validation[field]) {
          setError(field, { message: validation[field] });
        }
      }

      if (Object.keys(validation).length === 0) {
        setGenericError(getApiErrorMessage(error, 'Unable to accept the invitation. Please try again.'));
      }
    },
  });

  const onSubmit = (values: AcceptForm) => {
    if (!token || accept.isPending) {
      return;
    }
    setInvalidReason(null);
    setGenericError(null);
    accept.mutate(values);
  };

  return (
    <main className={authStyles.page}>
      <div className={authStyles.formWrapper}>
        <div className={authStyles.form}>
          <span className={authStyles.brand}>
            <Stethoscope size={22} /> {tenantName}
          </span>

          {acceptedEmail ? (
            <div className={styles.stateCard} role="status">
              <span className={styles.successIcon}>
                <CheckCircle2 size={32} />
              </span>
              <h1>Invitation accepted</h1>
              <p>
                Your hospital account for <strong>{acceptedEmail}</strong> is ready.
                You can now sign in with the password you just set.
              </p>
              <Button variant="primary" onClick={() => navigate('/login')}>
                Go to sign in <ArrowRight size={16} />
              </Button>
            </div>
          ) : (
            <form className={authStyles.fields} onSubmit={handleSubmit(onSubmit)}>
              <h1>Accept your invitation</h1>
              <p>Join {tenantName} as a hospital user. Set a password to continue.</p>

              {invalidReason ? (
                <div className={styles.alert} role="alert">
                  <AlertCircle size={18} />
                  <span>{invalidReason}</span>
                </div>
              ) : null}

              {genericError ? (
                <div className={styles.alert} role="alert">
                  <AlertCircle size={18} />
                  <span>{genericError}</span>
                </div>
              ) : null}

              <TextField
                label="Full name (optional)"
                autoComplete="name"
                maxLength={255}
                error={errors.name?.message}
                {...register('name')}
              />
              <TextField
                label="Password"
                type="password"
                autoComplete="new-password"
                error={errors.password?.message}
                {...register('password')}
              />
              <TextField
                label="Confirm password"
                type="password"
                autoComplete="new-password"
                error={errors.password_confirmation?.message}
                {...register('password_confirmation')}
              />

              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Setting your password…' : 'Accept invitation'}
              </Button>

              <p>
                Already have an account? <Link to="/login">Sign in</Link>
              </p>
            </form>
          )}
        </div>
      </div>
    </main>
  );
}

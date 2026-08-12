import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import toast from 'react-hot-toast';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { Building2 } from 'lucide-react';
import { Button } from '../components/Button';
import { TextField } from '../components/FormField';
import { useAuth } from '../context/AuthContext';
import { platformEndpoints } from '../lib/platform/api';
import { getApiErrorMessage } from '../utils/apiError';
import styles from './AuthPage.module.scss';

const schema = z.object({
  email: z.string().email(),
  password: z.string().min(8),
});

type PlatformLoginInput = z.input<typeof schema>;
type PlatformLoginForm = z.output<typeof schema>;

/**
 * Control-plane login, served only on the platform host. Authenticates against
 * the control database via the dedicated platform guard.
 */
export function PlatformLoginPage() {
  const navigate = useNavigate();
  const { setPlatformSession } = useAuth();
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<PlatformLoginInput, unknown, PlatformLoginForm>({
    resolver: zodResolver(schema),
  });

  const onSubmit = async (values: PlatformLoginForm) => {
    try {
      const response = await platformEndpoints.login(values);
      setPlatformSession(response.data.user, response.data.token);
      toast.success('Welcome back');
      navigate('/platform', { replace: true });
    } catch (error) {
      toast.error(getApiErrorMessage(error, 'Platform sign-in failed'));
    }
  };

  return (
    <main className={styles.page}>
      <div className={styles.formWrapper}>
        <form className={styles.form} onSubmit={handleSubmit(onSubmit)}>
          <span className={styles.brand}><Building2 size={22} /> vee-care Platform</span>
          <h1>Control plane sign in</h1>

          <div className={styles.fields}>
            <TextField label="Email" type="email" autoComplete="email" error={errors.email?.message} {...register('email')} />
            <TextField label="Password" type="password" autoComplete="current-password" error={errors.password?.message} {...register('password')} />
          </div>

          <Button disabled={isSubmitting}>
            {isSubmitting ? 'Please wait...' : 'Sign in'}
          </Button>
        </form>
      </div>
    </main>
  );
}

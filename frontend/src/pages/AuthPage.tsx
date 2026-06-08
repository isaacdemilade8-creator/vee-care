import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import toast from 'react-hot-toast';
import { Link, useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { Button } from '../components/Button';
import { SelectField, TextField } from '../components/FormField';
import { useAuth } from '../context/AuthContext';
import { endpoints } from '../services/endpoints';
import styles from './AuthPage.module.scss';

const schema = z
  .object({
    name: z.string().min(2).optional(),
    email: z.string().email(),
    password: z.string().min(8),
    password_confirmation: z.string().optional(),
    role: z.literal('patient').optional(),
  })
  .refine((data) => !data.password_confirmation || data.password === data.password_confirmation, {
    message: 'Passwords must match',
    path: ['password_confirmation'],
  });

type AuthFormInput = z.input<typeof schema>;
type AuthForm = z.output<typeof schema>;

export function AuthPage({ mode }: { mode: 'login' | 'register' }) {
  const navigate = useNavigate();
  const { setSession } = useAuth();
  const isRegister = mode === 'register';
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<AuthFormInput, unknown, AuthForm>({
    resolver: zodResolver(schema),
    defaultValues: { role: 'patient' },
  });

  const onSubmit = async (values: AuthForm) => {
    const response = isRegister ? await endpoints.register(values) : await endpoints.login(values);
    setSession(response.data.user, response.data.token);
    toast.success(isRegister ? 'Account created' : 'Welcome back');
    navigate('/dashboard');
  };

  return (
    <main className={styles.page}>
      <form className={styles.form} onSubmit={handleSubmit(onSubmit)}>
        <Link to="/" className={styles.brand}>vee-care</Link>
        <h1>{isRegister ? 'Create account' : 'Welcome back'}</h1>
        {isRegister ? <TextField label="Full name" error={errors.name?.message} {...register('name')} /> : null}
        <TextField label="Email" type="email" error={errors.email?.message} {...register('email')} />
        <TextField label="Password" type="password" error={errors.password?.message} {...register('password')} />
        {isRegister ? (
          <>
            <TextField label="Confirm password" type="password" error={errors.password_confirmation?.message} {...register('password_confirmation')} />
            <SelectField label="Account type" {...register('role')}>
              <option value="patient">Patient</option>
            </SelectField>
          </>
        ) : null}
        <Button disabled={isSubmitting}>{isSubmitting ? 'Please wait...' : isRegister ? 'Register' : 'Login'}</Button>
        <p>{isRegister ? 'Already registered?' : 'Need an account?'} <Link to={isRegister ? '/login' : '/register'}>{isRegister ? 'Login' : 'Register'}</Link></p>
      </form>
    </main>
  );
}

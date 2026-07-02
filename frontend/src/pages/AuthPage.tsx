import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import toast from 'react-hot-toast';
import { Link, useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { motion, useMotionValue, useTransform, type Variants } from 'framer-motion';
import { HeartPulse } from 'lucide-react';
import { Button } from '../components/Button';
import { SelectField, TextField } from '../components/FormField';
import { useAuth } from '../context/AuthContext';
import { endpoints } from '../services/endpoints';
import { getApiErrorMessage } from '../utils/apiError';
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

const containerVariants: Variants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: { staggerChildren: 0.08, delayChildren: 0.2 },
  },
};

const itemVariants: Variants = {
  hidden: { opacity: 0, y: 18 },
  visible: {
    opacity: 1,
    y: 0,
    transition: { duration: 0.4, ease: 'easeOut' as const },
  },
};

const decorOrbs = [
  { top: '15%', left: '8%', size: 220, delay: 0 },
  { top: '60%', right: '5%', size: 180, delay: 1.5 },
  { top: '25%', right: '10%', size: 140, delay: 0.8 },
  { bottom: '8%', left: '12%', size: 120, delay: 2.2 },
];

export function AuthPage({ mode }: { mode: 'login' | 'register' }) {
  const navigate = useNavigate();
  const { setSession } = useAuth();
  const isRegister = mode === 'register';
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<AuthFormInput, unknown, AuthForm>({
    resolver: zodResolver(schema),
    defaultValues: { role: 'patient' },
  });

  const mx = useMotionValue(0.5);
  const my = useMotionValue(0.5);
  const rotateX = useTransform(my, [0, 1], [5, -5]);
  const rotateY = useTransform(mx, [0, 1], [-5, 5]);

  const handlePointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    mx.set((e.clientX - rect.left) / rect.width);
    my.set((e.clientY - rect.top) / rect.height);
  };

  const handlePointerLeave = () => {
    mx.set(0.5);
    my.set(0.5);
  };

  const onSubmit = async (values: AuthForm) => {
    try {
      const response = isRegister ? await endpoints.register(values) : await endpoints.login(values);
      setSession(response.data.user, response.data.token);
      toast.success(isRegister ? 'Account created' : 'Welcome back');
      navigate('/dashboard');
    } catch (error) {
      toast.error(getApiErrorMessage(error, 'Authentication failed'));
    }
  };

  return (
    <motion.main
      className={styles.page}
      initial="hidden"
      animate="visible"
      variants={containerVariants}
    >
      {decorOrbs.map((orb, i) => (
        <motion.div
          key={i}
          className={styles.decorOrb}
          style={{
            width: orb.size,
            height: orb.size,
            top: 'top' in orb ? orb.top : undefined,
            left: 'left' in orb ? orb.left : undefined,
            right: 'right' in orb ? orb.right : undefined,
            bottom: 'bottom' in orb ? orb.bottom : undefined,
          }}
          animate={{
            y: [0, -24, 0],
            scale: [1, 1.1, 1],
          }}
          transition={{
            duration: 7 + i * 0.6,
            repeat: Infinity,
            ease: 'easeInOut',
            delay: orb.delay,
          }}
        />
      ))}

      <motion.div
        className={styles.floatingLogo}
        animate={{
          y: [0, -6, 0],
          rotate: [0, 4, 0, -4, 0],
        }}
        transition={{
          duration: 4,
          repeat: Infinity,
          ease: 'easeInOut',
        }}
      >
        <HeartPulse size={28} />
      </motion.div>

      <motion.div
        className={styles.formWrapper}
        variants={itemVariants}
        onPointerMove={handlePointerMove}
        onPointerLeave={handlePointerLeave}
        style={{ perspective: 1200 }}
      >
        <motion.form
          className={styles.form}
          style={{ rotateX, rotateY }}
          transition={{ type: 'spring', stiffness: 150, damping: 15 }}
          onSubmit={handleSubmit(onSubmit)}
        >
          <Link to="/" className={styles.brand}>vee-care</Link>
          <h1>{isRegister ? 'Create account' : 'Welcome back'}</h1>

          <div className={styles.fields}>
            {isRegister ? (
              <motion.div key="name" variants={itemVariants}>
                <TextField label="Full name" autoComplete="name" error={errors.name?.message} {...register('name')} />
              </motion.div>
            ) : null}
            <motion.div key="email" variants={itemVariants}>
              <TextField label="Email" type="email" autoComplete="email" error={errors.email?.message} {...register('email')} />
            </motion.div>
            <motion.div key="password" variants={itemVariants}>
              <TextField label="Password" type="password" autoComplete={isRegister ? 'new-password' : 'current-password'} error={errors.password?.message} {...register('password')} />
            </motion.div>
            {isRegister ? (
              <motion.div key="register-fields" variants={itemVariants}>
                <TextField label="Confirm password" type="password" autoComplete="new-password" error={errors.password_confirmation?.message} {...register('password_confirmation')} />
                <SelectField label="Account type" {...register('role')}>
                  <option value="patient">Patient</option>
                </SelectField>
              </motion.div>
            ) : null}
          </div>

          <motion.div key="submit" variants={itemVariants}>
            <Button disabled={isSubmitting}>
              {isSubmitting ? 'Please wait...' : isRegister ? 'Register' : 'Login'}
            </Button>
          </motion.div>

          <motion.p key="toggle" variants={itemVariants}>
            {isRegister ? 'Already registered?' : 'Need an account?'}{' '}
            <Link to={isRegister ? '/login' : '/register'}>
              {isRegister ? 'Login' : 'Register'}
            </Link>
          </motion.p>
        </motion.form>
      </motion.div>
    </motion.main>
  );
}

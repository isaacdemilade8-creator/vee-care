import type { ReactNode } from 'react';
import { motion } from 'framer-motion';
import type { HTMLMotionProps } from 'framer-motion';
import styles from './Button.module.scss';

interface ButtonProps extends HTMLMotionProps<'button'> {
  variant?: 'primary' | 'secondary' | 'ghost';
  children: ReactNode;
}

export function Button({ variant = 'primary', className = '', children, ...props }: ButtonProps) {
  return (
    <motion.button
      className={`${styles.button} ${styles[variant]} ${className}`}
      whileTap={{ scale: props.disabled ? 1 : 0.97 }}
      transition={{ duration: 0.16 }}
      {...props}
    >
      {children}
    </motion.button>
  );
}

import type { ComponentType, ReactNode } from 'react';
import { motion } from 'framer-motion';
import type { HTMLMotionProps } from 'framer-motion';
import styles from './Card.module.scss';

export function Card({ children, className = '', ...props }: { children: ReactNode; className?: string } & HTMLMotionProps<'section'>) {
  return (
    <motion.section
      className={`${styles.card} ${className}`}
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.28, ease: 'easeOut' }}
      {...props}
    >
      {children}
    </motion.section>
  );
}

export function StatCard({ label, value, icon: Icon }: { label: string; value: string | number; icon?: ComponentType<{ size?: number; className?: string }> }) {
  return (
    <Card>
      {Icon ? <Icon size={20} className={styles.statIcon} /> : null}
      <span className={styles.label}>{label}</span>
      <strong className={styles.value}>{value}</strong>
    </Card>
  );
}

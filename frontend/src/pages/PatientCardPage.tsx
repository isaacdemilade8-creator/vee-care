import { useQuery } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import type { Variants } from 'framer-motion';
import { AlertCircle, CreditCard, Plus } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { endpoints } from '../services/endpoints';
import { VirtualCard } from '../components/VirtualCard';
import styles from './PatientCardPage.module.scss';

const pageMotion: Variants = {
  hidden: { opacity: 0 },
  show: { opacity: 1, transition: { staggerChildren: 0.06 } },
};

const itemMotion: Variants = {
  hidden: { opacity: 0, y: 12 },
  show: { opacity: 1, y: 0, transition: { duration: 0.25, ease: 'easeOut' } },
};

export function PatientCardPage() {
  const { user } = useAuth();

  const { data, isLoading } = useQuery({
    queryKey: ['my-card'],
    queryFn: async () => (await endpoints.myCard()).data,
  });

  const card = data?.card ?? null;

  return (
    <motion.div className={styles.page} variants={pageMotion} initial="hidden" animate="show">
      <motion.div className={styles.header} variants={itemMotion}>
        <div>
          <span>Membership</span>
          <h2>My Virtual Card</h2>
          <p>Your digital hospital membership card for fast, card-based service access.</p>
        </div>
      </motion.div>

      <motion.div className={styles.cardSection} variants={itemMotion}>
        {isLoading ? (
          <div className={styles.loading}>Loading your card...</div>
        ) : card ? (
          <VirtualCard card={card} />
        ) : (
          <div className={styles.noCard}>
            <div className={styles.noCardIcon}>
              <CreditCard size={36} />
            </div>
            <h3>No card issued yet</h3>
            <p>
              {user?.role === 'patient'
                ? 'Visit the nurse station to get your virtual membership card issued. Once issued, you can use it for faster appointments and service access.'
                : 'No virtual cards have been issued yet. Cards can be created from the Nurse Station.'}
            </p>
          </div>
        )}
      </motion.div>

      {card ? (
        <motion.div className={styles.benefits} variants={itemMotion}>
          <h3>Card benefits</h3>
          <div className={styles.benefitGrid}>
            <div className={styles.benefit}>
              <div className={styles.benefitDot} style={{ background: '#059669' }} />
              <strong>Express booking</strong>
              <p>Skip payment steps when booking appointments</p>
            </div>
            <div className={styles.benefit}>
              <div className={styles.benefitDot} style={{ background: '#3b82f6' }} />
              <strong>Membership ID</strong>
              <p>Use your card number for hospital identification</p>
            </div>
            <div className={styles.benefit}>
              <div className={styles.benefitDot} style={{ background: '#8b5cf6' }} />
              <strong>Service tracking</strong>
              <p>All your hospital services linked to one card</p>
            </div>
            <div className={styles.benefit}>
              <div className={styles.benefitDot} style={{ background: '#f59e0b' }} />
              <strong>Renewal reminders</strong>
              <p>Get notified before your card expires</p>
            </div>
          </div>
        </motion.div>
      ) : null}
    </motion.div>
  );
}

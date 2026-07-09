import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import type { Variants } from 'framer-motion';
import { CreditCard } from 'lucide-react';
import toast from 'react-hot-toast';
import { Button } from '../components/Button';
import { Modal } from '../components/Modal';
import { TextField } from '../components/FormField';
import { VirtualCard } from '../components/VirtualCard';
import { useAuth } from '../context/AuthContext';
import { endpoints } from '../services/endpoints';
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
  const queryClient = useQueryClient();
  const [showRequestModal, setShowRequestModal] = useState(false);
  const [cardNumber, setCardNumber] = useState('');
  const [cardName, setCardName] = useState('');
  const [expiry, setExpiry] = useState('');
  const [cvv, setCvv] = useState('');
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const { data, isLoading } = useQuery({
    queryKey: ['my-card'],
    queryFn: async () => (await endpoints.myCard()).data,
  });

  const card = data?.card ?? null;

  const requestCardMutation = useMutation({
    mutationFn: async (payload: { cardNumber: string; cardName: string; expiry: string; cvv: string }) =>
      (await endpoints.requestPatientCard(payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-card'] });
      toast.success('Card issued successfully!');
      setShowRequestModal(false);
      setCardNumber('');
      setCardName('');
      setExpiry('');
      setCvv('');
      setFormErrors({});
    },
    onError: (error: unknown) => {
      const message = error instanceof Error ? error.message : 'Card request failed. Please try again.';
      toast.error(message);
    },
  });

  const validatePayment = (): boolean => {
    const errors: Record<string, string> = {};
    if (!/^\d{16}$/.test(cardNumber)) errors.cardNumber = 'Enter a valid 16-digit card number';
    if (cardName.length < 3) errors.cardName = 'Enter the cardholder name';
    if (!/^\d{2}\/\d{2}$/.test(expiry)) errors.expiry = 'Use MM/YY format';
    if (!/^\d{3,4}$/.test(cvv)) errors.cvv = 'Enter a valid CVV';
    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleRequestCard = (event: React.FormEvent) => {
    event.preventDefault();
    if (!validatePayment()) return;
    requestCardMutation.mutate({ cardNumber, cardName, expiry, cvv });
  };

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
                ? 'Request a virtual membership card to enjoy faster bookings and service access.'
                : 'No virtual cards have been issued yet. Cards can be created from the Nurse Station.'}
            </p>
            {user?.role === 'patient' ? (
              <Button onClick={() => setShowRequestModal(true)}>Request a card</Button>
            ) : null}
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

      {showRequestModal ? (
        <Modal title="Request a membership card" onClose={() => setShowRequestModal(false)}>
          <form onSubmit={handleRequestCard} style={{ display: 'grid', gap: '1rem' }}>
            <p style={{ color: 'var(--app-muted)', margin: 0, lineHeight: 1.5 }}>
              Pay the one-time membership fee to get your Vee-care virtual card. Once issued, you can use it for faster appointment bookings.
            </p>
            <TextField
              label="Card number"
              placeholder="1234 5678 9012 3456"
              value={cardNumber}
              onChange={(event) => setCardNumber(event.target.value)}
              error={formErrors.cardNumber}
            />
            <TextField
              label="Cardholder name"
              placeholder="John Doe"
              value={cardName}
              onChange={(event) => setCardName(event.target.value)}
              error={formErrors.cardName}
            />
            <div style={{ display: 'grid', gap: '0.75rem', gridTemplateColumns: '1fr 1fr' }}>
              <TextField
                label="Expiry (MM/YY)"
                placeholder="12/26"
                value={expiry}
                onChange={(event) => setExpiry(event.target.value)}
                error={formErrors.expiry}
              />
              <TextField
                label="CVV"
                placeholder="123"
                value={cvv}
                onChange={(event) => setCvv(event.target.value)}
                error={formErrors.cvv}
              />
            </div>
            <p style={{ color: 'var(--app-muted)', fontSize: '0.8rem', margin: 0 }}>
              This is a demo &mdash; no real payment will be processed.
            </p>
            <Button disabled={requestCardMutation.isPending}>
              {requestCardMutation.isPending ? 'Processing...' : 'Pay & get card'}
            </Button>
          </form>
        </Modal>
      ) : null}
    </motion.div>
  );
}

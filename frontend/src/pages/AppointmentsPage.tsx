import { useMemo, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm, useWatch } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Link } from 'react-router-dom';
import { z } from 'zod';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SelectField, TextAreaField, TextField } from '../components/FormField';
import { Modal } from '../components/Modal';
import { SkeletonRows } from '../components/Skeleton';
import { VirtualCard } from '../components/VirtualCard';
import { useAuth } from '../context/AuthContext';
import { useApiMutation, useAppointments, useDoctors } from '../hooks/useApi';
import { endpoints } from '../services/endpoints';
import type { Appointment } from '../types';
import styles from './TablePage.module.scss';

const appointmentSchema = z.object({
  doctor_id: z.coerce.number().min(1),
  scheduled_at: z.string().min(1),
  reason: z.string().min(3),
  notes: z.string().optional(),
});

const paymentSchema = z.object({
  cardNumber: z.string().regex(/^\d{16}$/, 'Enter a valid 16-digit card number'),
  cardName: z.string().min(3, 'Enter the cardholder name'),
  expiry: z.string().regex(/^\d{2}\/\d{2}$/, 'Use MM/YY format'),
  cvv: z.string().regex(/^\d{3,4}$/, 'Enter a valid CVV'),
});

export function AppointmentsPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [bookingStep, setBookingStep] = useState<'details' | 'payment'>('details');
  const [doctorSearch, setDoctorSearch] = useState('');
  const [specialty, setSpecialty] = useState('');
  const [minRating, setMinRating] = useState('');
  const [reviewAppointment, setReviewAppointment] = useState<Appointment | null>(null);
  const [rating, setRating] = useState(5);
  const [reviewComment, setReviewComment] = useState('');
  const [payByCard, setPayByCard] = useState(true);
  const [paymentMode, setPaymentMode] = useState<'request_card' | 'pay_direct'>('request_card');
  const [appointmentData, setAppointmentData] = useState<z.output<typeof appointmentSchema> | null>(null);
  const appointments = useAppointments(status ? { status } : undefined);
  const allDoctors = useDoctors();
  const doctors = useDoctors({
    ...(doctorSearch ? { search: doctorSearch } : {}),
    ...(specialty ? { specialty } : {}),
    ...(minRating ? { min_rating: minRating } : {}),
  });
  const { data: myCard } = useQuery({
    queryKey: ['my-card'],
    queryFn: async () => (await endpoints.myCard()).data,
    enabled: user?.role === 'patient',
  });
  const hasActiveCard = Boolean(myCard?.card?.status === 'active');
  const createAppointment = useApiMutation((payload: unknown) => endpoints.createAppointment(payload), ['appointments'], 'Appointment requested');
  const updateAppointment = useApiMutation(({ id, payload }: { id: number; payload: unknown }) => endpoints.updateAppointment(id, payload), ['appointments'], 'Appointment updated');
  const requestCardMutation = useMutation({
    mutationFn: async (payload: { cardNumber: string; cardName: string; expiry: string; cvv: string }) =>
      (await endpoints.requestPatientCard(payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-card'] });
    },
  });
  const createReview = useMutation({
    mutationFn: ({ doctorId, payload }: { doctorId: number; payload: unknown }) => endpoints.createProfileReview(doctorId, payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['appointments'] }),
        queryClient.invalidateQueries({ queryKey: ['doctors'] }),
        queryClient.invalidateQueries({ queryKey: ['profiles'] }),
      ]);
      toast.success('Review submitted');
      setReviewAppointment(null);
      setRating(5);
      setReviewComment('');
    },
  });
  const { register, handleSubmit, control, formState: { errors } } = useForm<z.input<typeof appointmentSchema>, unknown, z.output<typeof appointmentSchema>>({
    resolver: zodResolver(appointmentSchema),
  });
  const {
    register: registerPayment,
    handleSubmit: handlePaymentSubmit,
    formState: { errors: paymentErrors },
  } = useForm<z.input<typeof paymentSchema>, unknown, z.output<typeof paymentSchema>>({
    resolver: zodResolver(paymentSchema),
  });
  const selectedDoctorId = Number(useWatch({ control, name: 'doctor_id' }));
  const specialtyOptions = useMemo(() => {
    const values = new Set<string>();
    allDoctors.data?.data.forEach((doctor) => {
      if (doctor.specialty) {
        values.add(doctor.specialty);
      }
    });
    return Array.from(values).sort();
  }, [allDoctors.data?.data]);

  const selectedDoctor = doctors.data?.data.find((doctor) => doctor.id === selectedDoctorId);

  const goToPayment = () => setBookingStep('payment');

  const bookDirect = (data: z.output<typeof appointmentSchema>) => {
    createAppointment.mutate(data, {
      onSuccess: () => {
        setShowModal(false);
        setBookingStep('details');
        setAppointmentData(null);
      },
    });
  };

  const onPayDirect = () => {
    if (!appointmentData) return;
    toast.success('Payment processed (demo)');
    bookDirect(appointmentData);
  };

  const onRequestCard = (paymentData: { cardNumber: string; cardName: string; expiry: string; cvv: string }) => {
    if (!appointmentData) return;
    requestCardMutation.mutate(paymentData, {
      onSuccess: () => {
        toast.success('Card payment processed (demo)');
        toast.success('Membership card issued!');
        bookDirect(appointmentData!);
      },
      onError: () => {
        toast.error('Card request failed. Please try again.');
      },
    });
  };

  const bookOrProceed = (data: z.output<typeof appointmentSchema>) => {
    if (hasActiveCard) {
      bookDirect(data);
    } else {
      setAppointmentData(data);
      goToPayment();
    }
  };

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <h2>Appointments</h2>
        <div>
          <select value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="completed">Completed</option>
          </select>
          {user?.role === 'patient' ? <Button onClick={() => setShowModal(true)}>Book</Button> : null}
        </div>
      </div>
      <Card>
        {appointments.isLoading ? <SkeletonRows /> : (
          appointments.data?.data.length ? (
            <div className={styles.table}>
              {appointments.data.data.map((item) => (
                <article key={item.id}>
                  <strong>{item.reason}</strong>
                  <span>{item.patient?.name} with {item.doctor?.name}</span>
                  <span>{new Date(item.scheduledAt).toLocaleString()}</span>
                  <span className={styles.badge}>{item.status}</span>
                  {user?.role !== 'patient' && item.status === 'pending' ? (
                    <div>
                      <Button variant="secondary" onClick={() => updateAppointment.mutate({ id: item.id, payload: { status: 'approved' } })}>Approve</Button>
                      <Button variant="ghost" onClick={() => updateAppointment.mutate({ id: item.id, payload: { status: 'rejected' } })}>Reject</Button>
                    </div>
                  ) : null}
                  {item.status === 'approved' ? (
                    <Link to={`/consultations/${item.id}`}>
                      <Button variant="secondary">Join video</Button>
                    </Link>
                  ) : null}
                  {user?.role === 'patient' && item.status === 'completed' && item.doctor ? (
                    <Button variant="secondary" onClick={() => setReviewAppointment(item)}>Rate practitioner</Button>
                  ) : null}
                </article>
              ))}
            </div>
          ) : (
            <div className={styles.emptyState}>
              <h3>{user?.role === 'patient' ? 'You have no appointments' : 'No appointments found'}</h3>
              <p>{user?.role === 'patient' ? 'Book an appointment when you are ready to speak with a practitioner.' : 'Appointments will appear here once they are created.'}</p>
            </div>
          )
        )}
      </Card>
      {showModal ? (
        <Modal title={bookingStep === 'details' ? 'Book appointment' : 'Payment'} onClose={() => { setShowModal(false); setBookingStep('details'); }}>
          {bookingStep === 'details' ? (
            <form className={`${styles.form} ${styles.bookingForm}`} onSubmit={handleSubmit(bookOrProceed)}>
              <div className={styles.sectionTitle}>
                <h3>Find the right practitioner</h3>
                <p>Filter by specialty, name, or patient rating before choosing a time.</p>
              </div>
              <div className={styles.formRow}>
                <TextField label="Search doctor" value={doctorSearch} onChange={(event) => setDoctorSearch(event.target.value)} placeholder="Name or specialty" />
                <SelectField label="Specialty" value={specialty} onChange={(event) => setSpecialty(event.target.value)}>
                  <option value="">All specialties</option>
                  {specialtyOptions.map((item) => <option key={item} value={item}>{item}</option>)}
                </SelectField>
                <SelectField label="Minimum rating" value={minRating} onChange={(event) => setMinRating(event.target.value)}>
                  <option value="">Any rating</option>
                  <option value="4">4+ stars</option>
                  <option value="3">3+ stars</option>
                </SelectField>
              </div>
              <div className={styles.doctorChooser}>
                <SelectField label="Doctor" error={errors.doctor_id?.message} {...register('doctor_id')}>
                  <option value="">Choose doctor</option>
                  {doctors.data?.data.map((doctor) => (
                    <option key={doctor.id} value={doctor.id}>
                      {doctor.name} - {doctor.specialty || 'General Medicine'} - {doctor.averageRating ? `${doctor.averageRating}/5` : 'New'}
                    </option>
                  ))}
                </SelectField>
                <p>{doctors.isLoading ? 'Loading practitioners...' : `${doctors.data?.data.length ?? 0} practitioners match your filters.`}</p>
              </div>
              {selectedDoctor ? <p className={styles.ratingLine}>Selected rating: {selectedDoctor.averageRating ?? 'New'} / 5 from {selectedDoctor.reviewsCount ?? 0} reviews</p> : null}
              <div className={styles.formRow}>
                <TextField label="Date and time" type="datetime-local" error={errors.scheduled_at?.message} {...register('scheduled_at')} />
                <TextField label="Reason" error={errors.reason?.message} {...register('reason')} />
              </div>
              <TextAreaField label="Notes" {...register('notes')} />
              <Button>{hasActiveCard ? 'Book appointment' : 'Get a medical card'}</Button>
            </form>
          ) : (
            <div className={styles.form}>
              <div className={styles.sectionTitle}>
                <h3>Payment method</h3>
                <p>Select how you would like to pay for this appointment.</p>
              </div>

              {hasActiveCard ? (
                <div className={styles.cardPaymentOption}>
                  <label className={styles.paymentRadio}>
                    <input type="radio" name="pay_method" checked={payByCard} onChange={() => setPayByCard(true)} />
                    <span className={styles.paymentRadioMark} />
                    <div>
                      <strong>Membership card</strong>
                      <p>Pay with your Vee-care card &middot; {myCard?.card?.cardNumber}</p>
                    </div>
                  </label>
                  <label className={styles.paymentRadio}>
                    <input type="radio" name="pay_method" checked={!payByCard} onChange={() => setPayByCard(false)} />
                    <span className={styles.paymentRadioMark} />
                    <div>
                      <strong>Credit / debit card</strong>
                      <p>Pay with a Mastercard, Visa, or other card</p>
                    </div>
                  </label>

                  {payByCard && myCard?.card ? (
                    <div style={{ display: 'grid', gap: '1rem', justifyItems: 'center', padding: '0.5rem 0' }}>
                      <VirtualCard card={myCard.card} />
                      <p style={{ color: 'var(--app-muted)', fontSize: '0.85rem', margin: 0, textAlign: 'center' }}>
                        Your membership card will be charged for this appointment.
                      </p>
                      <Button onClick={() => appointmentData && bookDirect(appointmentData)}>
                        Confirm with card
                      </Button>
                    </div>
                  ) : null}

                  {!payByCard ? (
                    <form onSubmit={handlePaymentSubmit(() => onPayDirect())}>
                      <div className={styles.formRow}>
                        <TextField label="Card number" placeholder="1234 5678 9012 3456" error={paymentErrors.cardNumber?.message} {...registerPayment('cardNumber')} />
                      </div>
                      <div className={styles.formRow}>
                        <TextField label="Cardholder name" placeholder="John Doe" error={paymentErrors.cardName?.message} {...registerPayment('cardName')} />
                      </div>
                      <div className={styles.formRow}>
                        <TextField label="Expiry (MM/YY)" placeholder="12/26" error={paymentErrors.expiry?.message} {...registerPayment('expiry')} />
                        <TextField label="CVV" placeholder="123" error={paymentErrors.cvv?.message} {...registerPayment('cvv')} />
                      </div>
                      <p style={{ color: 'var(--app-muted)', fontSize: '0.8rem', margin: '0.5rem 0' }}>
                        This is a demo &mdash; no real payment will be processed.
                      </p>
                      <Button>Pay & book appointment</Button>
                    </form>
                  ) : null}
                </div>
              ) : (
                <div className={styles.cardPaymentOption}>
                  <label className={styles.paymentRadio}>
                    <input type="radio" name="no_card_method" checked={paymentMode === 'request_card'} onChange={() => setPaymentMode('request_card')} />
                    <span className={styles.paymentRadioMark} />
                    <div>
                      <strong>Request a medical card</strong>
                      <p>Get a Vee-care membership card for faster bookings in the future</p>
                    </div>
                  </label>
                  <label className={styles.paymentRadio}>
                    <input type="radio" name="no_card_method" checked={paymentMode === 'pay_direct'} onChange={() => setPaymentMode('pay_direct')} />
                    <span className={styles.paymentRadioMark} />
                    <div>
                      <strong>Pay directly</strong>
                      <p>Pay for this appointment only without a membership card</p>
                    </div>
                  </label>

                  {paymentMode === 'request_card' ? (
                    <form onSubmit={handlePaymentSubmit((paymentData) => onRequestCard(paymentData))}>
                      <div style={{ display: 'grid', gap: '0.75rem' }}>
                        <p style={{ color: 'var(--app-muted)', fontSize: '0.85rem', margin: 0 }}>
                          Your membership card costs a one-time fee. Enter your payment details below.
                        </p>
                        <div className={styles.formRow}>
                          <TextField label="Card number" placeholder="1234 5678 9012 3456" error={paymentErrors.cardNumber?.message} {...registerPayment('cardNumber')} />
                        </div>
                        <div className={styles.formRow}>
                          <TextField label="Cardholder name" placeholder="John Doe" error={paymentErrors.cardName?.message} {...registerPayment('cardName')} />
                        </div>
                        <div className={styles.formRow}>
                          <TextField label="Expiry (MM/YY)" placeholder="12/26" error={paymentErrors.expiry?.message} {...registerPayment('expiry')} />
                          <TextField label="CVV" placeholder="123" error={paymentErrors.cvv?.message} {...registerPayment('cvv')} />
                        </div>
                        <p style={{ color: 'var(--app-muted)', fontSize: '0.8rem', margin: '0.5rem 0' }}>
                          This is a demo &mdash; no real payment will be processed.
                        </p>
                        <Button disabled={requestCardMutation.isPending}>
                          {requestCardMutation.isPending ? 'Processing...' : 'Pay & get card'}
                        </Button>
                      </div>
                    </form>
                  ) : (
                    <form onSubmit={handlePaymentSubmit(() => onPayDirect())}>
                      <div className={styles.formRow}>
                        <TextField label="Card number" placeholder="1234 5678 9012 3456" error={paymentErrors.cardNumber?.message} {...registerPayment('cardNumber')} />
                      </div>
                      <div className={styles.formRow}>
                        <TextField label="Cardholder name" placeholder="John Doe" error={paymentErrors.cardName?.message} {...registerPayment('cardName')} />
                      </div>
                      <div className={styles.formRow}>
                        <TextField label="Expiry (MM/YY)" placeholder="12/26" error={paymentErrors.expiry?.message} {...registerPayment('expiry')} />
                        <TextField label="CVV" placeholder="123" error={paymentErrors.cvv?.message} {...registerPayment('cvv')} />
                      </div>
                      <p style={{ color: 'var(--app-muted)', fontSize: '0.8rem', margin: '0.5rem 0' }}>
                        This is a demo &mdash; no real payment will be processed.
                      </p>
                      <Button>Pay & book appointment</Button>
                    </form>
                  )}
                </div>
              )}
            </div>
          )}
        </Modal>
      ) : null}
      {reviewAppointment?.doctor ? (
        <Modal title={`Rate ${reviewAppointment.doctor.name}`} onClose={() => setReviewAppointment(null)}>
          <form
            className={styles.form}
            onSubmit={(event) => {
              event.preventDefault();
              createReview.mutate({
                doctorId: reviewAppointment.doctor?.id as number,
                payload: {
                  appointment_id: reviewAppointment.id,
                  rating,
                  comment: reviewComment,
                },
              });
            }}
          >
            <div className={styles.starPicker} aria-label="Rating out of five">
              {[1, 2, 3, 4, 5].map((value) => (
                <button key={value} type="button" className={value <= rating ? styles.starActive : ''} onClick={() => setRating(value)}>
                  ★
                </button>
              ))}
            </div>
            <TextAreaField label="Comment" value={reviewComment} onChange={(event) => setReviewComment(event.target.value)} placeholder="How was the service?" />
            <Button disabled={createReview.isPending}>Submit rating</Button>
          </form>
        </Modal>
      ) : null}
    </div>
  );
}

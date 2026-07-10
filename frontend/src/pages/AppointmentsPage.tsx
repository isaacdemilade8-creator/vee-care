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

type BookingStep = 'initial' | 'new_patient_info' | 'link_physical' | 'details' | 'payment';

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
  const [bookingStep, setBookingStep] = useState<BookingStep>('initial');
  const [doctorSearch, setDoctorSearch] = useState('');
  const [specialty, setSpecialty] = useState('');
  const [minRating, setMinRating] = useState('');
  const [reviewAppointment, setReviewAppointment] = useState<Appointment | null>(null);
  const [rating, setRating] = useState(5);
  const [reviewComment, setReviewComment] = useState('');
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
  const [patientInfo, setPatientInfo] = useState({
    phone: '',
    date_of_birth: '',
    allergies: '',
    chronic_conditions: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
  });
  const [physicalCardNumber, setPhysicalCardNumber] = useState('');
  const [appointmentData, setAppointmentData] = useState<z.output<typeof appointmentSchema> | null>(null);
  const [payDirect, setPayDirect] = useState(false);
  const createAppointment = useApiMutation((payload: unknown) => endpoints.createAppointment(payload), ['appointments'], 'Appointment requested');
  const updateAppointment = useApiMutation(({ id, payload }: { id: number; payload: unknown }) => endpoints.updateAppointment(id, payload), ['appointments'], 'Appointment updated');
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
  const registerAndRequestCardMutation = useMutation({
    mutationFn: async (payload: Parameters<typeof endpoints.registerAndRequestCard>[0]) =>
      (await endpoints.registerAndRequestCard(payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-card'] });
      toast.success('Card issued successfully!');
      if (appointmentData) bookDirect(appointmentData);
    },
    onError: (error: unknown) => {
      const message = error instanceof Error ? error.message : 'Card request failed. Please try again.';
      toast.error(message);
    },
  });
  const linkPhysicalCardMutation = useMutation({
    mutationFn: async (payload: { physical_card_number: string }) =>
      (await endpoints.linkPhysicalCard(payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-card'] });
      toast.success('Physical card linked! Virtual card issued.');
      if (appointmentData) bookDirect(appointmentData);
    },
    onError: (error: unknown) => {
      const message = error instanceof Error ? error.message : 'Failed to link card. Please try again.';
      toast.error(message);
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

  const bookDirect = (data: z.output<typeof appointmentSchema>) => {
    createAppointment.mutate(data, {
      onSuccess: () => {
        setShowModal(false);
        setBookingStep('initial');
        setAppointmentData(null);
      },
    });
  };

  const handleBookSubmit = (data: z.output<typeof appointmentSchema>) => {
    if (hasActiveCard) {
      bookDirect(data);
    } else {
      setAppointmentData(data);
      setPayDirect(false);
      setBookingStep('initial');
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
          {user?.role === 'patient' ? <Button onClick={() => { setShowModal(true); setBookingStep('details'); }}>Book</Button> : null}
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
        <Modal
          title={
            bookingStep === 'initial' ? 'Welcome' :
            bookingStep === 'new_patient_info' ? 'Patient information' :
            bookingStep === 'link_physical' ? 'Link physical card' :
            bookingStep === 'details' ? 'Book appointment' :
            'Payment'
          }
          onClose={() => { setShowModal(false); setBookingStep('initial'); setPayDirect(false); }}
        >
          {bookingStep === 'initial' ? (
            <div className={styles.form}>
              <div className={styles.sectionTitle}>
                <h3>Have you been using this hospital/clinic physically before?</h3>
                <p>We'll set you up with a virtual card for faster service access.</p>
              </div>
              <div style={{ display: 'grid', gap: '0.75rem', paddingTop: '0.5rem' }}>
                <Button onClick={() => setBookingStep('link_physical')}>
                  Yes, I have a physical card
                </Button>
                <Button variant="secondary" onClick={() => setBookingStep('new_patient_info')}>
                  No, I'm new here
                </Button>
                <Button variant="ghost" onClick={() => { setPayDirect(true); setBookingStep('payment'); }}>
                  Pay directly (no card)
                </Button>
              </div>
            </div>
          ) : null}

          {bookingStep === 'new_patient_info' ? (
            <div className={styles.form}>
              <div className={styles.sectionTitle}>
                <h3>Tell us about yourself</h3>
                <p>We need some information to issue your membership card.</p>
              </div>
              <div className={styles.formRow}>
                <TextField label="Full name" value={user?.name ?? ''} disabled />
              </div>
              <div className={styles.formRow}>
                <TextField label="Email" value={user?.email ?? ''} disabled />
              </div>
              <div className={styles.formRow}>
                <TextField
                  label="Phone"
                  value={patientInfo.phone}
                  onChange={(event) => setPatientInfo({ ...patientInfo, phone: event.target.value })}
                  placeholder="+1 234 567 890"
                />
              </div>
              <div className={styles.formRow}>
                <TextField
                  label="Date of birth"
                  type="date"
                  value={patientInfo.date_of_birth}
                  onChange={(event) => setPatientInfo({ ...patientInfo, date_of_birth: event.target.value })}
                />
              </div>
              <div className={styles.formRow}>
                <TextAreaField
                  label="Allergies (one per line)"
                  value={patientInfo.allergies}
                  onChange={(event) => setPatientInfo({ ...patientInfo, allergies: event.target.value })}
                  placeholder="Peanuts&#10;Penicillin&#10;Latex"
                  style={{ minHeight: '5rem' }}
                />
              </div>
              <div className={styles.formRow}>
                <TextAreaField
                  label="Chronic conditions (one per line)"
                  value={patientInfo.chronic_conditions}
                  onChange={(event) => setPatientInfo({ ...patientInfo, chronic_conditions: event.target.value })}
                  placeholder="Diabetes&#10;Hypertension"
                  style={{ minHeight: '5rem' }}
                />
              </div>
              <div className={styles.formRow}>
                <TextField
                  label="Emergency contact name"
                  value={patientInfo.emergency_contact_name}
                  onChange={(event) => setPatientInfo({ ...patientInfo, emergency_contact_name: event.target.value })}
                  placeholder="Jane Doe"
                />
              </div>
              <div className={styles.formRow}>
                <TextField
                  label="Emergency contact phone"
                  value={patientInfo.emergency_contact_phone}
                  onChange={(event) => setPatientInfo({ ...patientInfo, emergency_contact_phone: event.target.value })}
                  placeholder="+1 234 567 891"
                />
              </div>
              <Button onClick={() => setBookingStep('payment')}>Done</Button>
            </div>
          ) : null}

          {bookingStep === 'link_physical' ? (
            <div className={styles.form}>
              <div className={styles.sectionTitle}>
                <h3>Enter your physical card details</h3>
                <p>Provide the card number from your hospital/clinic physical card to link it to your account.</p>
              </div>
              <div className={styles.formRow}>
                <TextField
                  label="Physical card number"
                  value={physicalCardNumber}
                  onChange={(event) => setPhysicalCardNumber(event.target.value)}
                  placeholder="e.g. PHY-1234-5678"
                />
              </div>
              <Button
                disabled={!physicalCardNumber || linkPhysicalCardMutation.isPending}
                onClick={() => {
                  linkPhysicalCardMutation.mutate({ physical_card_number: physicalCardNumber });
                }}
              >
                {linkPhysicalCardMutation.isPending ? 'Linking...' : 'Link card'}
              </Button>
            </div>
          ) : null}

          {bookingStep === 'details' ? (
            <form className={`${styles.form} ${styles.bookingForm}`} onSubmit={handleSubmit(handleBookSubmit)}>
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
              <Button>Book appointment</Button>
            </form>
          ) : null}

          {bookingStep === 'payment' ? (
            <div className={styles.form}>
              <div className={styles.sectionTitle}>
                <h3>{payDirect ? 'Payment' : 'Payment for membership card'}</h3>
                <p>{payDirect ? 'Pay for this appointment only.' : 'Your membership card costs a one-time fee. Enter your payment details below.'}</p>
              </div>
              <form onSubmit={handlePaymentSubmit((paymentData) => {
                if (payDirect) {
                  toast.success('Payment processed (demo)');
                  if (appointmentData) bookDirect(appointmentData);
                } else {
                  const allergiesList = patientInfo.allergies
                    ? patientInfo.allergies.split('\n').map((s) => s.trim()).filter(Boolean)
                    : undefined;
                  const chronicList = patientInfo.chronic_conditions
                    ? patientInfo.chronic_conditions.split('\n').map((s) => s.trim()).filter(Boolean)
                    : undefined;
                  const emergencyContact: Record<string, string> = {};
                  if (patientInfo.emergency_contact_name) emergencyContact.name = patientInfo.emergency_contact_name;
                  if (patientInfo.emergency_contact_phone) emergencyContact.phone = patientInfo.emergency_contact_phone;

                  registerAndRequestCardMutation.mutate({
                    ...paymentData,
                    phone: patientInfo.phone || undefined,
                    date_of_birth: patientInfo.date_of_birth || undefined,
                    allergies: allergiesList,
                    chronic_conditions: chronicList,
                    emergency_contact: Object.keys(emergencyContact).length ? emergencyContact : undefined,
                  });
                }
              })}>
                <div style={{ display: 'grid', gap: '0.75rem' }}>
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
                  <Button disabled={registerAndRequestCardMutation.isPending}>
                    {payDirect ? 'Pay & book appointment' : registerAndRequestCardMutation.isPending ? 'Processing...' : 'Pay & get card'}
                  </Button>
                </div>
              </form>
            </div>
          ) : null}
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

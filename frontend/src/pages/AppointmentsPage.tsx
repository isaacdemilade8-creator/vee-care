import { useMemo, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm, useWatch } from 'react-hook-form';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Link } from 'react-router-dom';
import { z } from 'zod';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SelectField, TextAreaField, TextField } from '../components/FormField';
import { Modal } from '../components/Modal';
import { SkeletonRows } from '../components/Skeleton';
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

export function AppointmentsPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [showModal, setShowModal] = useState(false);
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
  const { register, handleSubmit, control, formState: { errors } } = useForm<z.input<typeof appointmentSchema>, unknown, z.output<typeof appointmentSchema>>({
    resolver: zodResolver(appointmentSchema),
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
        <Modal title="Book appointment" onClose={() => setShowModal(false)}>
          <form className={`${styles.form} ${styles.bookingForm}`} onSubmit={handleSubmit((values) => createAppointment.mutate(values, { onSuccess: () => setShowModal(false) }))}>
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
            <Button disabled={createAppointment.isPending}>Submit request</Button>
          </form>
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

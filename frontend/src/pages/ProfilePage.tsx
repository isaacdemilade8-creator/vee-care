import { CalendarDays, Globe, Mail, MapPin, Phone, ShieldCheck, Star, Stethoscope, UserRound } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { useState } from 'react';
import { Button } from '../components/Button';
import { Card, StatCard } from '../components/Card';
import { TextAreaField } from '../components/FormField';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { useProfile, useProfileReviews } from '../hooks/useApi';
import { endpoints } from '../services/endpoints';
import styles from './ProfilePage.module.scss';

function formatRole(role?: string) {
  return role ? role.replace('_', ' ') : 'Member';
}

export function ProfilePage() {
  const { id } = useParams();
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const profileId = id === 'me' ? user?.id : Number(id);
  const profile = useProfile(profileId);
  const reviews = useProfileReviews(profileId);
  const [rating, setRating] = useState(5);
  const [reviewComment, setReviewComment] = useState('');
  const isMe = profileId === user?.id;
  const review = useMutation({
    mutationFn: () => endpoints.createProfileReview(profileId as number, { rating, comment: reviewComment }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['profile', profileId] }),
        queryClient.invalidateQueries({ queryKey: ['profile-reviews', profileId] }),
        queryClient.invalidateQueries({ queryKey: ['profiles'], exact: false }),
      ]);
      toast.success('Review submitted');
      setRating(5);
      setReviewComment('');
    },
  });
  if (profile.isLoading) {
    return <SkeletonRows rows={5} />;
  }

  if (!profile.data) {
    return (
      <Card>
        <p>Profile not found.</p>
      </Card>
    );
  }

  const person = profile.data;
  const isClinical = ['doctor', 'nurse', 'lab_technician', 'pharmacist'].includes(person.role);
  const messageTargetId = person.id ?? profileId;

  return (
    <div className={styles.page}>
      <Card className={styles.hero}>
        <div className={styles.avatar}>
          {person.avatarUrl ? <img src={person.avatarUrl} alt="" /> : <UserRound size={40} />}
        </div>
        <div className={styles.identity}>
          <span>{formatRole(person.role)}</span>
          <h2>{person.name}</h2>
          <p>{person.bio || person.specialty || (isClinical ? 'Clinical team member' : 'CareGrid member')}</p>
        </div>
        <div className={styles.actions}>
          <Link to="/profiles"><Button variant="secondary">Directory</Button></Link>
          {messageTargetId && messageTargetId !== user?.id ? (
            <Link to={`/chat/${messageTargetId}`}><Button>Message</Button></Link>
          ) : null}
          {isMe ? <Link to="/settings"><Button variant="secondary">Edit profile</Button></Link> : null}
        </div>
      </Card>

      <div className={styles.stats}>
        <StatCard label="Role" value={formatRole(person.role)} />
        <StatCard label="Reviews" value={person.reviewsCount ?? 0} />
        <StatCard label="Rating" value={person.averageRating ? `${person.averageRating}/5` : 'New'} />
      </div>

      <div className={styles.details}>
        <Card>
          <h3>Contact</h3>
          <ul>
            <li><Mail size={18} /><span>{person.email}</span></li>
            <li><Phone size={18} /><span>{person.phone || 'No phone listed'}</span></li>
            <li><MapPin size={18} /><span>{person.location || 'No location listed'}</span></li>
            <li><Globe size={18} /><span>{person.website || 'No website listed'}</span></li>
          </ul>
        </Card>
        <Card>
          <h3>Profile</h3>
          <ul>
            <li><ShieldCheck size={18} /><span>{formatRole(person.role)}</span></li>
            <li><Stethoscope size={18} /><span>{person.specialty || 'No specialty listed'}</span></li>
            <li><CalendarDays size={18} /><span>{person.createdAt ? new Date(person.createdAt).toLocaleDateString() : 'Active member'}</span></li>
          </ul>
        </Card>
      </div>

      {isClinical ? (
        <Card>
          <div className={styles.reviewHeader}>
            <div>
              <h3>Patient Reviews</h3>
              <p>{person.reviewsCount ?? 0} reviews · {person.averageRating ? `${person.averageRating}/5 average` : 'No rating yet'}</p>
            </div>
            <div className={styles.stars}>
              {[1, 2, 3, 4, 5].map((value) => (
                <Star key={value} size={18} fill={person.averageRating && value <= Math.round(person.averageRating) ? 'currentColor' : 'none'} />
              ))}
            </div>
          </div>
          {person.canReview && !isMe ? (
            <form
              className={styles.reviewForm}
              onSubmit={(event) => {
                event.preventDefault();
                review.mutate();
              }}
            >
              <div className={styles.starPicker}>
                {[1, 2, 3, 4, 5].map((value) => (
                  <button key={value} type="button" className={value <= rating ? styles.starActive : ''} onClick={() => setRating(value)}>
                    ★
                  </button>
                ))}
              </div>
              <TextAreaField label="Your review" value={reviewComment} onChange={(event) => setReviewComment(event.target.value)} placeholder="Share what other patients should know" />
              <Button disabled={review.isPending}>Submit review</Button>
            </form>
          ) : null}
          {reviews.isLoading ? <SkeletonRows rows={2} /> : (
            <div className={styles.reviewList}>
              {reviews.data?.data.map((item) => (
                <article key={item.id}>
                  <strong>{'★'.repeat(item.rating)}{'☆'.repeat(5 - item.rating)}</strong>
                  <p>{item.comment || 'No comment left.'}</p>
                  <span>{item.patient?.name || 'Patient'} · {new Date(item.createdAt).toLocaleDateString()}</span>
                </article>
              ))}
              {!reviews.data?.data.length ? <p>No patient reviews yet.</p> : null}
            </div>
          )}
        </Card>
      ) : null}

    </div>
  );
}

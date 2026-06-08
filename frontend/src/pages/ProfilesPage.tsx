import { Search, UserRound } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useState } from 'react';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useProfiles } from '../hooks/useApi';
import styles from './ProfilesPage.module.scss';

const roleOptions = [
  { label: 'All roles', value: '' },
  { label: 'Super Admin', value: 'super_admin' },
  { label: 'Admin', value: 'admin' },
  { label: 'Doctor', value: 'doctor' },
  { label: 'Nurse', value: 'nurse' },
  { label: 'Patient', value: 'patient' },
  { label: 'Lab Technician', value: 'lab_technician' },
  { label: 'Pharmacist', value: 'pharmacist' },
];

export function ProfilesPage() {
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const profiles = useProfiles({
    ...(search ? { search } : {}),
    ...(role ? { role } : {}),
  });

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <div>
          <p>People directory</p>
          <h2>User Profiles</h2>
        </div>
        <div className={styles.filters}>
          <label>
            <Search size={18} />
            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search people" />
          </label>
          <select value={role} onChange={(event) => setRole(event.target.value)}>
            {roleOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </div>
      </div>

      {profiles.isLoading ? <SkeletonRows rows={6} /> : (
        <div className={styles.grid}>
          {profiles.data?.data.map((profile) => (
            <Link key={profile.id} to={`/profiles/${profile.id}`} className={styles.profileCard}>
              <Card>
                <div className={styles.avatar}>
                  {profile.avatarUrl ? <img src={profile.avatarUrl} alt="" /> : <UserRound size={28} />}
                </div>
                <div>
                  <strong>{profile.name}</strong>
                  <span>{profile.role.replace('_', ' ')}</span>
                </div>
                {profile.averageRating ? (
                  <p className={styles.rating}>★ {profile.averageRating}/5 · {profile.reviewsCount ?? 0} reviews</p>
                ) : null}
                <p>{profile.bio || profile.specialty || profile.phone || 'vee-care member'}</p>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}

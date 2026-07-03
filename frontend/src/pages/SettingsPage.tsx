import type { FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { Activity, Edit3, LogOut, Moon, UserRound } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { useAppSettings } from '../context/AppSettingsContext';
import { useAuth } from '../context/AuthContext';
import { endpoints } from '../services/endpoints';
import styles from './SettingsPage.module.scss';

type EditableField = 'avatar' | 'name' | 'email' | 'phone' | 'specialty' | 'location' | 'website' | 'bio';

const fieldLabels: Record<EditableField, string> = {
  avatar: 'Profile photo',
  name: 'Name',
  email: 'Email',
  phone: 'Phone',
  specialty: 'Specialty',
  location: 'Location',
  website: 'Website',
  bio: 'Bio',
};

export function SettingsPage() {
  const { user, updateUser, logout } = useAuth();
  const { toggleDarkMode } = useAppSettings();
  const queryClient = useQueryClient();
  const [editingField, setEditingField] = useState<EditableField | null>(null);
  const updateProfile = useMutation({
    mutationFn: (payload: unknown) => endpoints.updateMyProfile(payload),
    onSuccess: async (response) => {
      updateUser(response.data);
      await queryClient.invalidateQueries({ queryKey: ['profile', user?.id] });
      await queryClient.invalidateQueries({ queryKey: ['profiles'] });
      setEditingField(null);
      toast.success('Profile updated');
    },
  });

  const currentValue = (field: EditableField) => {
    if (field === 'avatar') {
      return user?.avatarUrl ?? '';
    }

    return String(user?.[field] ?? '');
  };

  const submitField = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!editingField) {
      return;
    }

    const form = event.currentTarget;
    const formData = new FormData(form);
    const value = String(formData.get('value') ?? '');

    const save = (avatarUrl?: string) => updateProfile.mutate({
      [editingField === 'avatar' ? 'avatar_url' : editingField]: editingField === 'avatar' ? avatarUrl : value || null,
    });

    if (editingField === 'avatar') {
      const avatar = formData.get('value') as File | null;
      if (!avatar?.size) {
        setEditingField(null);
        return;
      }

      const upload = new FormData();
      upload.append('folder', 'avatars');
      upload.append('image', avatar);
      endpoints.uploadImage(upload).then((response) => save(response.data.url));
      return;
    }

    save();
  };

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <p>Workspace preferences</p>
        <h2>Settings</h2>
      </div>

      <div className={styles.grid}>
        <Card>
          <div className={styles.sectionTitle}>
            <UserRound />
            <div>
              <h3>Account</h3>
              <p>{user?.name}</p>
            </div>
          </div>
          <div className={styles.actions}>
            <Link to="/profiles/me"><Button variant="secondary">View public profile</Button></Link>
            <Button variant="ghost" onClick={logout}>
              <LogOut size={18} />
              Logout
            </Button>
          </div>
        </Card>

        <Card>
          <div className={styles.sectionTitle}>
            <Moon />
            <div>
              <h3>Appearance</h3>
              <p>Switch between light and dark mode.</p>
            </div>
          </div>
          <Button variant="secondary" onClick={toggleDarkMode}>Toggle dark mode</Button>
        </Card>

        <Card>
          <div className={styles.sectionTitle}>
            <Activity />
            <div>
              <h3>Activity Log</h3>
              <p>View your recent activity and audit trail.</p>
            </div>
          </div>
          <Link to="/activity-log"><Button variant="secondary">View activity log</Button></Link>
        </Card>
      </div>

      <Card>
        <div className={styles.sectionTitle}>
          <UserRound />
          <div>
            <h3>Public Profile</h3>
            <p>Update the details other users see on your profile.</p>
          </div>
        </div>
        <div className={styles.profileSummary}>
          <div className={styles.avatarPreview}>
            {user?.avatarUrl ? <img src={user.avatarUrl} alt="" /> : <UserRound size={32} />}
            <button type="button" onClick={() => setEditingField('avatar')}>
              <Edit3 size={14} />
              Edit
            </button>
          </div>
          <dl>
            {(['name', 'email', 'phone', 'specialty', 'location', 'website', 'bio'] as EditableField[]).map((field) => (
              <div key={field}>
                <dt>{fieldLabels[field]}</dt>
                <dd>{currentValue(field) || 'Not set'}</dd>
                <button type="button" onClick={() => setEditingField(field)}>
                  <Edit3 size={14} />
                  Edit
                </button>
              </div>
            ))}
          </dl>
        </div>
      </Card>

      {editingField ? (
        <div className={styles.modal} role="presentation" onMouseDown={() => setEditingField(null)}>
          <Card className={styles.editModal} onMouseDown={(event) => event.stopPropagation()}>
            <div className={styles.modalHeader}>
              <h3>Edit {fieldLabels[editingField]}</h3>
              <button type="button" onClick={() => setEditingField(null)}>Close</button>
            </div>
            <form className={styles.fieldForm} onSubmit={submitField}>
              {editingField === 'bio' ? (
                <textarea name="value" defaultValue={currentValue(editingField)} placeholder={fieldLabels[editingField]} />
              ) : editingField === 'avatar' ? (
                <input name="value" type="file" accept="image/*" />
              ) : (
                <input
                  name="value"
                  type={editingField === 'email' ? 'email' : editingField === 'website' ? 'url' : 'text'}
                  defaultValue={currentValue(editingField)}
                  placeholder={fieldLabels[editingField]}
                  required={editingField === 'name' || editingField === 'email'}
                />
              )}
              <Button disabled={updateProfile.isPending}>Save change</Button>
            </form>
          </Card>
        </div>
      ) : null}
    </div>
  );
}

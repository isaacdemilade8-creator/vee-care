import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { TextAreaField, TextField } from '../components/FormField';
import { Modal } from '../components/Modal';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { useApiMutation, useMedicalRecords } from '../hooks/useApi';
import { endpoints } from '../services/endpoints';
import styles from './TablePage.module.scss';

interface UploadForm {
  title: string;
  description?: string;
  file: FileList;
}

export function MedicalRecordsPage() {
  const { user } = useAuth();
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const records = useMedicalRecords(search ? { search } : undefined);
  const upload = useApiMutation((payload: FormData) => endpoints.uploadRecord(payload), ['medical-records'], 'Record uploaded');
  const { register, handleSubmit } = useForm<UploadForm>();
  const canUseCurrentUploadForm = user?.role === 'patient';

  const submit = (values: UploadForm) => {
    const data = new FormData();
    data.append('title', values.title);
    data.append('description', values.description ?? '');
    data.append('file', values.file[0]);
    upload.mutate(data, { onSuccess: () => setShowModal(false) });
  };

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <h2>Medical Records</h2>
        <div>
          <input placeholder="Search records" value={search} onChange={(event) => setSearch(event.target.value)} />
          {canUseCurrentUploadForm ? <Button onClick={() => setShowModal(true)}>Upload</Button> : null}
        </div>
      </div>
      <Card>
        {records.isLoading ? <SkeletonRows /> : (
          records.data?.data.length ? (
            <div className={styles.table}>
              {records.data.data.map((record) => (
                <article key={record.id}>
                  <strong>{record.title}</strong>
                  <span>{record.patient?.name}</span>
                  <span>{record.description}</span>
                  <a className={styles.badge} href={record.fileUrl} target="_blank" rel="noreferrer">Open file</a>
                </article>
              ))}
            </div>
          ) : (
            <div className={styles.emptyState}>
              <h3>{user?.role === 'patient' ? 'You have no medical records' : 'No records found'}</h3>
              <p>{user?.role === 'patient' ? 'Your uploaded files and care documents will appear here.' : 'Medical records will appear here once they are uploaded.'}</p>
            </div>
          )
        )}
      </Card>
      {showModal && canUseCurrentUploadForm ? (
        <Modal title="Upload record" onClose={() => setShowModal(false)}>
          <form className={styles.form} onSubmit={handleSubmit(submit)}>
            <TextField label="Title" {...register('title', { required: true })} />
            <TextAreaField label="Description" {...register('description')} />
            <TextField label="File" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" {...register('file', { required: true })} />
            <Button disabled={upload.isPending}>Upload</Button>
          </form>
        </Modal>
      ) : null}
    </div>
  );
}

import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { TextAreaField, TextField } from '../components/FormField';
import { Modal } from '../components/Modal';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { useApiMutation, useMedicalRecords, usePrescriptions } from '../hooks/useApi';
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
  const prescriptions = usePrescriptions(Boolean(user));
  const upload = useApiMutation((payload: FormData) => endpoints.uploadRecord(payload), ['medical-records'], 'Record uploaded');
  const { register, handleSubmit } = useForm<UploadForm>();
  const canUseCurrentUploadForm = user?.role === 'patient';
  const prescriptionRows = prescriptions.data?.data ?? [];
  const filteredPrescriptions = prescriptionRows.filter((prescription) => {
    const query = search.trim().toLowerCase();
    if (!query) return true;
    const haystack = `${prescription.medication} ${prescription.dosage} ${prescription.instructions} ${prescription.doctor?.name ?? ''}`.toLowerCase();
    return haystack.includes(query);
  });

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
          <input placeholder="Search records and prescriptions" value={search} onChange={(event) => setSearch(event.target.value)} />
          {canUseCurrentUploadForm ? <Button onClick={() => setShowModal(true)}>Upload</Button> : null}
        </div>
      </div>

      <Card>
        <div className={styles.sectionTitle}>
          <h3>Prescribed medicines</h3>
          <p>Medicines approved by the pharmacy after your doctor's request are stored here.</p>
        </div>
        {prescriptions.isLoading ? <SkeletonRows rows={3} /> : filteredPrescriptions.length ? (
          <div className={styles.table}>
            {filteredPrescriptions.map((prescription) => (
              <article key={prescription.id}>
                <strong>{prescription.medication}</strong>
                <span>{prescription.dosage}</span>
                <span>{prescription.instructions}</span>
                <span>Dr. {prescription.doctor?.name ?? 'Doctor'}</span>
                <span>{new Date(prescription.issuedAt).toLocaleDateString()}</span>
              </article>
            ))}
          </div>
        ) : (
          <div className={styles.emptyState}>
            <h3>No prescriptions yet</h3>
            <p>When your doctor sends a pharmacy request and medicines are marked available, they will appear here.</p>
          </div>
        )}
      </Card>

      <Card>
        <div className={styles.sectionTitle}>
          <h3>Uploaded files</h3>
        </div>
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
              <h3>{user?.role === 'patient' ? 'You have no uploaded medical files' : 'No files found'}</h3>
              <p>{user?.role === 'patient' ? 'Your uploaded files and care documents will appear here.' : 'Medical files will appear here once they are uploaded.'}</p>
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

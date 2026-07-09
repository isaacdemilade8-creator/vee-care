import { Activity, ClipboardList, FileUp, HeartPulse, MessageCircle, Search } from 'lucide-react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { Link } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card, StatCard } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';

import { useAuth } from '../context/AuthContext';
import { useMedicalRecords, useUrgentCareRequests } from '../hooks/useApi';
import { useEnterprisePatients, useEnterpriseVitals } from '../hooks/useEnterprise';
import { endpoints } from '../services/endpoints';
import type { PatientProfile, UrgentCareRequest, Vital } from '../types';
import styles from './TablePage.module.scss';

function formValues(form: HTMLFormElement) {
  return Object.fromEntries(new FormData(form)) as Record<string, string>;
}

export function NurseStationPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [selectedPatientId, setSelectedPatientId] = useState<number | undefined>();
  const patients = useEnterprisePatients(search);
  const vitals = useEnterpriseVitals(selectedPatientId, Boolean(selectedPatientId));
  const records = useMedicalRecords(undefined, true);
  const triage = useUrgentCareRequests();
  const patientRows = patients.data?.data ?? [];
  const selectedPatient = patientRows.find((patient) => patient.user?.id === selectedPatientId);
  const activeTriage = (triage.data?.data ?? []).filter((item) => !['resolved', 'cancelled'].includes(item.status));
  const patientOptions = useMemo(() => patientRows.map((patient) => ({
    label: patient.user?.name ?? patient.patientNumber,
    value: patient.user?.id ?? patient.id,
  })), [patientRows]);

  const recordVitals = useMutation({
    mutationFn: (payload: unknown) => endpoints.recordVitals(payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['enterprise-vitals'] }),
        queryClient.invalidateQueries({ queryKey: ['notifications'] }),
      ]);
      toast.success('Vitals recorded');
    },
  });

  const uploadRecord = useMutation({
    mutationFn: (payload: FormData) => endpoints.uploadRecord(payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['medical-records'] });
      toast.success('Care file uploaded');
    },
  });

  const updateTriage = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: unknown }) => endpoints.updateUrgentCareRequest(id, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['urgent-care-requests'] });
      toast.success('Triage updated');
    },
  });

  const submitVitals = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const values = formValues(event.currentTarget);

    recordVitals.mutate({
      patient_id: Number(values.patient_id || selectedPatientId),
      temperature: values.temperature || null,
      heart_rate: values.heart_rate ? Number(values.heart_rate) : null,
      blood_pressure: values.blood_pressure || null,
      weight: values.weight || null,
      height: values.height || null,
    }, {
      onSuccess: () => event.currentTarget.reset(),
    });
  };

  const submitRecord = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    formData.set('patient_id', String(formData.get('patient_id') || selectedPatientId || ''));

    uploadRecord.mutate(formData, {
      onSuccess: () => form.reset(),
    });
  };

  const takeTriage = (request: UrgentCareRequest) => updateTriage.mutate({
    id: request.id,
    payload: { status: 'in_progress', assigned_to: user?.id },
  });

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <div>
          <span>Nursing station</span>
          <h2>Patient Care Workbench</h2>
        </div>
        <div>
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search patients" />
          <Link to="/chat"><Button variant="secondary"><MessageCircle size={17} /> Team chat</Button></Link>
        </div>
      </div>

      <div className={styles.stats}>
        <StatCard label="Patients" value={patients.data?.meta?.total ?? 0} />
        <StatCard label="Active triage" value={activeTriage.length} />
        <StatCard label="Records" value={records.data?.meta?.total ?? 0} />
        <StatCard label="Recent vitals" value={vitals.data?.meta?.total ?? 0} />
      </div>

      <div className={styles.adminGrid}>
        <Card>
          <div className={styles.sectionTitle}>
            <span>Patient register</span>
            <h3>Select a patient</h3>
            <p>Choose a patient before recording vitals or uploading care files.</p>
          </div>
          {patients.isLoading ? <SkeletonRows /> : (
            <div className={styles.table}>
              {patientRows.map((patient: PatientProfile) => (
                <button
                  className={`${styles.tableAction} ${selectedPatientId === patient.user?.id ? styles.selectedRow : ''}`}
                  key={patient.id}
                  type="button"
                  onClick={() => setSelectedPatientId(patient.user?.id)}
                >
                  <strong>{patient.user?.name ?? 'Unnamed patient'}</strong>
                  <span>{patient.patientNumber}</span>
                  <span>Allergies: {patient.allergies.join(', ') || 'None listed'}</span>
                  <span>Conditions: {patient.chronicConditions.join(', ') || 'None listed'}</span>
                </button>
              ))}
              {!patientRows.length ? (
                <div className={styles.emptyState}>
                  <Search size={32} />
                  <h3>No patients found</h3>
                  <p>Try a different search term or register patients first.</p>
                </div>
              ) : null}
            </div>
          )}
        </Card>

        <div className={styles.page}>
          <Card>
            <div className={styles.sectionTitle}>
              <span>Vitals</span>
              <h3>{selectedPatient?.user?.name ?? 'Record patient vitals'}</h3>
              <p>Vitals are added to the patient timeline and the patient is notified.</p>
            </div>
            <form className={styles.form} onSubmit={submitVitals}>
              <div className={styles.formRow}>
                <select name="patient_id" value={selectedPatientId ?? ''} onChange={(event) => setSelectedPatientId(Number(event.target.value) || undefined)} required>
                  <option value="">Select patient</option>
                  {patientOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
                <input name="temperature" type="number" step="0.1" placeholder="Temperature, e.g. 37.1" />
                <input name="heart_rate" type="number" placeholder="Heart rate" />
              </div>
              <div className={styles.formRow}>
                <input name="blood_pressure" placeholder="Blood pressure, e.g. 120/80" />
                <input name="weight" type="number" step="0.1" placeholder="Weight kg" />
                <input name="height" type="number" step="0.1" placeholder="Height cm" />
              </div>
              <Button disabled={recordVitals.isPending || !selectedPatientId}><HeartPulse size={17} /> Save vitals</Button>
            </form>
          </Card>

          <Card>
            <div className={styles.sectionTitle}>
              <span>Care file</span>
              <h3>Upload nursing document</h3>
            </div>
            <form className={styles.form} onSubmit={submitRecord}>
              <div className={styles.formRow}>
                <select name="patient_id" defaultValue={selectedPatientId ?? ''} required>
                  <option value="">Select patient</option>
                  {patientOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
                <input name="title" placeholder="Record title" required />
                <input name="description" placeholder="Short description" />
              </div>
              <label className={styles.uploadBox}>
                <FileUp size={17} />
                <span>Attach PDF or image</span>
                <input name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required />
              </label>
              <Button disabled={uploadRecord.isPending}><FileUp size={17} /> Upload care file</Button>
            </form>
          </Card>

        </div>
      </div>

      <div className={styles.adminGrid}>
        <Card>
          <div className={styles.sectionTitle}>
            <span>Recent vitals</span>
            <h3>{selectedPatient ? `${selectedPatient.user?.name}'s vitals` : 'Select a patient to view vitals'}</h3>
          </div>
          <div className={styles.table}>
            {(vitals.data?.data ?? []).map((vital: Vital) => (
              <article key={vital.id}>
                <strong>{vital.patient?.name ?? selectedPatient?.user?.name ?? 'Patient'}</strong>
                <span>Temp: {vital.temperature ?? '-'} | HR: {vital.heartRate ?? '-'}</span>
                <span>BP: {vital.bloodPressure ?? '-'} | W/H: {vital.weight ?? '-'}kg / {vital.height ?? '-'}cm</span>
                <span>{vital.recordedAt ? new Date(vital.recordedAt).toLocaleString() : 'Just now'}</span>
              </article>
            ))}
            {selectedPatientId && !vitals.isLoading && !vitals.data?.data?.length ? <p className={styles.empty}>No vitals recorded yet.</p> : null}
          </div>
        </Card>

        <Card>
          <div className={styles.sectionTitle}>
            <span>Triage</span>
            <h3>Urgent care queue</h3>
          </div>
          <div className={styles.table}>
            {activeTriage.map((request) => (
              <article key={request.id}>
                <strong>{request.patient?.name ?? 'Patient'} - {request.severity}</strong>
                <span>{request.symptoms.join(', ')}</span>
                <span>Status: {request.status} | Channel: {request.preferredChannel}</span>
                <div>
                  <Button variant="secondary" onClick={() => takeTriage(request)} disabled={updateTriage.isPending}>
                    <Activity size={17} />
                    Take
                  </Button>
                  <Button onClick={() => updateTriage.mutate({ id: request.id, payload: { status: 'resolved', assigned_to: request.assignee?.id ?? user?.id } })} disabled={updateTriage.isPending}>
                    <ClipboardList size={17} />
                    Resolve
                  </Button>
                </div>
              </article>
            ))}
            {!activeTriage.length ? <p className={styles.empty}>No urgent triage requests waiting.</p> : null}
          </div>
        </Card>
      </div>

    </div>
  );
}

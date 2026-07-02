import { ClipboardPlus, Pill, Stethoscope } from 'lucide-react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { Link } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useEnterprisePatients, usePharmacyMedicines, usePharmacyRequests } from '../hooks/useEnterprise';
import { endpoints } from '../services/endpoints';
import type { PharmacyRequest, PharmacyRequestItem } from '../types';
import styles from './TablePage.module.scss';

type DrugRow = {
  medicineId: string;
  dosage: string;
  quantity: string;
  instructions: string;
};

const emptyDrugRow = (): DrugRow => ({
  medicineId: '',
  dosage: '',
  quantity: '1',
  instructions: '',
});

function availabilityLabel(status: PharmacyRequestItem['availabilityStatus']) {
  if (status === 'available') return 'Available';
  if (status === 'unavailable') return 'Unavailable';
  return 'Pending review';
}

function dispenseLabel(status: PharmacyRequestItem['dispenseStatus']) {
  if (status === 'given') return 'Given to patient';
  if (status === 'dispensed') return 'Ready for pickup';
  return 'Awaiting dispense';
}

function RequestItems({ items }: { items: PharmacyRequestItem[] }) {
  return (
    <div className={styles.itemList}>
      {items.map((item) => (
        <article key={item.id} className={styles.itemRow}>
          <div>
            <strong>{item.medicationName}</strong>
            <span>
              {item.dosage ? `${item.dosage} · ` : ''}
              Qty {item.quantity}
            </span>
            {item.instructions ? <p>{item.instructions}</p> : null}
            {item.pharmacistNote ? <p>Pharmacy note: {item.pharmacistNote}</p> : null}
          </div>
          <div className={styles.badgeGroup}>
            <span className={`${styles.badge} ${styles[`status-${item.availabilityStatus}`]}`}>
              {availabilityLabel(item.availabilityStatus)}
            </span>
            {item.availabilityStatus === 'available' ? (
              <span className={`${styles.badge} ${styles[`status-${item.dispenseStatus}`]}`}>
                {dispenseLabel(item.dispenseStatus)}
              </span>
            ) : null}
          </div>
        </article>
      ))}
    </div>
  );
}

function RequestCard({ request }: { request: PharmacyRequest }) {
  return (
    <article className={styles.requestCard}>
      <div className={styles.requestHeader}>
        <div>
          <strong>{request.patient?.name ?? 'Patient'}</strong>
          <span>{request.patient?.email}</span>
        </div>
        <span className={styles.badge}>{request.status === 'reviewed' ? 'Reviewed' : 'Awaiting pharmacy'}</span>
      </div>
      <p className={styles.clinicalNote}><strong>Clinical note:</strong> {request.clinicalNote}</p>
      <RequestItems items={request.items ?? []} />
      <span className={styles.requestMeta}>
        Sent {new Date(request.createdAt).toLocaleString()}
        {request.reviewedAt ? ` · Reviewed ${new Date(request.reviewedAt).toLocaleString()}` : ''}
      </span>
    </article>
  );
}

export function PharmacyRequestPage() {
  const queryClient = useQueryClient();
  const patients = useEnterprisePatients('', true);
  const medicines = usePharmacyMedicines();
  const requests = usePharmacyRequests('', true);
  const [patientId, setPatientId] = useState('');
  const [clinicalNote, setClinicalNote] = useState('');
  const [drugRows, setDrugRows] = useState<DrugRow[]>([emptyDrugRow()]);

  const patientOptions = useMemo(
    () => (patients.data?.data ?? []).map((profile) => ({
      value: String(profile.user?.id ?? ''),
      label: `${profile.user?.name ?? 'Patient'} (${profile.patientNumber})`,
    })).filter((option) => option.value),
    [patients.data?.data],
  );
  const medicineRows = medicines.data?.medicines?.data ?? [];
  const requestRows = requests.data?.data ?? [];

  const createRequest = useMutation({
    mutationFn: (payload: unknown) => endpoints.createPharmacyRequest(payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['pharmacy-requests'] });
      toast.success('Pharmacy request sent');
      setClinicalNote('');
      setDrugRows([emptyDrugRow()]);
    },
  });

  const updateDrugRow = (index: number, patch: Partial<DrugRow>) => {
    setDrugRows((rows) => rows.map((row, rowIndex) => (rowIndex === index ? { ...row, ...patch } : row)));
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();

    const items = drugRows
      .filter((row) => row.medicineId)
      .map((row) => ({
        medicine_id: Number(row.medicineId),
        dosage: row.dosage || null,
        quantity: Number(row.quantity || 1),
        instructions: row.instructions || null,
      }));

    if (!patientId || !clinicalNote.trim() || !items.length) {
      toast.error('Select a patient, add a clinical note, and choose at least one medicine.');
      return;
    }

    createRequest.mutate({
      patient_id: Number(patientId),
      clinical_note: clinicalNote.trim(),
      items,
    });
  };

  if (patients.isLoading || medicines.isLoading || requests.isLoading) {
    return <SkeletonRows rows={5} />;
  }

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <div>
          <span>Pharmacy workflow</span>
          <h2>Send pharmacy request</h2>
          <p>Request drugs for a patient. The pharmacist will mark availability, dispense, and notify you when ready.</p>
        </div>
        <div>
          <Link to="/dashboard"><Button variant="secondary">Back to dashboard</Button></Link>
        </div>
      </div>

      <Card>
        <div className={styles.sectionTitle}>
          <Stethoscope size={20} />
          <h3>New request</h3>
        </div>
        <form className={styles.form} onSubmit={submit}>
          <label>
            Patient
            <select value={patientId} onChange={(event) => setPatientId(event.target.value)} required>
              <option value="">Select patient</option>
              {patientOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          <label>
            Clinical note
            <textarea
              value={clinicalNote}
              onChange={(event) => setClinicalNote(event.target.value)}
              placeholder="Describe the patient's condition, diagnosis, and why these medicines are needed."
              required
            />
          </label>

          <div className={styles.sectionTitle}>
            <Pill size={18} />
            <h3>Requested medicines</h3>
          </div>

          {drugRows.map((row, index) => (
            <div className={styles.formRow} key={`drug-${index}`}>
              <select
                value={row.medicineId}
                onChange={(event) => updateDrugRow(index, { medicineId: event.target.value })}
                required={index === 0}
              >
                <option value="">Select medicine</option>
                {medicineRows.map((medicine) => (
                  <option key={medicine.id} value={medicine.id}>
                    {medicine.name}{medicine.strength ? ` (${medicine.strength})` : ''}
                  </option>
                ))}
              </select>
              <input
                value={row.dosage}
                onChange={(event) => updateDrugRow(index, { dosage: event.target.value })}
                placeholder="Dosage"
              />
              <input
                value={row.quantity}
                onChange={(event) => updateDrugRow(index, { quantity: event.target.value })}
                type="number"
                min="1"
                placeholder="Qty"
              />
              <input
                value={row.instructions}
                onChange={(event) => updateDrugRow(index, { instructions: event.target.value })}
                placeholder="Instructions"
              />
              {drugRows.length > 1 ? (
                <Button type="button" variant="secondary" onClick={() => setDrugRows((rows) => rows.filter((_, rowIndex) => rowIndex !== index))}>
                  Remove
                </Button>
              ) : null}
            </div>
          ))}

          <div className={styles.formActions}>
            <Button type="button" variant="secondary" onClick={() => setDrugRows((rows) => [...rows, emptyDrugRow()])}>
              <ClipboardPlus size={16} /> Add another drug
            </Button>
            <Button disabled={createRequest.isPending}>
              {createRequest.isPending ? 'Sending request...' : 'Send to pharmacy'}
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <div className={styles.sectionTitle}>
          <h3>Your pharmacy requests</h3>
          <p>Track pharmacist availability decisions for each drug.</p>
        </div>
        {requestRows.length ? requestRows.map((request) => <RequestCard key={request.id} request={request} />) : (
          <div className={styles.emptyState}>
            <h3>No pharmacy requests yet</h3>
            <p>Requests you send will appear here with per-drug availability once the pharmacist reviews them.</p>
          </div>
        )}
      </Card>
    </div>
  );
}

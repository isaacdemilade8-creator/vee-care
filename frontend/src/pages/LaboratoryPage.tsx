import { ClipboardPlus, FileUp, FlaskConical, MessageCircle, Search } from 'lucide-react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { Link } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card, StatCard } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { useEnterpriseLabTests, useEnterprisePatients } from '../hooks/useEnterprise';
import { endpoints } from '../services/endpoints';
import type { LabTest } from '../types';
import styles from './TablePage.module.scss';

function formValues(form: HTMLFormElement) {
  return Object.fromEntries(new FormData(form)) as Record<string, string>;
}

export function LaboratoryPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<LabTest | null>(null);
  const canRequest = ['doctor', 'nurse', 'admin', 'super_admin'].includes(user?.role ?? '');
  const tests = useEnterpriseLabTests(status ? { status } : {});
  const patients = useEnterprisePatients(search, canRequest);
  const testRows = tests.data?.data ?? [];
  const patientOptions = useMemo(() => (patients.data?.data ?? []).map((patient) => ({
    label: patient.user?.name ?? patient.patientNumber,
    value: patient.user?.id ?? patient.id,
  })), [patients.data?.data]);

  const createLab = useMutation({
    mutationFn: (payload: unknown) => endpoints.createLabTest(payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['enterprise-lab-tests'] }),
        queryClient.invalidateQueries({ queryKey: ['enterprise-ehr'] }),
        queryClient.invalidateQueries({ queryKey: ['enterprise-dashboard'] }),
      ]);
      toast.success('Lab request created');
    },
  });

  const updateLab = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: FormData | unknown }) => endpoints.updateLabResult(id, payload),
    onSuccess: async (_, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['enterprise-lab-tests'] }),
        queryClient.invalidateQueries({ queryKey: ['enterprise-ehr'] }),
        queryClient.invalidateQueries({ queryKey: ['enterprise-dashboard'] }),
      ]);
      toast.success('Lab updated');
      const updatedId = variables.id;
      setSelected((current) => (current?.id === updatedId ? null : current));
    },
  });

  const submitRequest = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const values = formValues(event.currentTarget);
    createLab.mutate({
      patient_id: Number(values.patient_id),
      name: values.name,
    }, {
      onSuccess: () => event.currentTarget.reset(),
    });
  };

  const submitResult = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selected) return;
    const form = event.currentTarget;
    const formData = new FormData(form);

    updateLab.mutate({ id: selected.id, payload: formData }, {
      onSuccess: () => form.reset(),
    });
  };

  const requested = testRows.filter((test) => test.status === 'requested').length;
  const processing = testRows.filter((test) => test.status === 'processing').length;
  const completed = testRows.filter((test) => test.status === 'completed').length;
  const flagged = testRows.filter((test) => test.status === 'flagged').length;

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <div>
          <span>Laboratory</span>
          <h2>Lab Workbench</h2>
        </div>
        <div>
          <select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Filter lab status">
            <option value="">All tests</option>
            <option value="requested">Requested</option>
            <option value="processing">Processing</option>
            <option value="completed">Completed</option>
            <option value="flagged">Flagged</option>
          </select>
          <Link to="/chat"><Button variant="secondary"><MessageCircle size={17} /> Team chat</Button></Link>
        </div>
      </div>

      <div className={styles.stats}>
        <StatCard label="Requested" value={requested} />
        <StatCard label="Processing" value={processing} />
        <StatCard label="Completed" value={completed} />
        <StatCard label="Flagged" value={flagged} />
      </div>

      <div className={styles.adminGrid}>
        <Card>
          <div className={styles.sectionTitle}>
            <span>Queue</span>
            <h3>Lab requests</h3>
            <p>Pick a request to process results, upload a report, or flag urgent findings.</p>
          </div>
          {tests.isLoading ? <SkeletonRows /> : (
            <div className={styles.table}>
              {testRows.map((test) => (
                <button
                  key={test.id}
                  type="button"
                  className={`${styles.tableAction} ${selected?.id === test.id ? styles.selectedRow : ''}`}
                  onClick={() => setSelected(test)}
                >
                  <strong>{test.name}</strong>
                  <span>{test.patient?.name ?? 'Patient not loaded'}</span>
                  <span className={styles.badge}>{test.status}</span>
                  <span>Requested by {test.requestedBy?.name ?? 'care team'}</span>
                </button>
              ))}
              {!testRows.length ? (
                <div className={styles.emptyState}>
                  <Search size={32} />
                  <h3>No lab requests found</h3>
                  <p>New lab requests will appear here once doctors or nurses create them.</p>
                </div>
              ) : null}
            </div>
          )}
        </Card>

        <div className={styles.page}>
          {canRequest ? (
            <Card>
              <div className={styles.sectionTitle}>
                <span>Request</span>
                <h3>Create lab request</h3>
              </div>
              <form className={styles.form} onSubmit={submitRequest}>
                <div className={styles.formRow}>
                  <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search patient list" />
                  <select name="patient_id" required>
                    <option value="">Select patient</option>
                    {patientOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                  </select>
                  <input name="name" placeholder="Test name, e.g. Full Blood Count" required />
                </div>
                <Button disabled={createLab.isPending}><ClipboardPlus size={17} /> Request lab test</Button>
              </form>
            </Card>
          ) : null}

          <Card>
            <div className={styles.sectionTitle}>
              <span>Result</span>
              <h3>{selected ? selected.name : 'Select a lab request'}</h3>
              <p>{selected ? `Patient: ${selected.patient?.name ?? 'Unknown patient'}` : 'Choose a request from the queue to update it.'}</p>
            </div>
            {selected ? (
              <form className={styles.form} onSubmit={submitResult}>
                <div className={styles.formRow}>
                  <select name="status" defaultValue={selected.status}>
                    <option value="requested">Requested</option>
                    <option value="processing">Processing</option>
                    <option value="completed">Completed</option>
                    <option value="flagged">Flagged</option>
                  </select>
                  <input name="result_summary" placeholder="Result summary" defaultValue={selected.resultSummary ?? ''} />
                </div>
                <label className={styles.uploadBox}>
                  <FileUp size={17} />
                  <span>Attach lab report</span>
                  <input name="report" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" />
                </label>
                <div className={styles.formActions}>
                  <Button disabled={updateLab.isPending}><FlaskConical size={17} /> Save result</Button>
                  {selected.reportUrl ? <a href={selected.reportUrl} target="_blank" rel="noreferrer">Open current report</a> : null}
                </div>
              </form>
            ) : null}
          </Card>
        </div>
      </div>
    </div>
  );
}

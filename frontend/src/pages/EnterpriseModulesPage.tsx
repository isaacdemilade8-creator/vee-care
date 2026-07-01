import { QRCodeCanvas } from 'qrcode.react';
import { CalendarDays, ClipboardList, FileText, FlaskConical, HeartPulse, Pill, Stethoscope } from 'lucide-react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { useSearchParams } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { useEnterpriseEhr, useEnterprisePatients, useEnterprisePharmacy, useEnterpriseStaff, usePharmacyOrders } from '../hooks/useEnterprise';
import { endpoints } from '../services/endpoints';
import type { Appointment, MedicalRecord, MedicineOrder, Prescription, UrgentCareRequest, User, Vital } from '../types';
import styles from './EnterpriseModulesPage.module.scss';

const modules = ['patients', 'ehr', 'staff', 'pharmacy', 'lab', 'ai'] as const;

export function EnterpriseModulesPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedModule = searchParams.get('module') as (typeof modules)[number] | null;
  const [selectedPatientId, setSelectedPatientId] = useState<number | undefined>();
  const [active, setActive] = useState<(typeof modules)[number]>(
    requestedModule && modules.includes(requestedModule) ? requestedModule : 'patients',
  );
  const canUsePatients = ['admin', 'doctor', 'nurse', 'super_admin'].includes(user?.role ?? '');
  const canUseStaff = ['admin', 'super_admin'].includes(user?.role ?? '');
  const canUseEhr = ['admin', 'doctor', 'nurse', 'lab_technician', 'super_admin'].includes(user?.role ?? '');
  const canUsePharmacy = ['admin', 'pharmacist', 'super_admin'].includes(user?.role ?? '');
  const patients = useEnterprisePatients('', (active === 'patients' && canUsePatients) || (active === 'ehr' && canUsePatients));
  const staff = useEnterpriseStaff('', active === 'staff' && canUseStaff);
  const ehr = useEnterpriseEhr((['ehr', 'lab', 'ai'].includes(active)) && canUseEhr, selectedPatientId);
  const pharmacy = useEnterprisePharmacy(active === 'pharmacy' && canUsePharmacy);
  const pharmacyOrders = usePharmacyOrders('', active === 'pharmacy' && canUsePharmacy);
  const updateOrder = useMutation({
    mutationFn: ({ id, status }: { id: number; status: MedicineOrder['status'] }) => endpoints.updateMedicineOrder(id, { status }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['pharmacy-orders'] }),
        queryClient.invalidateQueries({ queryKey: ['enterprise-pharmacy'] }),
        queryClient.invalidateQueries({ queryKey: ['enterprise-dashboard'] }),
      ]);
      toast.success('Order updated');
    },
  });
  const createNote = useMutation({
    mutationFn: (payload: unknown) => endpoints.createEhrEntry(payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['enterprise-ehr'] });
      toast.success('Clinical note saved');
    },
  });
  const recordVitals = useMutation({
    mutationFn: (payload: unknown) => endpoints.recordVitals(payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['enterprise-ehr'] });
      toast.success('Vitals recorded');
    },
  });
  const updateLab = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: unknown }) => endpoints.updateLabResult(id, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['enterprise-ehr'] });
      toast.success('Lab result updated');
    },
  });
  const allowedModules = modules.filter((module) => {
    if (module === 'patients') return canUsePatients;
    if (module === 'staff') return canUseStaff;
    if (module === 'ehr' || module === 'lab') return canUseEhr;
    if (module === 'pharmacy') return canUsePharmacy;
    if (module === 'ai') return ['admin', 'doctor'].includes(user?.role ?? '');
    return true;
  });
  const visibleActive = allowedModules.includes(active) ? active : allowedModules[0];

  useEffect(() => {
    if (requestedModule && modules.includes(requestedModule) && requestedModule !== active) {
      setActive(requestedModule);
    }
  }, [active, requestedModule]);

  const selectModule = (module: (typeof modules)[number]) => {
    setActive(module);
    setSearchParams({ module });
  };
  const patientRows = patients.data?.data ?? [];
  const selectedPatient = patientRows.find((patient) => patient.user?.id === selectedPatientId);
  const timeline = useMemo(() => {
    const noteItems = (ehr.data?.records ?? []).map((record: { id: number; title: string; type: string; body?: string; doctor?: User; createdAt?: string }) => ({
      id: `note-${record.id}`,
      title: record.title,
      subtitle: `${record.type} ${record.doctor?.name ? `by ${record.doctor.name}` : ''}`,
      body: record.body,
      time: record.createdAt,
      icon: Stethoscope,
    }));
    const vitalItems = (ehr.data?.vitals?.data ?? ehr.data?.vitals ?? []).map((vital: Vital) => ({
      id: `vital-${vital.id}`,
      title: 'Vitals recorded',
      subtitle: `Temp ${vital.temperature ?? '-'} | HR ${vital.heartRate ?? '-'} | BP ${vital.bloodPressure ?? '-'}`,
      body: `Weight ${vital.weight ?? '-'}kg | Height ${vital.height ?? '-'}cm`,
      time: vital.recordedAt ?? vital.createdAt,
      icon: HeartPulse,
    }));
    const labItems = (ehr.data?.labTests ?? []).map((test: { id: number; name: string; status: string; resultSummary?: string; createdAt?: string; updatedAt?: string }) => ({
      id: `lab-${test.id}`,
      title: test.name,
      subtitle: `Lab status: ${test.status}`,
      body: test.resultSummary,
      time: test.updatedAt ?? test.createdAt,
      icon: FlaskConical,
    }));
    const fileItems = (ehr.data?.medicalRecords?.data ?? ehr.data?.medicalRecords ?? []).map((record: MedicalRecord) => ({
      id: `file-${record.id}`,
      title: record.title,
      subtitle: `Uploaded by ${record.uploader?.name ?? 'care team'}`,
      body: record.description,
      href: record.fileUrl,
      time: record.createdAt,
      icon: FileText,
    }));
    const prescriptionItems = (ehr.data?.prescriptions?.data ?? ehr.data?.prescriptions ?? []).map((prescription: Prescription) => ({
      id: `rx-${prescription.id}`,
      title: prescription.medication,
      subtitle: `Prescription: ${prescription.dosage}`,
      body: prescription.instructions,
      time: prescription.issuedAt,
      icon: Pill,
    }));
    const appointmentItems = (ehr.data?.appointments?.data ?? ehr.data?.appointments ?? []).map((appointment: Appointment) => ({
      id: `appt-${appointment.id}`,
      title: appointment.reason,
      subtitle: `Appointment: ${appointment.status}`,
      body: appointment.doctor?.name ? `Doctor: ${appointment.doctor.name}` : appointment.notes,
      time: appointment.scheduledAt,
      icon: CalendarDays,
    }));
    const triageItems = (ehr.data?.triage?.data ?? ehr.data?.triage ?? []).map((request: UrgentCareRequest) => ({
      id: `triage-${request.id}`,
      title: `${request.severity} urgent care`,
      subtitle: `Status: ${request.status} | ${request.preferredChannel}`,
      body: request.symptoms.join(', '),
      time: request.createdAt,
      icon: ClipboardList,
    }));

    return [...noteItems, ...vitalItems, ...labItems, ...fileItems, ...prescriptionItems, ...appointmentItems, ...triageItems]
      .sort((a, b) => new Date(b.time ?? 0).getTime() - new Date(a.time ?? 0).getTime());
  }, [ehr.data]);

  return (
    <div className={styles.page}>
      <div className={styles.tabs}>
        {allowedModules.map((module) => (
          <button key={module} className={visibleActive === module ? styles.active : ''} onClick={() => selectModule(module)}>
            {module}
          </button>
        ))}
      </div>

      {visibleActive === 'patients' ? (
        <Card>
          <h2>Patient management</h2>
          {patients.isLoading ? <SkeletonRows /> : patients.data?.data.map((patient) => (
            <article className={styles.row} key={patient.id}>
              <div>
                <strong>{patient.user?.name}</strong>
                <span>{patient.patientNumber} - Allergies: {patient.allergies.join(', ') || 'None'}</span>
              </div>
              <QRCodeCanvas value={`vee-care://patient/${patient.patientNumber}`} size={72} />
            </article>
          ))}
        </Card>
      ) : null}

      {visibleActive === 'staff' ? (
        <Card>
          <h2>Staff and RBAC</h2>
          {staff.data?.data.map((member) => (
            <article className={styles.row} key={member.id}>
              <strong>{member.name}</strong>
              <span>{member.email} - {member.role}</span>
            </article>
          ))}
        </Card>
      ) : null}

      {visibleActive === 'ehr' ? (
        <div className={styles.ehrGrid}>
          <Card className={styles.patientPanel}>
            <div className={styles.panelHeader}>
              <span>Patient timeline</span>
              <h2>Electronic health records</h2>
              <p>Select a patient to review notes, vitals, labs, records, prescriptions, appointments, and triage history.</p>
            </div>
            <select value={selectedPatientId ?? ''} onChange={(event) => setSelectedPatientId(Number(event.target.value) || undefined)}>
              <option value="">All patients</option>
              {patientRows.map((patient) => (
                <option key={patient.id} value={patient.user?.id}>{patient.user?.name ?? patient.patientNumber}</option>
              ))}
            </select>
            {selectedPatient ? (
              <div className={styles.patientSummary}>
                <strong>{selectedPatient.user?.name}</strong>
                <span>{selectedPatient.patientNumber}</span>
                <span>Allergies: {selectedPatient.allergies.join(', ') || 'None listed'}</span>
                <span>Conditions: {selectedPatient.chronicConditions.join(', ') || 'None listed'}</span>
              </div>
            ) : null}
            <div className={styles.summaryGrid}>
              <article><strong>{ehr.data?.summary?.notes ?? 0}</strong><span>Notes</span></article>
              <article><strong>{ehr.data?.summary?.vitals ?? 0}</strong><span>Vitals</span></article>
              <article><strong>{ehr.data?.summary?.labs ?? 0}</strong><span>Labs</span></article>
              <article><strong>{ehr.data?.summary?.prescriptions ?? 0}</strong><span>Rx</span></article>
            </div>
          </Card>

          <Card className={styles.timelineCard}>
            <div className={styles.panelHeader}>
              <span>Clinical timeline</span>
              <h2>{selectedPatient?.user?.name ?? 'All patient activity'}</h2>
            </div>
            {ehr.isLoading ? <SkeletonRows /> : (
              <div className={styles.timeline}>
                {timeline.map((item) => (
                  <article key={item.id}>
                    <span className={styles.timelineIcon}>{(() => {
                      const Icon = item.icon;
                      return <Icon size={17} />;
                    })()}</span>
                    <div>
                      <strong>{item.title}</strong>
                      <span>{item.subtitle}</span>
                      {item.body ? <p>{item.body}</p> : null}
                      {item.href ? <a href={item.href} target="_blank" rel="noreferrer">Open record</a> : null}
                      <small>{item.time ? new Date(item.time).toLocaleString() : 'No date'}</small>
                    </div>
                  </article>
                ))}
                {!timeline.length ? <p className={styles.emptyState}>No EHR activity found for this selection.</p> : null}
              </div>
            )}
          </Card>

          <div className={styles.ehrActions}>
            {['doctor', 'admin', 'super_admin'].includes(user?.role ?? '') ? (
              <Card>
                <div className={styles.panelHeader}>
                  <span>Doctor note</span>
                  <h2>Add clinical note</h2>
                </div>
                <form className={styles.inlineForm} onSubmit={(event) => {
                  event.preventDefault();
                  const values = Object.fromEntries(new FormData(event.currentTarget));
                  createNote.mutate({
                    patient_id: Number(values.patient_id || selectedPatientId),
                    type: values.type,
                    title: values.title,
                    body: values.body,
                  }, { onSuccess: () => event.currentTarget.reset() });
                }}>
                  <select name="patient_id" defaultValue={selectedPatientId ?? ''} required>
                    <option value="">Select patient</option>
                    {patientRows.map((patient) => <option key={patient.id} value={patient.user?.id}>{patient.user?.name ?? patient.patientNumber}</option>)}
                  </select>
                  <select name="type" defaultValue="visit_note">
                    <option value="visit_note">Visit note</option>
                    <option value="diagnosis">Diagnosis</option>
                    <option value="treatment_plan">Treatment plan</option>
                  </select>
                  <input name="title" placeholder="Note title" required />
                  <textarea name="body" placeholder="Clinical note" required />
                  <Button disabled={createNote.isPending}>Save note</Button>
                </form>
              </Card>
            ) : null}

            {['doctor', 'nurse'].includes(user?.role ?? '') ? (
              <Card>
                <div className={styles.panelHeader}>
                  <span>Vitals</span>
                  <h2>Record vitals</h2>
                </div>
                <form className={styles.inlineForm} onSubmit={(event) => {
                  event.preventDefault();
                  const values = Object.fromEntries(new FormData(event.currentTarget));
                  recordVitals.mutate({
                    patient_id: Number(values.patient_id || selectedPatientId),
                    temperature: values.temperature || null,
                    heart_rate: values.heart_rate ? Number(values.heart_rate) : null,
                    blood_pressure: values.blood_pressure || null,
                    weight: values.weight || null,
                    height: values.height || null,
                  }, { onSuccess: () => event.currentTarget.reset() });
                }}>
                  <select name="patient_id" defaultValue={selectedPatientId ?? ''} required>
                    <option value="">Select patient</option>
                    {patientRows.map((patient) => <option key={patient.id} value={patient.user?.id}>{patient.user?.name ?? patient.patientNumber}</option>)}
                  </select>
                  <input name="temperature" type="number" step="0.1" placeholder="Temperature" />
                  <input name="heart_rate" type="number" placeholder="Heart rate" />
                  <input name="blood_pressure" placeholder="Blood pressure" />
                  <input name="weight" type="number" step="0.1" placeholder="Weight" />
                  <input name="height" type="number" step="0.1" placeholder="Height" />
                  <Button disabled={recordVitals.isPending}>Save vitals</Button>
                </form>
              </Card>
            ) : null}

            {['doctor', 'lab_technician'].includes(user?.role ?? '') ? (
              <Card>
                <div className={styles.panelHeader}>
                  <span>Lab result</span>
                  <h2>Update lab test</h2>
                </div>
                <form className={styles.inlineForm} onSubmit={(event) => {
                  event.preventDefault();
                  const values = Object.fromEntries(new FormData(event.currentTarget));
                  updateLab.mutate({
                    id: Number(values.lab_test_id),
                    payload: { status: values.status, result_summary: values.result_summary },
                  }, { onSuccess: () => event.currentTarget.reset() });
                }}>
                  <select name="lab_test_id" required>
                    <option value="">Select lab test</option>
                    {(ehr.data?.labTests ?? []).map((test: { id: number; name: string; status: string }) => <option key={test.id} value={test.id}>{test.name} - {test.status}</option>)}
                  </select>
                  <select name="status" defaultValue="processing">
                    <option value="processing">Processing</option>
                    <option value="completed">Completed</option>
                    <option value="flagged">Flagged</option>
                  </select>
                  <textarea name="result_summary" placeholder="Result summary" />
                  <Button disabled={updateLab.isPending}>Update lab</Button>
                </form>
              </Card>
            ) : null}
          </div>
        </div>
      ) : null}

      {visibleActive === 'pharmacy' ? (
        <Card>
          <h2>Pharmacy inventory</h2>
          {(pharmacy.data?.medicines?.data ?? []).map((medicine: { id: number; name: string; stock: number; reorder_level: number }) => (
            <article className={styles.row} key={medicine.id}>
              <strong>{medicine.name}</strong>
              <span>Stock: {medicine.stock} | Reorder: {medicine.reorder_level}</span>
            </article>
          ))}
        </Card>
      ) : null}

      {visibleActive === 'pharmacy' ? (
        <Card>
          <h2>Medicine pickup queue</h2>
          {(pharmacyOrders.data?.orders?.data ?? []).map((order: MedicineOrder) => (
            <article className={styles.row} key={order.id}>
              <div>
                <strong>{order.medicine?.name ?? 'Medicine order'} x {order.quantity}</strong>
                <span>{order.patient?.name ?? 'Patient'} | Pickup: {order.pickup_code}</span>
                {order.notes ? <p>{order.notes}</p> : null}
              </div>
              <div className={styles.orderActions}>
                <span className={styles.badge}>{order.status}</span>
                {order.status === 'pending' ? <Button variant="secondary" onClick={() => updateOrder.mutate({ id: order.id, status: 'preparing' })}>Prepare</Button> : null}
                {order.status === 'preparing' ? <Button onClick={() => updateOrder.mutate({ id: order.id, status: 'ready' })}>Mark ready</Button> : null}
                {order.status === 'ready' ? <Button onClick={() => updateOrder.mutate({ id: order.id, status: 'completed' })}>Picked up</Button> : null}
              </div>
            </article>
          ))}
          {!pharmacyOrders.data?.orders?.data?.length ? <p>No medicine pickup orders yet.</p> : null}
        </Card>
      ) : null}

      {visibleActive === 'lab' ? (
        <Card>
          <h2>Laboratory</h2>
          <p>Lab requests, result summaries, report attachments, and patient notifications are managed in the laboratory workbench.</p>
          <a href="/laboratory">Open laboratory workbench</a>
        </Card>
      ) : null}
      {visibleActive === 'ai' ? <Card><h2>AI clinical assistant</h2><p>AI service interfaces are wired on the backend for symptom checking, summaries, diagnosis support, and prescription drafting.</p></Card> : null}
    </div>
  );
}

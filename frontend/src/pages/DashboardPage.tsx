import {
  Activity,
  Boxes,
  CalendarClock,
  ClipboardList,
  FileText,
  FlaskConical,
  MessageCircle,
  PackageCheck,
  Pill,
  ShieldCheck,
  Stethoscope,
  Users,
  Video,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import type { ReactNode } from 'react';
import toast from 'react-hot-toast';
import { Button } from '../components/Button';
import { Card, StatCard } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { specialtyDepartmentOptions } from '../constants/specialties';
import { useAuth } from '../context/AuthContext';
import { useAdminAnalytics, useAppointments, useMedicalRecords } from '../hooks/useApi';
import { useEnterpriseDashboard, useEnterpriseEhr, useEnterprisePatients, useEnterprisePharmacy, usePharmacyRequests } from '../hooks/useEnterprise';
import { endpoints } from '../services/endpoints';
import type { Appointment, Role } from '../types';
import styles from './DashboardPage.module.scss';

function AppointmentList({ title, appointments }: { title: string; appointments: Appointment[] }) {
  return (
    <Card>
      <div className={styles.sectionTitle}>
        <CalendarClock />
        <h2>{title}</h2>
      </div>
      <div className={styles.list}>
        {appointments.slice(0, 5).map((appointment) => (
          <article key={appointment.id}>
            <strong>{appointment.reason}</strong>
            <span>{new Date(appointment.scheduledAt).toLocaleString()} - {appointment.status}</span>
          </article>
        ))}
        {!appointments.length ? <p className={styles.empty}>Nothing queued right now.</p> : null}
      </div>
    </Card>
  );
}

function QuickAction({ to, icon: Icon, label }: { to: string; icon: typeof Activity; label: string }) {
  return (
    <Link to={to}>
      <Card className={styles.quickAction}>
        <Icon />
        <span>{label}</span>
      </Card>
    </Link>
  );
}

function WorkflowCard({
  title,
  description,
  fields,
  button,
  onSubmit,
}: {
  title: string;
  description: string;
  fields: Array<{ name: string; label: string; type?: string; placeholder?: string; options?: Array<{ label: string; value: string | number }> }>;
  button: string;
  onSubmit: (values: Record<string, FormDataEntryValue>) => Promise<unknown>;
}) {
  return (
    <Card>
      <div className={styles.workflowHeader}>
        <h2>{title}</h2>
        <p>{description}</p>
      </div>
      <form
        className={styles.workflowForm}
        onSubmit={(event) => {
          event.preventDefault();
          const values = Object.fromEntries(new FormData(event.currentTarget));
          onSubmit(values)
            .then(() => {
              toast.success(`${title} complete`);
              event.currentTarget.reset();
            })
            .catch(() => undefined);
        }}
      >
        {fields.map((field) => (
          <label key={field.name}>
            <span>{field.label}</span>
            {field.options ? (
              <select name={field.name} required>
                {field.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </select>
            ) : (
              <input name={field.name} type={field.type ?? 'text'} placeholder={field.placeholder} required />
            )}
          </label>
        ))}
        <Button>{button}</Button>
      </form>
    </Card>
  );
}

export function DashboardPage() {
  const { user } = useAuth();
  const role = user?.role;
  const isSuperAdmin = role === 'super_admin';
  const needsEnterpriseStats = ['admin', 'lab_technician', 'pharmacist'].includes(role ?? '');
  const needsAppointments = ['patient', 'doctor', 'admin', 'super_admin'].includes(role ?? '');
  const needsRecords = ['patient', 'doctor', 'nurse', 'lab_technician', 'admin', 'super_admin'].includes(role ?? '');
  const needsPatients = ['admin', 'doctor', 'nurse'].includes(role ?? '');
  const needsEhr = ['doctor', 'lab_technician'].includes(role ?? '');
  const needsPharmacy = ['admin', 'pharmacist'].includes(role ?? '');
  const appointments = useAppointments(undefined, needsAppointments);
  const records = useMedicalRecords(undefined, needsRecords);
  const adminStats = useAdminAnalytics(isSuperAdmin);
  const enterprise = useEnterpriseDashboard(needsEnterpriseStats);
  const patients = useEnterprisePatients('', needsPatients);
  const ehr = useEnterpriseEhr(needsEhr);
  const pharmacy = useEnterprisePharmacy(needsPharmacy);
  const pharmacyRequests = usePharmacyRequests('', needsPharmacy);

  if ((needsAppointments && appointments.isLoading) || (needsEnterpriseStats && enterprise.isLoading)) {
    return <SkeletonRows rows={5} />;
  }

  const appointmentRows = appointments.data?.data ?? [];
  const enterpriseStats = enterprise.data?.stats;
  const patientOptions = (patients.data?.data ?? []).map((patient) => ({
    label: patient.user?.name ?? patient.patientNumber,
    value: patient.user?.id ?? patient.id,
  }));
  const labOptions = (ehr.data?.labTests ?? []).map((test: { id: number; name: string }) => ({ label: test.name, value: test.id }));
  const medicineOptions = (pharmacy.data?.medicines?.data ?? []).map((medicine: { id: number; name: string }) => ({ label: medicine.name, value: medicine.id }));

  const dashboards: Partial<Record<Role, ReactNode>> = {
    super_admin: (
      <div className={styles.stack}>
        <div className={styles.roleHero}>
          <ShieldCheck />
          <div>
            <p>Platform oversight</p>
            <h2>Monitor users, subscriptions, compliance signals, and platform health.</h2>
          </div>
          <Link to="/admin"><Button variant="secondary">Open admin panel</Button></Link>
        </div>
        <div className={styles.grid}>
          <StatCard label="Platform users" value={adminStats.data?.users.total ?? 0} />
          <StatCard label="Patients" value={adminStats.data?.users.patients ?? 0} />
          <StatCard label="Doctors" value={adminStats.data?.users.doctors ?? 0} />
          <StatCard label="Appointments" value={adminStats.data?.appointments.total ?? 0} />
          <StatCard label="Messages" value={adminStats.data?.messages ?? 0} />
          <StatCard label="Medical records" value={adminStats.data?.medicalRecords ?? 0} />
          <StatCard label="Prescriptions" value={adminStats.data?.prescriptions ?? 0} />
        </div>
        <div className={styles.actions}>
          <QuickAction to="/enterprise" icon={Activity} label="Operations analytics" />
          <QuickAction to="/admin" icon={Users} label="User governance" />
          <QuickAction to="/enterprise/modules?module=ehr" icon={FileText} label="Clinical records" />
        </div>
      </div>
    ),
    admin: (
      <div className={styles.stack}>
        <div className={styles.roleHero}>
          <Activity />
          <div>
            <p>Operations admin</p>
            <h2>Coordinate staff, queues, inventory, records, and patient flow.</h2>
          </div>
          <Link to="/enterprise/modules"><Button variant="secondary">Manage modules</Button></Link>
        </div>
        <div className={styles.grid}>
          <StatCard label="Patients" value={enterpriseStats?.patients ?? 0} />
          <StatCard label="Staff" value={enterpriseStats?.staff ?? 0} />
          <StatCard label="Appointments today" value={enterpriseStats?.appointmentsToday ?? 0} />
          <StatCard label="Pending labs" value={enterpriseStats?.pendingLabs ?? 0} />
        </div>
        <div className={styles.split}>
          <AppointmentList title="Operational queue" appointments={appointmentRows} />
          <WorkflowCard
            title="Register staff"
            description="Create a worker account on the shared platform. Admins cannot create other admin accounts."
            fields={[
              { name: 'name', label: 'Full name', placeholder: 'Dr. Ada Morgan' },
              { name: 'email', label: 'Staff email', type: 'email', placeholder: 'staff@example.com' },
              { name: 'password', label: 'Temporary password', type: 'password', placeholder: 'Minimum 8 characters' },
              { name: 'role', label: 'Role', options: [
                { label: 'Doctor', value: 'doctor' },
                { label: 'Nurse', value: 'nurse' },
                { label: 'Lab Technician', value: 'lab_technician' },
                { label: 'Pharmacist', value: 'pharmacist' },
              ] },
              { name: 'specialty', label: 'Specialty or department', options: specialtyDepartmentOptions },
            ]}
            button="Register worker"
            onSubmit={(values) => endpoints.registerStaff(values)}
          />
        </div>
      </div>
    ),
    doctor: (
      <div className={styles.stack}>
        <div className={styles.roleHero}>
          <Stethoscope />
          <div>
            <p>Clinical workspace</p>
            <h2>Review patients, complete consults, write notes, and issue prescriptions.</h2>
          </div>
          <Link to="/appointments"><Button variant="secondary">Review appointments</Button></Link>
        </div>
        <div className={styles.grid}>
          <StatCard label="Consults" value={appointments.data?.meta?.total ?? 0} />
          <StatCard label="Records" value={records.data?.meta?.total ?? 0} />
          <StatCard label="EHR entries" value={ehr.data?.records?.length ?? 0} />
          <StatCard label="Care messages" value="Open" />
        </div>
        <div className={styles.split}>
          <AppointmentList title="Clinical queue" appointments={appointmentRows} />
          <WorkflowCard
            title="Write clinical note"
            description="Create an encrypted EHR note attached to a patient."
            fields={[
              { name: 'patient_id', label: 'Patient', options: patientOptions },
              { name: 'type', label: 'Note type', options: [
                { label: 'Visit note', value: 'visit_note' },
                { label: 'Diagnosis', value: 'diagnosis' },
                { label: 'Treatment plan', value: 'treatment_plan' },
              ] },
              { name: 'title', label: 'Title', placeholder: 'Consultation summary' },
              { name: 'body', label: 'Clinical note', placeholder: 'Assessment and plan' },
            ]}
            button="Save EHR note"
            onSubmit={(values) => endpoints.createEhrEntry(values)}
          />
        </div>
        <div className={styles.actions}>
          <QuickAction to="/pharmacy/requests" icon={Pill} label="Send pharmacy request" />
          <QuickAction to="/enterprise/modules?module=ehr" icon={FileText} label="Patient EHR" />
        </div>
      </div>
    ),
    nurse: (
      <div className={styles.stack}>
        <div className={styles.roleHero}>
          <ClipboardList />
          <div>
            <p>Nursing station</p>
            <h2>Track triage, vitals, care tasks, and patient handoffs.</h2>
          </div>
          <Link to="/nurse/station"><Button variant="secondary">Open nurse station</Button></Link>
        </div>
        <div className={styles.grid}>
          <StatCard label="Patients in care" value={patients.data?.meta?.total ?? 0} />
          <StatCard label="Vitals due" value="6" />
          <StatCard label="Handoffs" value="3" />
          <StatCard label="Alerts" value="2" />
        </div>
        <div className={styles.actions}>
          <WorkflowCard
            title="Record vitals"
            description="Capture vitals directly into the patient timeline."
            fields={[
              { name: 'patient_id', label: 'Patient', options: patientOptions },
              { name: 'temperature', label: 'Temperature', type: 'number', placeholder: '37.1' },
              { name: 'heart_rate', label: 'Heart rate', type: 'number', placeholder: '78' },
              { name: 'blood_pressure', label: 'Blood pressure', placeholder: '120/80' },
            ]}
            button="Submit vitals"
            onSubmit={(values) => endpoints.recordVitals(values)}
          />
          <QuickAction to="/records" icon={FileText} label="Care notes" />
          <QuickAction to="/nurse/station" icon={ClipboardList} label="Nurse station" />
          <QuickAction to="/chat" icon={MessageCircle} label="Team messages" />
        </div>
      </div>
    ),
    patient: (
      <div className={styles.stack}>
        <div className={styles.roleHero}>
          <Video />
          <div>
            <p>Your care hub</p>
            <h2>Track appointments, records, care messages, and video consultations.</h2>
          </div>
          <Link to="/appointments"><Button variant="secondary">Book appointment</Button></Link>
        </div>
        <div className={styles.grid}>
          <StatCard label="Appointments" value={appointments.data?.meta?.total ?? 0} />
          <StatCard label="Medical records" value={records.data?.meta?.total ?? 0} />
          <StatCard label="Care plan" value="Active" />
          <StatCard label="Unread messages" value="Open" />
        </div>
        <div className={styles.actions}>
          <QuickAction to="/records" icon={FileText} label="View records" />
          <QuickAction to="/chat" icon={MessageCircle} label="Message doctor" />
          <WorkflowCard
            title="Emergency contact"
            description="Queue an urgent triage request for the care desk."
            fields={[
              { name: 'severity', label: 'Severity', options: [
                { label: 'Low', value: 'low' },
                { label: 'Moderate', value: 'moderate' },
                { label: 'High', value: 'high' },
                { label: 'Critical', value: 'critical' },
              ] },
              { name: 'preferred_channel', label: 'Preferred channel', options: [
                { label: 'Chat', value: 'chat' },
                { label: 'Video', value: 'video' },
                { label: 'Phone', value: 'phone' },
              ] },
              { name: 'symptoms', label: 'Symptoms', placeholder: 'Fever, dizziness, chest pain' },
              { name: 'message', label: 'Message', placeholder: 'I need urgent assistance' },
            ]}
            button="Queue triage"
            onSubmit={(values) => endpoints.emergencyRequest({
              ...values,
              symptoms: String(values.symptoms).split(',').map((symptom) => symptom.trim()).filter(Boolean),
            })}
          />
        </div>
      </div>
    ),
    lab_technician: (
      <div className={styles.stack}>
        <div className={styles.roleHero}>
          <FlaskConical />
          <div>
            <p>Laboratory workbench</p>
            <h2>Process test requests, upload reports, and notify care teams.</h2>
          </div>
        </div>
        <div className={styles.grid}>
          <StatCard label="Pending labs" value={enterpriseStats?.pendingLabs ?? 0} />
          <StatCard label="Reports today" value="4" />
          <StatCard label="Critical flags" value="1" />
          <StatCard label="Assigned tests" value={ehr.data?.labTests?.length ?? 0} />
        </div>
        <div className={styles.actions}>
          <WorkflowCard
            title="Update lab result"
            description="Move a lab request forward and notify the care team."
            fields={[
              { name: 'lab_test_id', label: 'Lab test', options: labOptions },
              { name: 'status', label: 'Status', options: [
                { label: 'Processing', value: 'processing' },
                { label: 'Completed', value: 'completed' },
                { label: 'Flagged', value: 'flagged' },
              ] },
              { name: 'result_summary', label: 'Result summary', placeholder: 'Summary of findings' },
            ]}
            button="Update result"
            onSubmit={(values) => endpoints.updateLabResult(Number(values.lab_test_id), { status: values.status, result_summary: values.result_summary })}
          />
          <QuickAction to="/laboratory" icon={FlaskConical} label="Lab workbench" />
          <QuickAction to="/records" icon={FileText} label="Upload reports" />
          <QuickAction to="/chat" icon={MessageCircle} label="Notify doctors" />
        </div>
      </div>
    ),
    pharmacist: (
      <div className={styles.stack}>
        <div className={styles.roleHero}>
          <PackageCheck />
          <div>
            <p>Pharmacy console</p>
            <h2>Fulfill prescriptions, monitor stock, and manage medicine alerts.</h2>
          </div>
          <Link to="/enterprise/modules"><Button variant="secondary">Inventory</Button></Link>
        </div>
        <div className={styles.grid}>
          <StatCard label="Low stock" value={enterpriseStats?.lowStock ?? 0} />
          <StatCard label="Inventory items" value={pharmacy.data?.medicines?.data?.length ?? 0} />
          <StatCard label="Review queue" value={pharmacyRequests.data?.data?.filter((request) => request.status === 'pending_review').length ?? 0} />
          <StatCard label="Drug alerts" value="2" />
        </div>
        <div className={styles.actions}>
          <QuickAction to="/pharmacy/inventory" icon={Boxes} label="Drug inventory" />
          <QuickAction to="/pharmacy/medicines/new" icon={PackageCheck} label="Add medicine" />
          <QuickAction to="/enterprise/modules?module=pharmacy" icon={Pill} label="Pharmacy requests" />
          <WorkflowCard
            title="Adjust stock"
            description="Record dispense, restock, expiry removal, or correction."
            fields={[
              { name: 'medicine_id', label: 'Medicine', options: medicineOptions },
              { name: 'delta', label: 'Quantity change', type: 'number', placeholder: '-1' },
              { name: 'reason', label: 'Reason', placeholder: 'Prescription fulfilled' },
            ]}
            button="Update stock"
            onSubmit={(values) => endpoints.adjustMedicineStock(Number(values.medicine_id), { delta: Number(values.delta), reason: values.reason })}
          />
          <QuickAction to="/chat" icon={MessageCircle} label="Clarify orders" />
        </div>
      </div>
    ),
  };

  return dashboards[role as Role] ?? dashboards.patient;
}

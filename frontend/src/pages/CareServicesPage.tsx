import {
  AlertTriangle,
  CalendarPlus,
  FileText,
  HeartPulse,
  MessageCircle,
  ShieldCheck,
  Sparkles,
  Stethoscope,
  UserRoundSearch,
  Video,
} from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { useApiMutation, useAppointments, useDoctors, useUrgentCareRequests } from '../hooks/useApi';
import { endpoints } from '../services/endpoints';
import styles from './CareServicesPage.module.scss';

const serviceTracks = [
  {
    title: '24/7 Care Chat',
    description: 'Message doctors and care teams for quick guidance, follow-up questions, and handoffs.',
    to: '/chat',
    icon: MessageCircle,
  },
  {
    title: 'Urgent Telehealth',
    description: 'Send an urgent request to the care desk and keep the message connected to your patient account.',
    to: '#urgent-request',
    icon: AlertTriangle,
  },
  {
    title: 'Specialist Consults',
    description: 'Find providers by specialty and book the next available consultation.',
    to: '/profiles',
    icon: UserRoundSearch,
  },
  {
    title: 'Video Visits',
    description: 'Join approved consultations from your appointment queue with secure in-app signaling.',
    to: '/appointments',
    icon: Video,
  },
  {
    title: 'Health Records',
    description: 'Upload and review visit documents, reports, and patient files from one clinical timeline.',
    to: '/records',
    icon: FileText,
  },
  {
    title: 'Care Community',
    description: 'Read clinician posts, follow providers, save updates, and share helpful health education.',
    to: '/blog',
    icon: HeartPulse,
  },
];

const nextWave = [
  'Eat a Balanced Diet',
  'Stay Hydrated',
  'Get Quality Sleep',
  'Exercise Regularly',
  'Reduce Screen and Sitting Time',
  'Schedule Health Check-ups',
];

export function CareServicesPage() {
  const { user } = useAuth();
  const appointments = useAppointments();
  const doctors = useDoctors();
  const urgentRequests = useUrgentCareRequests({ per_page: '3' });
  const [message, setMessage] = useState('');
  const [symptoms, setSymptoms] = useState('');
  const [severity, setSeverity] = useState('moderate');
  const [preferredChannel, setPreferredChannel] = useState('chat');
  const emergencyRequest = useApiMutation((payload: unknown) => endpoints.createUrgentCareRequest(payload), ['urgent-care-requests'], 'Triage request queued');

  const approvedAppointment = useMemo(
    () => appointments.data?.data.find((appointment) => appointment.status === 'approved'),
    [appointments.data?.data],
  );

  const specialties = useMemo(() => {
    const map = new Map<string, number>();
    doctors.data?.data.forEach((doctor) => {
      const specialty = doctor.specialty?.trim() || 'General Medicine';
      map.set(specialty, (map.get(specialty) ?? 0) + 1);
    });

    return Array.from(map.entries()).map(([name, count]) => ({ name, count }));
  }, [doctors.data?.data]);

  const latestUrgentRequest = urgentRequests.data?.data[0];
  const canSubmitTriage = user?.role === 'patient';

  const submitUrgentRequest = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const symptomList = symptoms
      .split(',')
      .map((symptom) => symptom.trim())
      .filter(Boolean);

    if (!symptomList.length) {
      return;
    }

    emergencyRequest.mutate({
      symptoms: symptomList,
      severity,
      preferred_channel: preferredChannel,
      message,
    }, {
      onSuccess: () => {
        setMessage('');
        setSymptoms('');
        setSeverity('moderate');
        setPreferredChannel('chat');
      },
    });
  };

  return (
    <div className={styles.page}>
      <section className={styles.hero}>
        <div>
          <p>{user?.role === 'patient' ? 'Patient care access' : 'Telehealth command center'}</p>
          <h2>Chat, book, consult, follow up, and keep care moving from one place.</h2>
        </div>
        <div className={styles.heroActions}>
          <Link to="/appointments"><Button><CalendarPlus size={18} /> Book visit</Button></Link>
          <Link to="/chat"><Button variant="secondary"><MessageCircle size={18} /> Start chat</Button></Link>
        </div>
      </section>

      <section className={styles.serviceGrid}>
        {serviceTracks.map(({ title, description, to, icon: Icon }) => (
          <a key={title} href={to} className={styles.serviceCard}>
            <Icon size={24} />
            <strong>{title}</strong>
            <span>{description}</span>
          </a>
        ))}
      </section>

      <section className={styles.split}>
        <Card>
          <div className={styles.sectionHeader}>
            <Stethoscope />
            <div>
              <h3>Available Specialties</h3>
              <p>Browse care categories already represented by your active provider directory.</p>
            </div>
          </div>
          {doctors.isLoading ? <SkeletonRows rows={3} /> : (
            <div className={styles.specialties}>
              {specialties.map((specialty) => (
                <Link key={specialty.name} to={`/profiles?specialty=${encodeURIComponent(specialty.name)}`}>
                  <strong>{specialty.name}</strong>
                  <span>{specialty.count} provider{specialty.count === 1 ? '' : 's'}</span>
                </Link>
              ))}
              {!specialties.length ? <p className={styles.empty}>No specialties are listed yet.</p> : null}
            </div>
          )}
        </Card>

        <Card>
          <div className={styles.sectionHeader}>
            <Video />
            <div>
              <h3>Next Video Visit</h3>
              <p>Approved consultations are ready for secure video rooms.</p>
            </div>
          </div>
          {appointments.isLoading ? <SkeletonRows rows={2} /> : approvedAppointment ? (
            <div className={styles.videoVisit}>
              <strong>{approvedAppointment.reason}</strong>
              <span>{new Date(approvedAppointment.scheduledAt).toLocaleString()}</span>
              <Link to={`/consultations/${approvedAppointment.id}`}>
                <Button variant="secondary"><Video size={18} /> Join room</Button>
              </Link>
            </div>
          ) : (
            <div className={styles.videoVisit}>
              <strong>No approved video visits</strong>
              <span>Book a visit and join once the care team approves it.</span>
              <Link to="/appointments"><Button variant="secondary">View appointments</Button></Link>
            </div>
          )}
        </Card>
      </section>

      <section className={styles.split}>
        <Card id="urgent-request">
          <div className={styles.sectionHeader}>
            <AlertTriangle />
            <div>
              <h3>{canSubmitTriage ? 'Urgent Request' : 'Urgent Triage Queue'}</h3>
              <p>{canSubmitTriage ? 'Share symptoms, urgency, and how the care team should contact you.' : 'Review the latest urgent care item available to your role.'}</p>
            </div>
          </div>
          {canSubmitTriage ? (
            <form className={styles.urgentForm} onSubmit={submitUrgentRequest}>
              <div className={styles.formGrid}>
                <label>
                  <span>Severity</span>
                  <select value={severity} onChange={(event) => setSeverity(event.target.value)}>
                    <option value="low">Low</option>
                    <option value="moderate">Moderate</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                  </select>
                </label>
                <label>
                  <span>Preferred channel</span>
                  <select value={preferredChannel} onChange={(event) => setPreferredChannel(event.target.value)}>
                    <option value="chat">Chat</option>
                    <option value="video">Video</option>
                    <option value="phone">Phone</option>
                  </select>
                </label>
              </div>
              <label>
                <span>Symptoms</span>
                <input value={symptoms} onChange={(event) => setSymptoms(event.target.value)} maxLength={300} placeholder="Chest pain, fever, dizziness" />
              </label>
              <label>
                <span>Context</span>
                <textarea value={message} onChange={(event) => setMessage(event.target.value)} maxLength={800} rows={4} placeholder="When did it start? What changed? Any medication taken?" />
              </label>
              <Button disabled={emergencyRequest.isPending || !symptoms.trim()}><ShieldCheck size={18} /> Queue triage</Button>
            </form>
          ) : null}
          {latestUrgentRequest ? (
            <div className={styles.triageStatus}>
              <strong>{latestUrgentRequest.queueName.replaceAll('-', ' ')}</strong>
              <span>{latestUrgentRequest.status} - {latestUrgentRequest.severity} priority</span>
              {latestUrgentRequest.assignee ? <span>Assigned to {latestUrgentRequest.assignee.name}</span> : <span>Care team assignment pending</span>}
            </div>
          ) : null}
        </Card>

        <Card>
          <div className={styles.sectionHeader}>
            <Sparkles />
            <div>
              <h3>Healthy tips</h3>
              <p>Achieving optimal health comes down to making small, consistent lifestyle changes. The most effective core habits include eating a balanced diet, staying hydrated, prioritizing quality sleep, and moving your body daily.</p>
            </div>
          </div>
          <ul className={styles.roadmap}>
            {nextWave.map((item) => <li key={item}>{item}</li>)}
          </ul>
        </Card>
      </section>
    </div>
  );
}

import { motion } from 'framer-motion';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import {
  Activity,
  ArrowRight,
  BadgeCheck,
  Bell,
  CalendarCheck,
  ChevronDown,
  ClipboardPlus,
  FileHeart,
  HeartPulse,
  LockKeyhole,
  MessageSquareText,
  Newspaper,
  Pill,
  ShieldCheck,
  Sparkles,
  Stethoscope,
  Video,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { Button } from '../components/Button';
import bedsImage from '../assets/beds.png';
import roomImage from '../assets/room.png';
import surgeryImage from '../assets/surgery.png';
import { endpoints } from '../services/endpoints';
import styles from './LandingPage.module.scss';

const fadeUp = {
  hidden: { opacity: 0, y: 24 },
  show: { opacity: 1, y: 0 },
};

const stagger = {
  hidden: {},
  show: { transition: { staggerChildren: 0.08 } },
};

const cardMotion = {
  hidden: { opacity: 0, y: 20, scale: 0.98 },
  show: { opacity: 1, y: 0, scale: 1 },
};

const features = [
  { icon: CalendarCheck, title: 'Smart scheduling', text: 'Appointment queues, approvals, reminders, and role-aware calendars.' },
  { icon: FileHeart, title: 'Unified EHR', text: 'Encrypted records, vitals, diagnoses, visit notes, labs, and uploads.' },
  { icon: Video, title: 'Telemedicine', text: 'Appointment-linked video rooms, in-call chat, and secure signaling.' },
  { icon: Pill, title: 'Pharmacy flow', text: 'Prescription fulfillment, stock alerts, inventory, and purchase history.' },
  { icon: MessageSquareText, title: 'Realtime care', text: 'Notifications, doctor-patient messaging, and operational alerts.' },
];

const plans = [
  { name: 'Starter', price: '$149', text: 'For small teams digitizing daily care.', points: ['5 staff seats', 'EHR + appointments', 'Secure messaging'] },
  { name: 'Growth', price: '$399', text: 'For multi-role teams and growing care services.', points: ['Unlimited patients', 'Lab + pharmacy', 'Care analytics'], featured: true },
  { name: 'Enterprise', price: 'Custom', text: 'For HMOs, networks, and large care teams.', points: ['Dedicated support', 'Advanced security', 'Custom integrations'] },
];

const carouselImages = [
  { src: bedsImage, label: 'Inpatient operations' },
  { src: roomImage, label: 'Modern consultation rooms' },
  { src: surgeryImage, label: 'Surgical care coordination' },
];

export function LandingPage() {
  const [activeSlide, setActiveSlide] = useState(0);
  const posts = useQuery({
    queryKey: ['landing-posts'],
    queryFn: async () => (await endpoints.posts({ per_page: '5' })).data,
  });
  const previewPosts = (posts.data?.data ?? []).slice(0, 5);

  useEffect(() => {
    const timer = window.setInterval(() => {
      setActiveSlide((current) => (current + 1) % carouselImages.length);
    }, 5200);

    return () => window.clearInterval(timer);
  }, []);

  return (
    <main className={styles.page}>
      <div className={styles.backgroundCarousel} aria-hidden="true">
        {carouselImages.map((image, index) => (
          <motion.img
            key={image.src}
            src={image.src}
            alt=""
            className={index === activeSlide ? styles.activeBackground : ''}
            initial={false}
            animate={{ opacity: index === activeSlide ? 1 : 0, scale: index === activeSlide ? 1.04 : 1 }}
            transition={{ duration: 1.2, ease: 'easeOut' }}
          />
        ))}
      </div>

      <div className={styles.ambient} aria-hidden="true">
        <motion.span animate={{ y: [0, -14, 0], x: [0, 8, 0] }} transition={{ duration: 6, repeat: Infinity, ease: 'easeInOut' }}>ICU ready</motion.span>
        <motion.span animate={{ y: [0, 12, 0], x: [0, -10, 0] }} transition={{ duration: 7, repeat: Infinity, ease: 'easeInOut', delay: 0.35 }}>Live vitals</motion.span>
        <motion.span animate={{ y: [0, -10, 0], x: [0, -6, 0] }} transition={{ duration: 6.5, repeat: Infinity, ease: 'easeInOut', delay: 0.8 }}>Secure EHR</motion.span>
      </div>

      <motion.nav className={styles.nav} initial={{ opacity: 0, y: -14 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.45 }}>
        <Link to="/" className={styles.brand}>
          <HeartPulse size={25} />
          <strong>vee-care</strong>
        </Link>
        <div className={styles.navLinks}>
          <a href="#features">Features</a>
          <a href="#pricing">Pricing</a>
          <a href="#faq">FAQ</a>
          <Link to="/blog">Blog</Link>
        </div>
        <div className={styles.navActions}>
          <Link to="/login">Login</Link>
          <Link to="/register">
            <Button>Register</Button>
          </Link>
        </div>
      </motion.nav>

      <section className={styles.hero}>
        <motion.div className={styles.copy} variants={stagger} initial="hidden" animate="show">
          <motion.span className={styles.eyebrow} variants={fadeUp}><Sparkles size={16} /> Shared healthcare operating system</motion.span>
          <motion.h1 variants={fadeUp}>vee-care</motion.h1>
          <motion.p variants={fadeUp}>Run appointments, EHR, telemedicine, pharmacy, lab, analytics, and patient communication from one secure HealthTech SaaS platform.</motion.p>
          <motion.div className={styles.ctaRow} variants={fadeUp}>
            <Link to="/register"><Button>Launch workspace <ArrowRight size={18} /></Button></Link>
            <Link to="/login" className={styles.secondaryCta}>View demo</Link>
          </motion.div>
          <motion.div className={styles.trust} variants={stagger}>
            <motion.span variants={cardMotion}><ShieldCheck size={17} /> Role protected</motion.span>
            <motion.span variants={cardMotion}><LockKeyhole size={17} /> Encrypted records</motion.span>
            <motion.span variants={cardMotion}><BadgeCheck size={17} /> RBAC ready</motion.span>
          </motion.div>
          <motion.div className={styles.carouselControls} variants={fadeUp} aria-label="Landing page background carousel">
            {carouselImages.map((image, index) => (
              <button
                key={image.label}
                aria-current={index === activeSlide}
                aria-label={`Show ${image.label}`}
                onClick={() => setActiveSlide(index)}
                type="button"
              >
                <span />
                <i />
                {image.label}
              </button>
            ))}
          </motion.div>
        </motion.div>

        <motion.div className={styles.panel} aria-label="Care operations preview" initial={{ opacity: 0, scale: 0.94, rotate: -1 }} animate={{ opacity: 1, scale: 1, rotate: 0 }} transition={{ duration: 0.7, delay: 0.12 }}>
          <div className={styles.panelHeader}>
            <div className={styles.pulse}><Activity size={34} /></div>
            <div>
              <span>Live care command</span>
              <strong>98.4%</strong>
              <p>Care flow uptime</p>
            </div>
          </div>
          <motion.div className={styles.vitals} variants={stagger} initial="hidden" animate="show">
            {[
              ['Appointments', '128', '24 pending'],
              ['Lab results', '37', '5 flagged'],
              ['Active visits', '42', '+18%'],
            ].map(([label, value, note]) => (
              <motion.article key={label} variants={cardMotion} whileHover={{ y: -5 }}>
                <span>{label}</span><strong>{value}</strong><small>{note}</small>
              </motion.article>
            ))}
          </motion.div>
          <motion.div className={styles.timeline} variants={stagger} initial="hidden" animate="show">
            {[
              [Bell, 'Emergency triage', 'Doctor assigned in 3 min'],
              [Stethoscope, 'Consult completed', 'Prescription sent to pharmacy'],
              [ClipboardPlus, 'Lab report uploaded', 'Patient notified instantly'],
            ].map(([Icon, title, text]) => (
              <motion.article key={title as string} variants={cardMotion} whileHover={{ x: 6 }}>
                <Icon size={17} /><div><strong>{title as string}</strong><span>{text as string}</span></div>
              </motion.article>
            ))}
          </motion.div>
        </motion.div>
      </section>

      <motion.section className={styles.stats} variants={stagger} initial="hidden" whileInView="show" viewport={{ once: true, amount: 0.25 }}>
        {[
          ['42k+', 'patient records organized'],
          ['8 roles', 'with strict permissions'],
          ['24/7', 'realtime care operations'],
          ['99.9%', 'cloud-ready architecture'],
        ].map(([value, label]) => (
          <motion.article key={label} variants={cardMotion} whileHover={{ y: -6, scale: 1.02 }}>
            <strong>{value}</strong>
            <span>{label}</span>
          </motion.article>
        ))}
      </motion.section>

      <section className={styles.features} id="features">
        <motion.div className={styles.sectionIntro} variants={fadeUp} initial="hidden" whileInView="show" viewport={{ once: true, amount: 0.3 }}>
          <span>Clinical-grade modules</span>
          <h2>Built for every team inside a modern care platform</h2>
        </motion.div>
        <motion.div className={styles.featureGrid} variants={stagger} initial="hidden" whileInView="show" viewport={{ once: true, amount: 0.2 }}>
          {features.map(({ icon: Icon, title, text }) => (
            <motion.article key={title} variants={cardMotion} whileHover={{ y: -8, scale: 1.015 }}>
              <Icon size={24} />
              <h3>{title}</h3>
              <p>{text}</p>
            </motion.article>
          ))}
        </motion.div>
      </section>

      <motion.section className={styles.workflow} initial={{ opacity: 0, y: 22 }} whileInView={{ opacity: 1, y: 0 }} viewport={{ once: true, amount: 0.25 }} transition={{ duration: 0.55 }}>
        <div>
          <span>From front desk to pharmacy</span>
          <h2>One connected care journey</h2>
          <p>vee-care keeps each role focused on its own workflow while the platform keeps records, alerts, and clinical operations synchronized behind the scenes.</p>
        </div>
        <motion.div className={styles.workflowSteps} variants={stagger} initial="hidden" whileInView="show" viewport={{ once: true, amount: 0.3 }}>
          {['Patient checked in', 'Vitals recorded', 'Doctor consult', 'Lab processed', 'Prescription fulfilled', 'Care follow-up'].map((step, index) => (
            <motion.article key={step} variants={cardMotion} whileHover={{ x: 8 }}>
              <strong>{String(index + 1).padStart(2, '0')}</strong>
              <span>{step}</span>
            </motion.article>
          ))}
        </motion.div>
      </motion.section>

      <section className={styles.blog} id="blog">
        <motion.div className={styles.sectionIntro} variants={fadeUp} initial="hidden" whileInView="show" viewport={{ once: true }}>
          <span><Newspaper size={17} /> Blog</span>
          <h2>Fresh care guidance from the Vee-care team</h2>
        </motion.div>
        <div className={styles.blogRail}>
          <motion.div className={styles.blogGrid} variants={stagger} initial="hidden" whileInView="show" viewport={{ once: true, amount: 0.2 }}>
            {previewPosts.map((post) => (
              <motion.article key={post.id} variants={cardMotion} whileHover={{ y: -8, scale: 1.015 }}>
                {post.imageUrl ? <img src={post.imageUrl} alt="" /> : <div className={styles.blogFallback}><Newspaper size={24} /></div>}
                <div>
                  <span>{new Date(post.createdAt).toLocaleDateString()}</span>
                  <h3>{post.title || 'Care update'}</h3>
                  <p>{post.body}</p>
                  <Link to="/blog">Read article <ArrowRight size={16} /></Link>
                </div>
              </motion.article>
            ))}
            {!posts.isLoading && !previewPosts.length ? (
              <motion.article variants={cardMotion}>
                <div className={styles.blogFallback}><Newspaper size={24} /></div>
                <div>
                  <span>Coming soon</span>
                  <h3>Care articles are being prepared</h3>
                  <p>Official health guides and product updates will show here once the admin publishes them.</p>
                  <Link to="/blog">Visit blog <ArrowRight size={16} /></Link>
                </div>
              </motion.article>
            ) : null}
          </motion.div>
        </div>
        <Link to="/blog" className={styles.blogCta}><Button>Open blog <ArrowRight size={18} /></Button></Link>
      </section>

      <section className={styles.pricing} id="pricing">
        <motion.div className={styles.sectionIntro} variants={fadeUp} initial="hidden" whileInView="show" viewport={{ once: true }}>
          <span>Plans</span>
          <h2>Scale from first clinic to full care network</h2>
        </motion.div>
        <motion.div className={styles.planGrid} variants={stagger} initial="hidden" whileInView="show" viewport={{ once: true, amount: 0.25 }}>
          {plans.map((plan) => (
            <motion.article key={plan.name} className={plan.featured ? styles.featuredPlan : ''} variants={cardMotion} whileHover={{ y: -8, scale: 1.02 }}>
              <h3>{plan.name}</h3>
              <strong>{plan.price}</strong>
              <p>{plan.text}</p>
              {plan.points.map((point) => <span key={point}><BadgeCheck size={16} /> {point}</span>)}
            </motion.article>
          ))}
        </motion.div>
      </section>

      <motion.section className={styles.testimonials} variants={stagger} initial="hidden" whileInView="show" viewport={{ once: true, amount: 0.25 }}>
        <motion.article variants={cardMotion} whileHover={{ y: -6 }}>
          <p>vee-care gave our admin team, doctors, nurses, and pharmacy one shared operational picture without slowing down clinical work.</p>
          <strong>Dr. Amara Okonkwo</strong>
          <span>Medical Director, Meridian Care</span>
        </motion.article>
        <motion.article variants={cardMotion} whileHover={{ y: -6 }}>
          <p>The role-based workflows are exactly what a busy care team needs. Everyone sees the work that belongs to them.</p>
          <strong>Elena Martins</strong>
          <span>Care Operations Lead</span>
        </motion.article>
      </motion.section>

      <section className={styles.faq} id="faq">
        <motion.div className={styles.sectionIntro} variants={fadeUp} initial="hidden" whileInView="show" viewport={{ once: true }}>
          <span>Questions</span>
          <h2>Designed for secure healthcare operations</h2>
        </motion.div>
        {[
          ['Can one platform serve many teams?', 'Yes. Users share one platform while permissions keep each workflow role-aware.'],
          ['Can patients use it on mobile?', 'Yes. The interface is responsive and installable as a PWA.'],
          ['Is telemedicine included?', 'Yes. Appointment-linked WebRTC rooms are already integrated.'],
        ].map(([question, answer]) => (
          <motion.details key={question} initial={{ opacity: 0, y: 12 }} whileInView={{ opacity: 1, y: 0 }} viewport={{ once: true }} whileHover={{ x: 4 }}>
            <summary>{question}<ChevronDown size={18} /></summary>
            <p>{answer}</p>
          </motion.details>
        ))}
      </section>

      <motion.section className={styles.contact} initial={{ opacity: 0, scale: 0.97 }} whileInView={{ opacity: 1, scale: 1 }} viewport={{ once: true, amount: 0.35 }} transition={{ duration: 0.5 }}>
        <div>
          <span>Ready for cleaner care operations?</span>
          <h2>Launch a polished healthcare workspace today.</h2>
        </div>
        <Link to="/register"><Button>Start vee-care <ArrowRight size={18} /></Button></Link>
      </motion.section>
    </main>
  );
}

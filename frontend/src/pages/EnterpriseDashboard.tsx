import { motion } from 'framer-motion';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Card, StatCard } from '../components/Card';
import { Button } from '../components/Button';
import { SkeletonRows } from '../components/Skeleton';
import { Link } from 'react-router-dom';
import { useEnterpriseDashboard } from '../hooks/useEnterprise';
import styles from './EnterpriseDashboard.module.scss';

const chartData = [
  { name: 'Mon', visits: 34 },
  { name: 'Tue', visits: 42 },
  { name: 'Wed', visits: 39 },
  { name: 'Thu', visits: 51 },
  { name: 'Fri', visits: 48 },
];

export function EnterpriseDashboard() {
  const dashboard = useEnterpriseDashboard();
  const stats = dashboard.data?.stats;

  if (dashboard.isLoading) {
    return <SkeletonRows rows={5} />;
  }

  return (
    <div className={styles.page}>
      <div className={styles.hero}>
        <motion.div initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }}>
          <p>Enterprise command center</p>
          <h2>Care operations, staffing, and clinical activity in one place</h2>
          <Link to="/enterprise/modules"><Button variant="secondary">Open modules</Button></Link>
        </motion.div>
      </div>
      <div className={styles.stats}>
        <StatCard label="Patients" value={stats?.patients ?? 0} />
        <StatCard label="Staff" value={stats?.staff ?? 0} />
        <StatCard label="Appointments today" value={stats?.appointmentsToday ?? 0} />
        <StatCard label="Low stock items" value={stats?.lowStock ?? 0} />
      </div>
      <div className={styles.grid}>
        <Card>
          <h3>Visit activity</h3>
          <div className={styles.chart}>
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="name" />
                <YAxis />
                <Tooltip />
                <Area type="monotone" dataKey="visits" stroke="#0f766e" fill="#99f6e4" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>
        <Card>
          <h3>Activity feed</h3>
          <div className={styles.feed}>
            {dashboard.data?.activity.map((item) => (
              <article key={item.time}>
                <strong>{item.label}</strong>
                <span>{new Date(item.time).toLocaleString()}</span>
              </article>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}

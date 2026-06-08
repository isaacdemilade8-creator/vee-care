import { BarChart3, Bookmark, Heart, MessageCircle, Repeat2, Share2 } from 'lucide-react';
import { BlogTabs } from '../components/BlogTabs';
import { Card, StatCard } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { usePosts } from '../hooks/useApi';
import styles from './BlogPage.module.scss';

export function BlogAnalyticsPage() {
  const { user } = useAuth();
  const posts = usePosts(user?.id ? { user_id: String(user.id), per_page: '50' } : undefined);
  const rows = posts.data?.data ?? [];
  const totals = rows.reduce((acc, post) => ({
    likes: acc.likes + post.counts.likes,
    comments: acc.comments + post.counts.comments,
    saves: acc.saves + post.counts.saves,
    reposts: acc.reposts + post.counts.reposts,
    shares: acc.shares + post.shareCount,
  }), { likes: 0, comments: 0, saves: 0, reposts: 0, shares: 0 });
  const topPosts = [...rows].sort((a, b) => (
    b.counts.likes + b.counts.comments + b.counts.saves + b.counts.reposts + b.shareCount
  ) - (
    a.counts.likes + a.counts.comments + a.counts.saves + a.counts.reposts + a.shareCount
  )).slice(0, 5);

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.brand}><span>V</span> Vee-care Journal</div>
        <BlogTabs />
      </header>

      <section className={styles.writeHero}>
        <p>Post analytics</p>
        <h1>Understand how your health content is performing.</h1>
      </section>

      {posts.isLoading ? <SkeletonRows rows={5} /> : (
        <>
          <section className={styles.analyticsGrid}>
            <StatCard label="Posts" value={rows.length} />
            <StatCard label="Likes" value={totals.likes} />
            <StatCard label="Comments" value={totals.comments} />
            <StatCard label="Shares" value={totals.shares} />
          </section>
          <Card className={styles.analyticsPanel}>
            <div className={styles.sectionHeading}>
              <h2>Engagement mix</h2>
              <span><BarChart3 size={17} /> {totals.likes + totals.comments + totals.saves + totals.reposts + totals.shares} total actions</span>
            </div>
            <div className={styles.metricRows}>
              <span><Heart size={17} /> Likes <strong>{totals.likes}</strong></span>
              <span><MessageCircle size={17} /> Comments <strong>{totals.comments}</strong></span>
              <span><Bookmark size={17} /> Saves <strong>{totals.saves}</strong></span>
              <span><Repeat2 size={17} /> Reposts <strong>{totals.reposts}</strong></span>
              <span><Share2 size={17} /> Shares <strong>{totals.shares}</strong></span>
            </div>
          </Card>
          <Card className={styles.analyticsPanel}>
            <div className={styles.sectionHeading}>
              <h2>Top posts</h2>
              <span>{topPosts.length} ranked</span>
            </div>
            <div className={styles.topPosts}>
              {topPosts.map((post) => (
                <article key={post.id}>
                  <strong>{post.title || post.body}</strong>
                  <span>{post.counts.likes} likes · {post.counts.comments} comments · {post.shareCount} shares</span>
                </article>
              ))}
              {!topPosts.length ? <p>No posts yet.</p> : null}
            </div>
          </Card>
        </>
      )}
    </div>
  );
}

import { motion } from 'framer-motion';
import { ArrowRight, HeartPulse, MessageCircle, Search, Send } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { usePosts } from '../hooks/useApi';
import { endpoints } from '../services/endpoints';
import type { Post } from '../types';
import styles from './BlogPage.module.scss';

function formatDate(value: string) {
  return new Date(value).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
}

function readingTime(post: Post) {
  const words = `${post.title ?? ''} ${post.body}`.trim().split(/\s+/).filter(Boolean).length;
  return `${Math.max(1, Math.ceil(words / 180))} min read`;
}

function excerpt(body: string, length = 210) {
  return body.length > length ? `${body.slice(0, length).trim()}...` : body;
}

function ArticleImage({ post }: { post: Post }) {
  if (post.imageUrl) {
    return <img src={post.imageUrl} alt="" />;
  }

  return (
    <div className={styles.imageFallback}>
      <span>Vee-care Journal</span>
    </div>
  );
}

function CommentArea({ post }: { post: Post }) {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const [comment, setComment] = useState('');
  const commentPost = useMutation({
    mutationFn: () => endpoints.commentPost(post.id, { body: comment }),
    onSuccess: async () => {
      setComment('');
      toast.success('Comment posted');
      await queryClient.invalidateQueries({ queryKey: ['posts'] });
    },
  });

  return (
    <div className={styles.commentBlock}>
      <div className={styles.commentHeader}>
        <MessageCircle size={17} />
        <span>{post.counts.comments} comments</span>
      </div>
      {post.comments?.length ? (
        <div className={styles.comments}>
          {post.comments.map((item) => (
            <article key={item.id}>
              <span>{item.author.name}</span>
              <p>{item.body}</p>
            </article>
          ))}
        </div>
      ) : null}
      {user ? (
        <form
          className={styles.commentForm}
          onSubmit={(event) => {
            event.preventDefault();
            if (comment.trim()) {
              commentPost.mutate();
            }
          }}
        >
          <input value={comment} onChange={(event) => setComment(event.target.value)} placeholder="Write a comment" />
          <Button aria-label="Comment" disabled={commentPost.isPending || !comment.trim()}><Send size={16} /></Button>
        </form>
      ) : (
        <div className={styles.commentPrompt}>
          <span>Join the discussion</span>
          <Link to="/login">Login to comment</Link>
        </div>
      )}
    </div>
  );
}

function ArticleCard({ post, featured = false }: { post: Post; featured?: boolean }) {
  return (
    <motion.article
      layout
      className={featured ? styles.featuredArticle : styles.articleCard}
      initial={{ opacity: 0, y: 18 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25 }}
    >
      <ArticleImage post={post} />
      <div className={styles.articleBody}>
        <div className={styles.meta}>
          <span>{formatDate(post.createdAt)}</span>
          <span>{readingTime(post)}</span>
        </div>
        <h2>{post.title || 'Care update'}</h2>
        <p>{featured ? post.body : excerpt(post.body)}</p>
        <div className={styles.articleFooter}>
          <span>By {post.author.name}</span>
        </div>
        <CommentArea post={post} />
      </div>
    </motion.article>
  );
}

export function BlogPage() {
  const [search, setSearch] = useState('');
  const posts = usePosts({ per_page: '24' });
  const postRows = useMemo(() => posts.data?.data ?? [], [posts.data?.data]);
  const visiblePosts = useMemo(() => {
    const query = search.trim().toLowerCase();

    return postRows.filter((post) => {
      const haystack = `${post.title ?? ''} ${post.body} ${post.author.name}`.toLowerCase();
      return !query || haystack.includes(query);
    });
  }, [postRows, search]);
  const featuredPost = visiblePosts[0];
  const latestPosts = visiblePosts.slice(featuredPost ? 1 : 0);

  return (
    <main className={styles.page}>
      <motion.nav className={styles.nav} initial={{ opacity: 0, y: -14 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.45 }}>
        <Link to="/" className={styles.brand}>
          <HeartPulse size={25} />
          <strong>vee-care</strong>
        </Link>
        <div className={styles.navLinks}>
          <Link to="/#features">Features</Link>
          <Link to="/#pricing">Pricing</Link>
          <Link to="/#faq">FAQ</Link>
          <Link to="/blog">Blog</Link>
        </div>
        <div className={styles.navActions}>
          <Link to="/login">Login</Link>
          <Link to="/register"><Button>Register</Button></Link>
        </div>
      </motion.nav>

      <section className={styles.blogHero}>
        <motion.span initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }}>Vee-care Journal</motion.span>
        <motion.h1 initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.06 }}>
          Practical health notes and product updates for modern care teams.
        </motion.h1>
        <motion.label className={styles.searchBox} initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.12 }}>
          <Search size={18} />
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search articles" />
        </motion.label>
      </section>

      {posts.isLoading ? <SkeletonRows rows={5} /> : (
        <section className={styles.contentShell}>
          {featuredPost ? <ArticleCard post={featuredPost} featured /> : (
            <Card className={styles.emptyState}>
              <h2>No posts yet</h2>
              <p>Admin-published health articles will appear here.</p>
            </Card>
          )}

          {latestPosts.length ? (
            <div className={styles.latestHeader}>
              <h2>Latest articles</h2>
              <span>{latestPosts.length} posts</span>
            </div>
          ) : null}

          <motion.div layout className={styles.articleGrid}>
            {latestPosts.map((post) => <ArticleCard key={post.id} post={post} />)}
          </motion.div>

          {visiblePosts.length === 0 && postRows.length ? (
            <Card className={styles.emptyState}>
              <h2>No matching articles</h2>
              <p>Try a different keyword.</p>
            </Card>
          ) : null}
        </section>
      )}

      <Link to="/" className={styles.backHome}>Back to home <ArrowRight size={16} /></Link>
    </main>
  );
}

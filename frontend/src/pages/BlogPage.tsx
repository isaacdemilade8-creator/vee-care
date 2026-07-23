import { motion } from 'framer-motion';
import { ArrowRight, ChevronDown, HeartPulse, MessageCircle, Search, Send } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { DataStatePanel } from '../components/DataStatePanel';
import { useAuth } from '../context/AuthContext';
import { usePosts } from '../hooks/useApi';
import { endpoints } from '../services/endpoints';
import type { Post } from '../types';
import { excerpt, formatDateLong as formatDate, readingTime } from '../utils/format';
import styles from './BlogPage.module.scss';

function articleAlt(post: Post) {
  return post.title?.trim() || 'Vee-care Journal article image';
}

function ArticleImage({ post }: { post: Post }) {
  if (post.imageUrl) {
    return <img src={post.imageUrl} alt={articleAlt(post)} />;
  }

  return (
    <div className={styles.imageFallback}>
      <span>Vee-care Journal</span>
    </div>
  );
}

function CommentArea({ post, defaultOpen = false }: { post: Post; defaultOpen?: boolean }) {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const [open, setOpen] = useState(defaultOpen);
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
      <button
        type="button"
        className={styles.commentToggle}
        aria-expanded={open ? 'true' : 'false'}
        onClick={() => setOpen((value) => !value)}
      >
        <span className={styles.commentHeader}>
          <MessageCircle size={17} />
          <span>{post.counts.comments} comments</span>
        </span>
        <ChevronDown size={17} className={open ? styles.commentChevronOpen : styles.commentChevron} />
      </button>
      {open ? (
        <>
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
              <input
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                placeholder="Write a comment"
                aria-label="Write a comment"
              />
              <Button aria-label="Post comment" disabled={commentPost.isPending || !comment.trim()}>
                {commentPost.isPending ? 'Posting…' : <Send size={16} />}
              </Button>
            </form>
          ) : (
            <div className={styles.commentPrompt}>
              <span>Join the discussion</span>
              <Link to="/login">Login to comment</Link>
            </div>
          )}
        </>
      ) : null}
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
          <span>{readingTime(`${post.title ?? ''} ${post.body}`)}</span>
        </div>
        <h2>{post.title || 'Care update'}</h2>
        <p>{featured ? excerpt(post.body, 420) : excerpt(post.body)}</p>
        <div className={styles.articleFooter}>
          <span>By {post.author.name}</span>
        </div>
        <CommentArea post={post} defaultOpen={featured} />
      </div>
    </motion.article>
  );
}

export function BlogPage() {
  const [search, setSearch] = useState('');
  const searchQuery = search.trim();
  const postFilters = useMemo(
    () => ({ per_page: '24', ...(searchQuery ? { search: searchQuery } : {}) }),
    [searchQuery],
  );
  const posts = usePosts(postFilters);
  const postRows = useMemo(() => posts.data?.data ?? [], [posts.data?.data]);
  const featuredPost = postRows[0];
  const latestPosts = postRows.slice(featuredPost ? 1 : 0);
  const hasSearch = Boolean(searchQuery);

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

      {posts.isLoading ? <SkeletonRows rows={5} /> : posts.isError ? (
        <DataStatePanel title="Could not load articles" description="Check your connection and try again." action={<Button onClick={() => posts.refetch()}>Retry</Button>} />
      ) : (
        <section className={styles.contentShell}>
          {postRows.length === 0 ? (
            hasSearch ? (
              <Card className={styles.emptyState}>
                <h2>No matching articles</h2>
                <p>Try a different keyword.</p>
              </Card>
            ) : (
              <Card className={styles.emptyState}>
                <h2>No posts yet</h2>
                <p>Admin-published health articles will appear here.</p>
              </Card>
            )
          ) : (
            <>
              {featuredPost ? <ArticleCard post={featuredPost} featured /> : null}

              {latestPosts.length ? (
                <div className={styles.latestHeader}>
                  <h2>Latest articles</h2>
                  <span>{latestPosts.length} posts</span>
                </div>
              ) : null}

              <motion.div layout className={styles.articleGrid}>
                {latestPosts.map((post) => <ArticleCard key={post.id} post={post} />)}
              </motion.div>
            </>
          )}
        </section>
      )}

      <Link to="/" className={styles.backHome}>Back to home <ArrowRight size={16} /></Link>
    </main>
  );
}

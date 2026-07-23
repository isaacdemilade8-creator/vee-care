import { ImagePlus, Send } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { useAuth } from '../context/AuthContext';
import { usePublishPost } from '../hooks/usePublishPost';
import styles from './BlogPage.module.scss';

export function CreateBlogPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [preview, setPreview] = useState('');
  const createPost = usePublishPost(() => navigate('/blog'));

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <Link to="/blog" className={styles.brand}><span>V</span> Vee-care Blog</Link>
        <nav className={styles.publicNav}>
          <Link to="/blog">Blog</Link>
          <Link to="/admin">Admin</Link>
        </nav>
      </header>

      <section className={styles.writeHero}>
        <p>Create blog</p>
        <h1>Publish an official care update, patient guide, or practical health note.</h1>
      </section>

      <Card className={styles.writerCard}>
        <form
          className={styles.writer}
          onSubmit={(event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const formData = new FormData(form);
            const values = Object.fromEntries(formData) as Record<string, string>;
            const image = formData.get('image') as File | null;

            createPost.mutate(
              { title: values.title, body: values.body, image },
              { onSuccess: () => { form.reset(); setPreview(''); } },
            );
          }}
        >
          <div className={styles.writerMeta}>
            <span>{user?.name}</span>
            <span>{user?.role.replace('_', ' ')}</span>
          </div>
          <input name="title" placeholder="Title" maxLength={120} />
          <textarea name="body" placeholder="Write your post..." required />
          <label className={styles.uploadBox}>
            <ImagePlus size={20} />
            <span>{preview || 'Add a cover image'}</span>
            <input
              name="image"
              type="file"
              accept="image/*"
              onChange={(event) => setPreview(event.target.files?.[0]?.name ?? '')}
            />
          </label>
          <Button disabled={createPost.isPending}><Send size={17} /> Publish blog</Button>
        </form>
      </Card>
    </div>
  );
}

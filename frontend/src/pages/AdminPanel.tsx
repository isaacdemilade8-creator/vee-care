import { useState } from 'react';
import type { FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import type { Variants } from 'framer-motion';
import toast from 'react-hot-toast';
import { Link, useSearchParams } from 'react-router-dom';
import { Edit3, ImagePlus, MessageCircle, Newspaper, Send, Trash2, UserPlus } from 'lucide-react';
import { Button } from '../components/Button';
import { Card, StatCard } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { TextField, SelectField } from '../components/FormField';
import { specialtyDepartmentOptions } from '../constants/specialties';
import { useAuth } from '../context/AuthContext';
import { useAdminAnalytics, useAdminUsers, usePosts } from '../hooks/useApi';
import { endpoints } from '../services/endpoints';
import type { Post } from '../types';
import styles from './TablePage.module.scss';

const platformRoles = [
  { label: 'Patient', value: 'patient' },
  { label: 'Doctor', value: 'doctor' },
  { label: 'Nurse', value: 'nurse' },
  { label: 'Lab Technician', value: 'lab_technician' },
  { label: 'Pharmacist', value: 'pharmacist' },
  { label: 'Admin', value: 'admin' },
  { label: 'Super Admin', value: 'super_admin' },
];

const adminManagedRoles = platformRoles.filter((role) => !['super_admin', 'admin'].includes(role.value));

const pageMotion: Variants = {
  hidden: { opacity: 0 },
  show: {
    opacity: 1,
    transition: { staggerChildren: 0.06 },
  },
};

const itemMotion: Variants = {
  hidden: { opacity: 0, y: 14 },
  show: { opacity: 1, y: 0, transition: { duration: 0.3, ease: 'easeOut' } },
};

function formValues(form: HTMLFormElement) {
  return Object.fromEntries(new FormData(form)) as Record<string, string>;
}

function formatDate(value: string) {
  return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function excerpt(value: string, length = 130) {
  return value.length > length ? `${value.slice(0, length).trim()}...` : value;
}

export function AdminPanel() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const activeTab = searchParams.get('tab') === 'campaign' ? 'campaign' : 'users';
  const [search, setSearch] = useState('');
  const [selectedRole, setSelectedRole] = useState('doctor');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [postSearch, setPostSearch] = useState('');
  const [preview, setPreview] = useState('');
  const [editingPost, setEditingPost] = useState<Post | null>(null);
  const [selectedPostId, setSelectedPostId] = useState<number | null>(null);
  const isSuperAdmin = user?.role === 'super_admin';
  const analytics = useAdminAnalytics();
  const users = useAdminUsers(search ? { search } : undefined);
  const posts = usePosts({ per_page: '50' });
  const roles = isSuperAdmin ? platformRoles : adminManagedRoles;
  const visiblePosts = (posts.data?.data ?? []).filter((post) => {
    const query = postSearch.trim().toLowerCase();
    return !query || `${post.title ?? ''} ${post.body} ${post.author.name}`.toLowerCase().includes(query);
  });
  const selectedPost = visiblePosts.find((post) => post.id === selectedPostId) ?? visiblePosts[0];

  const createUser = useMutation({
    mutationFn: (payload: unknown) => endpoints.createAdminUser(payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['admin-users'] }),
        queryClient.invalidateQueries({ queryKey: ['admin-analytics'] }),
      ]);
      toast.success('User registered');
    },
  });

  const deleteUser = useMutation({
    mutationFn: (targetUserId: number) => endpoints.deleteAdminUser(targetUserId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['admin-users'] }),
        queryClient.invalidateQueries({ queryKey: ['admin-analytics'] }),
      ]);
      toast.success('User deleted');
    },
  });

  const createPost = useMutation({
    mutationFn: (payload: unknown) => endpoints.createPost(payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['posts'] });
      toast.success('Campaign post published');
    },
  });

  const updatePost = useMutation({
    mutationFn: (payload: { id: number; values: unknown }) => endpoints.updatePost(payload.id, payload.values),
    onSuccess: async () => {
      setEditingPost(null);
      await queryClient.invalidateQueries({ queryKey: ['posts'] });
      toast.success('Campaign post updated');
    },
  });

  const deletePost = useMutation({
    mutationFn: (postId: number) => endpoints.deletePost(postId),
    onSuccess: async () => {
      setSelectedPostId(null);
      await queryClient.invalidateQueries({ queryKey: ['posts'] });
      toast.success('Campaign post deleted');
    },
  });

  const deleteComment = useMutation({
    mutationFn: (commentId: number) => endpoints.deletePostComment(commentId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['posts'] });
      toast.success('Comment removed');
    },
  });

  const submitUser = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const values = formValues(form);
    const newErrors: Record<string, string> = {};

    if (!values.name?.trim()) newErrors.name = 'Full name is required';
    if (!values.email?.trim()) newErrors.email = 'Email address is required';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) newErrors.email = 'Invalid email format';
    if (!values.password) newErrors.password = 'Password is required';
    else if (values.password.length < 8) newErrors.password = 'Must be at least 8 characters';
    if (!values.role) newErrors.role = 'Role is required';

    setErrors(newErrors);
    if (Object.keys(newErrors).length) return;

    createUser.mutate({
      name: values.name,
      email: values.email,
      password: values.password,
      role: values.role,
      specialty: values.specialty,
      phone: values.phone,
    }, {
      onSuccess: () => {
        form.reset();
        setErrors({});
        setSelectedRole('doctor');
      },
    });
  };

  const publishPost = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const values = Object.fromEntries(formData) as Record<string, string>;
    const image = formData.get('image') as File | null;
    const publish = (imageUrl?: string) => createPost.mutate({
      title: values.title,
      body: values.body,
      image_url: imageUrl,
    }, {
      onSuccess: () => {
        form.reset();
        setPreview('');
      },
    });

    if (image?.size) {
      const upload = new FormData();
      upload.append('folder', 'posts');
      upload.append('image', image);
      endpoints.uploadImage(upload).then((response) => publish(response.data.url));
      return;
    }

    publish();
  };

  const submitPostEdit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!editingPost) {
      return;
    }

    const values = formValues(event.currentTarget);
    updatePost.mutate({
      id: editingPost.id,
      values: {
        title: values.title,
        body: values.body,
        image_url: values.image_url || editingPost.imageUrl,
      },
    });
  };

  return (
    <motion.div className={styles.page} variants={pageMotion} initial="hidden" animate="show">
      <motion.div className={styles.header} variants={itemMotion}>
        <div>
          <span>{isSuperAdmin ? 'Platform governance' : 'User operations'}</span>
          <h2>{isSuperAdmin ? 'Super Admin Control Center' : 'Admin Console'}</h2>
        </div>
        <div>
          <input
            placeholder={activeTab === 'campaign' ? 'Search campaign posts' : 'Search users'}
            value={activeTab === 'campaign' ? postSearch : search}
            onChange={(event) => activeTab === 'campaign' ? setPostSearch(event.target.value) : setSearch(event.target.value)}
          />
        </div>
      </motion.div>

      <motion.div className={styles.tabBar} variants={itemMotion}>
        <button className={activeTab === 'users' ? styles.selectedTab : ''} type="button" onClick={() => setSearchParams({})}>
          User management
        </button>
        <button className={activeTab === 'campaign' ? styles.selectedTab : ''} type="button" onClick={() => setSearchParams({ tab: 'campaign' })}>
          Campaign
        </button>
      </motion.div>

      {activeTab === 'users' ? (
        <>
      <motion.div className={styles.stats} variants={pageMotion}>
        <StatCard label="Total users" value={analytics.data?.users.total ?? 0} />
        <StatCard label="Patients" value={analytics.data?.users.patients ?? 0} />
        <StatCard label="Doctors" value={analytics.data?.users.doctors ?? 0} />
        <StatCard label="Staff" value={analytics.data?.users.staff ?? 0} />
        <StatCard label="Pending appointments" value={analytics.data?.appointments.pending ?? 0} />
        <StatCard label="Medical records" value={analytics.data?.medicalRecords ?? 0} />
        <StatCard label="Messages" value={analytics.data?.messages ?? 0} />
        <StatCard label="Prescriptions" value={analytics.data?.prescriptions ?? 0} />
      </motion.div>

      <motion.div variants={itemMotion}>
        <Card>
          <div className={styles.sectionTitle}>
            <span>Account setup</span>
            <h3>Register User</h3>
            <p>Create patient, clinical, support, and admin accounts on the shared platform.</p>
          </div>
          <form className={styles.form} onSubmit={submitUser} noValidate>
            <div className={styles.formGroup}>
              <TextField
                label="Full name"
                name="name"
                placeholder="e.g. John Doe"
                error={errors.name}
                onChange={() => errors.name && setErrors((prev) => ({ ...prev, name: '' }))}
              />
              <TextField
                label="Email address"
                name="email"
                type="email"
                placeholder="e.g. john@hospital.com"
                error={errors.email}
                onChange={() => errors.email && setErrors((prev) => ({ ...prev, email: '' }))}
              />
              <TextField
                label="Temporary password"
                name="password"
                type="password"
                placeholder="At least 8 characters"
                minLength={8}
                error={errors.password}
                onChange={() => errors.password && setErrors((prev) => ({ ...prev, password: '' }))}
              />
            </div>

            <div className={styles.formDivider} />

            <div className={styles.formGroup}>
              <div className={styles.formRow}>
                <SelectField
                  label="Role"
                  name="role"
                  value={selectedRole}
                  onChange={(event) => {
                    setSelectedRole(event.target.value);
                    if (errors.role) setErrors((prev) => ({ ...prev, role: '' }));
                  }}
                  error={errors.role}
                >
                  {roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                </SelectField>
                <SelectField
                  label="Specialty / Department"
                  name="specialty"
                  defaultValue=""
                >
                  <option value="">{selectedRole === 'patient' ? 'No specialty needed' : 'Select specialty or department'}</option>
                  {specialtyDepartmentOptions.map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                  ))}
                </SelectField>
                <TextField
                  label="Phone"
                  name="phone"
                  type="tel"
                  placeholder="e.g. +1 (555) 123-4567"
                />
              </div>
            </div>

            <Button disabled={createUser.isPending}>
              <UserPlus size={18} />
              Register user
            </Button>
          </form>
        </Card>
      </motion.div>

      <Card className={styles.userPanel}>
        <div className={styles.sectionTitle}>
          <span>Access control</span>
          <h3>{isSuperAdmin ? 'Platform Users' : 'Managed Users'}</h3>
          <p>{isSuperAdmin ? 'Super admins can manage every account.' : 'Admins can manage non-admin accounts across the shared platform.'}</p>
        </div>
        {users.isLoading ? <SkeletonRows /> : (
          <div className={styles.table}>
            {users.data?.data.map((item) => (
              <motion.article key={item.id} variants={itemMotion} layout>
                <strong><Link to={`/profiles/${item.id}`}>{item.name}</Link></strong>
                <span>{item.email}</span>
                <span className={styles.badge}>{item.role.replace('_', ' ')}</span>
                <span>{item.specialty || 'No specialty'}</span>
                <div>
                  <Button
                    variant="ghost"
                    disabled={deleteUser.isPending || item.id === user?.id}
                    onClick={() => {
                      if (window.confirm(`Delete ${item.name}? This removes the account and related records.`)) {
                        deleteUser.mutate(item.id);
                      }
                    }}
                  >
                    <Trash2 size={16} /> Delete
                  </Button>
                </div>
              </motion.article>
            ))}
          </div>
        )}
      </Card>
        </>
      ) : (
        <section className={styles.adminGrid}>
          <motion.div variants={itemMotion}>
            <Card>
              <div className={styles.sectionTitle}>
                <span>Campaign publishing</span>
                <h3>{editingPost ? 'Edit Blog Post' : 'Create Blog Post'}</h3>
                <p>Publish, update, and remove public blog posts from the admin campaign workspace.</p>
              </div>
              <form className={styles.form} onSubmit={editingPost ? submitPostEdit : publishPost}>
                <input name="title" placeholder="Post title" defaultValue={editingPost?.title ?? ''} maxLength={120} />
                <textarea name="body" placeholder="Write the post..." defaultValue={editingPost?.body ?? ''} required />
                {editingPost ? (
                  <input name="image_url" placeholder="Cover image URL" defaultValue={editingPost.imageUrl ?? ''} />
                ) : (
                  <label className={styles.uploadBox}>
                    <ImagePlus size={18} />
                    <span>{preview || 'Add a cover image'}</span>
                    <input
                      name="image"
                      type="file"
                      accept="image/*"
                      onChange={(event) => setPreview(event.target.files?.[0]?.name ?? '')}
                    />
                  </label>
                )}
                <div className={styles.formActions}>
                  <Button disabled={createPost.isPending || updatePost.isPending}>
                    {editingPost ? <Edit3 size={17} /> : <Send size={17} />}
                    {editingPost ? 'Save post' : 'Publish post'}
                  </Button>
                  {editingPost ? <Button type="button" variant="secondary" onClick={() => setEditingPost(null)}>Cancel edit</Button> : null}
                </div>
              </form>
            </Card>
          </motion.div>

          <Card className={styles.userPanel}>
            <div className={styles.sectionTitle}>
              <span>Campaign library</span>
              <h3>Blog Posts</h3>
              <p>Select a post to review comments and manage the article.</p>
            </div>
            {posts.isLoading ? <SkeletonRows /> : (
              <div className={styles.table}>
                {visiblePosts.map((post) => (
                  <motion.article
                    key={post.id}
                    className={`${styles.tableAction} ${selectedPost?.id === post.id ? styles.selectedRow : ''}`}
                    role="button"
                    tabIndex={0}
                    onClick={() => setSelectedPostId(post.id)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        setSelectedPostId(post.id);
                      }
                    }}
                    layout
                  >
                    <strong>{post.title || 'Care update'}</strong>
                    <span>{excerpt(post.body)}</span>
                    <span className={styles.badge}>{post.counts.comments} comments</span>
                    <span>{formatDate(post.createdAt)}</span>
                    <div>
                      <Button type="button" variant="ghost" onClick={(event) => {
                        event.stopPropagation();
                        setEditingPost(post);
                      }}>
                        <Edit3 size={16} /> Edit
                      </Button>
                      <Button type="button" variant="ghost" disabled={deletePost.isPending} onClick={(event) => {
                        event.stopPropagation();
                        if (window.confirm('Delete this campaign post?')) {
                          deletePost.mutate(post.id);
                        }
                      }}>
                        <Trash2 size={16} /> Delete
                      </Button>
                    </div>
                  </motion.article>
                ))}
                {!visiblePosts.length ? <p>No campaign posts found.</p> : null}
              </div>
            )}
          </Card>

          <Card className={styles.focusCard}>
            <div className={styles.sectionTitle}>
              <span>Comment review</span>
              <h3>{selectedPost?.title || 'Select a post'}</h3>
              <p>Review reader comments for the selected campaign post.</p>
            </div>
            {selectedPost ? (
              <div className={styles.commentReview}>
                {selectedPost.comments?.map((comment) => (
                  <article key={comment.id}>
                    <div>
                      <strong>{comment.author.name}</strong>
                      <span>{formatDate(comment.createdAt)}</span>
                    </div>
                    <p>{comment.body}</p>
                    <Button
                      variant="ghost"
                      disabled={deleteComment.isPending}
                      onClick={() => {
                        if (window.confirm('Remove this comment?')) {
                          deleteComment.mutate(comment.id);
                        }
                      }}
                    >
                      <Trash2 size={16} /> Remove
                    </Button>
                  </article>
                ))}
                {!selectedPost.comments?.length ? (
                  <div className={styles.emptyReview}>
                    <MessageCircle size={24} />
                    <p>No comments on this post yet.</p>
                  </div>
                ) : null}
              </div>
            ) : (
              <div className={styles.emptyReview}>
                <Newspaper size={24} />
                <p>No post selected.</p>
              </div>
            )}
          </Card>
        </section>
      )}
    </motion.div>
  );
}

export type Role = 'super_admin' | 'admin' | 'doctor' | 'nurse' | 'patient' | 'lab_technician' | 'pharmacist';
export type AppointmentStatus = 'pending' | 'approved' | 'rejected' | 'completed' | 'cancelled';

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
  organizationId?: number | null;
  branchId?: number | null;
  specialty?: string | null;
  phone?: string | null;
  avatarUrl?: string | null;
  bio?: string | null;
  location?: string | null;
  website?: string | null;
  dateOfBirth?: string | null;
  followersCount?: number;
  followingCount?: number;
  reviewsCount?: number;
  averageRating?: number | null;
  canReview?: boolean;
  isFollowing?: boolean;
  createdAt?: string;
}

export interface Appointment {
  id: number;
  scheduledAt: string;
  reason: string;
  notes?: string | null;
  status: AppointmentStatus;
  patient?: User;
  doctor?: User;
  prescription?: Prescription;
}

export interface MedicalRecord {
  id: number;
  title: string;
  description?: string | null;
  fileUrl: string;
  fileType?: string | null;
  patient?: User;
  uploader?: User;
  createdAt: string;
}

export interface Vital {
  id: number;
  temperature?: number | null;
  heartRate?: number | null;
  bloodPressure?: string | null;
  weight?: number | null;
  height?: number | null;
  patient?: User;
  recordedBy?: User;
  recordedAt?: string;
  createdAt?: string;
}

export interface LabTest {
  id: number;
  name: string;
  status: 'requested' | 'processing' | 'completed' | 'flagged';
  resultSummary?: string | null;
  reportPath?: string | null;
  reportUrl?: string | null;
  patient?: User;
  requestedBy?: User;
  assignedTo?: User | null;
  createdAt?: string;
  updatedAt?: string;
}

export interface Message {
  id: number;
  body: string;
  sender: User;
  receiver: User;
  createdAt: string;
}

export interface Prescription {
  id: number;
  medication: string;
  dosage: string;
  instructions: string;
  issuedAt: string;
  patient?: User;
  doctor?: User;
}

export interface Medicine {
  id: number;
  name: string;
  sku?: string | null;
  category?: string | null;
  dosage_form?: string | null;
  strength?: string | null;
  manufacturer?: string | null;
  batch_number?: string | null;
  storage_location?: string | null;
  stock: number;
  reorder_level?: number;
  unit_price?: number;
  status?: 'active' | 'inactive';
  expires_at?: string | null;
  stock_movements?: MedicineStockMovement[];
}

export interface MedicineStockMovement {
  id: number;
  type: 'opening_stock' | 'restock' | 'dispense' | 'correction' | 'waste' | 'return';
  delta: number;
  quantity_before?: number;
  quantity_after?: number;
  reason: string;
  reference?: string | null;
  created_at?: string;
}

export interface MedicineOrder {
  id: number;
  medicine?: Medicine;
  patient?: User;
  preparedBy?: User | null;
  quantity: number;
  status: 'pending' | 'preparing' | 'ready' | 'completed' | 'cancelled';
  pickup_code?: string;
  notes?: string | null;
  pharmacist_note?: string | null;
  prepared_at?: string | null;
  picked_up_at?: string | null;
  created_at?: string;
}

export interface PharmacyRequestItem {
  id: number;
  medicationName: string;
  dosage?: string | null;
  quantity: number;
  instructions?: string | null;
  availabilityStatus: 'pending' | 'available' | 'unavailable';
  pharmacistNote?: string | null;
  dispenseStatus: 'pending' | 'dispensed' | 'given';
  dispensedBy?: { id: number; name: string } | null;
  dispensedAt?: string | null;
  givenBy?: { id: number; name: string } | null;
  givenAt?: string | null;
  medicine?: Pick<Medicine, 'id' | 'name' | 'strength' | 'dosage_form'> & Partial<Pick<Medicine, 'stock'>> | null;
}

export interface PharmacyRequest {
  id: number;
  clinicalNote: string;
  status: 'pending_review' | 'reviewed';
  patient?: User;
  doctor?: User;
  reviewedBy?: User | null;
  reviewedAt?: string | null;
  items?: PharmacyRequestItem[];
  createdAt: string;
}

export interface CareNotification {
  id: number;
  type: string;
  title: string;
  body: string;
  data?: Record<string, unknown> | null;
  readAt?: string | null;
  createdAt: string;
}

export interface UrgentCareRequest {
  id: number;
  severity: 'low' | 'moderate' | 'high' | 'critical';
  priority: number;
  preferredChannel: 'chat' | 'video' | 'phone';
  queueName: string;
  status: 'queued' | 'assigned' | 'in_progress' | 'resolved' | 'cancelled';
  symptoms: string[];
  message?: string | null;
  patient?: User;
  assignee?: User | null;
  assignedAt?: string | null;
  resolvedAt?: string | null;
  createdAt: string;
}

export interface VideoSignal {
  appointmentId: number;
  fromUserId: number;
  type: 'ready' | 'offer' | 'answer' | 'ice-candidate' | 'leave';
  payload?: Record<string, unknown>;
}

export interface Analytics {
  users: Record<string, number>;
  appointments: Record<string, number>;
  medicalRecords: number;
  messages: number;
  prescriptions: number;
}

export interface Paginated<T> {
  data: T[];
  links?: unknown;
  meta?: { current_page: number; last_page: number; total: number };
}

export interface EnterpriseStats {
  stats: Record<string, number>;
  activity: Array<{ label: string; time: string }>;
}

export interface Organization {
  id: number;
  name: string;
  slug: string;
  type: string;
  plan: string;
  status: string;
  currency: string;
  usersCount?: number;
  branchesCount?: number;
  settings?: Record<string, unknown>;
  branches?: Array<{ id: number; name: string }>;
}

export interface PatientProfile {
  id: number;
  patientNumber: string;
  allergies: string[];
  chronicConditions: string[];
  emergencyContact?: Record<string, string>;
  user?: User;
}

export interface PostComment {
  id: number;
  body: string;
  author: User;
  createdAt: string;
}

export interface Post {
  id: number;
  title?: string | null;
  body: string;
  imageUrl?: string | null;
  shareCount: number;
  author: User;
  repost?: Post | null;
  comments?: PostComment[];
  counts: {
    likes: number;
    saves: number;
    comments: number;
    reposts: number;
  };
  viewer: {
    liked: boolean;
    saved: boolean;
    canEdit?: boolean;
  };
  createdAt: string;
  updatedAt?: string;
}

export interface PractitionerReview {
  id: number;
  rating: number;
  comment?: string | null;
  patient?: User;
  practitioner?: User;
  appointmentId?: number | null;
  createdAt: string;
}

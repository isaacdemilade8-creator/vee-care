export type Role = 'hospital_admin' | 'doctor' | 'nurse' | 'patient' | 'lab_technician' | 'pharmacist';
export type PlatformRole = 'platform_super_admin' | 'platform_admin';
export type AppointmentStatus = 'pending' | 'approved' | 'rejected' | 'completed' | 'cancelled';
export type AdmissionStatus = 'admitted' | 'discharged';
export type StructureStatus = 'active' | 'inactive';
export type BedStatus = 'available' | 'occupied' | 'reserved' | 'unavailable';
export type WardType =
  | 'general'
  | 'private'
  | 'semi_private'
  | 'isolation'
  | 'icu'
  | 'maternity'
  | 'pediatric'
  | 'surgical'
  | 'emergency';

/** Hospital department as returned by the hospital-admin structure API. */
export interface Department {
  id: number;
  name: string;
  description?: string | null;
  status: StructureStatus;
  isActive: boolean;
  wardsCount?: number;
  createdAt?: string;
}

/** Hospital ward: a named grouping of rooms under a department. */
export interface Ward {
  id: number;
  departmentId?: number | null;
  department?: { id: number; name: string } | null;
  name: string;
  type?: WardType | null;
  capacity: number;
  status: StructureStatus;
  isActive: boolean;
  roomsCount?: number;
  bedsCount?: number;
  createdAt?: string;
}

/** Hospital room: a bed-carrying unit inside a ward. */
export interface Room {
  id: number;
  wardId: number;
  ward?: { id: number; name: string } | null;
  name: string;
  capacity: number;
  status: StructureStatus;
  isActive: boolean;
  bedsCount?: number;
  createdAt?: string;
}

/**
 * Hospital bed. `status` is the operational bed state while `isActive`
 * reflects the lifecycle toggle (deactivated beds are out of service).
 */
export interface Bed {
  id: number;
  roomId: number;
  room?: { id: number; name: string; ward?: { id: number; name: string } | null } | null;
  bedNumber: string;
  status: BedStatus;
  isActive: boolean;
  createdAt?: string;
}

/**
 * Inpatient admission as returned by the hospital-admin admission API. The
 * structure chain (department -> ward -> room -> bed) is denormalised onto the
 * row and kept consistent; `practitioner` is the optional attending user.
 */
export interface Admission {
  id: number;
  patient?: User;
  practitioner?: User | null;
  department?: { id: number; name: string } | null;
  ward?: { id: number; name: string } | null;
  room?: { id: number; name: string } | null;
  bed?: Bed | null;
  status: AdmissionStatus;
  admittedAt: string;
  dischargedAt?: string | null;
  reason?: string | null;
  notes?: string | null;
  createdAt?: string;
}

/** Per-ward bed occupancy summary from the bed dashboard API. */
export interface WardOccupancy {
  id: number;
  name: string;
  department?: { id: number; name: string } | null;
  capacity: number;
  totalBeds: number;
  availableBeds: number;
  occupiedBeds: number;
  reservedBeds: number;
  unavailableBeds: number;
  rooms: Array<{
    id: number;
    name: string;
    capacity: number;
    beds: Array<{ id: number; bedNumber: string; status: BedStatus; isActive: boolean }>;
  }>;
}

/**
 * Reusable working-period definition (Morning 08:00-16:00, Night 22:00-06:00).
 * An end time earlier than the start time spans midnight.
 */
export interface Shift {
  id: number;
  name: string;
  startTime: string;
  endTime: string;
  description?: string | null;
  status: StructureStatus;
  dutiesCount?: number;
  createdAt?: string;
}

export type DutyStatus = 'scheduled' | 'completed' | 'cancelled';

/**
 * A practitioner scheduled to work a particular shift on a particular date.
 * Whether someone is currently on duty is derived, never stored here.
 */
export interface DutyAssignment {
  id: number;
  practitioner?: { id: number; name: string; role: Role; specialty?: string | null } | null;
  shift?: { id: number; name: string; startTime: string; endTime: string; status: StructureStatus } | null;
  department?: { id: number; name: string } | null;
  ward?: { id: number; name: string } | null;
  dutyDate: string;
  status: DutyStatus;
  notes?: string | null;
  createdAt?: string;
}

/** One "currently on duty" team grouped by department / ward / shift. */
export interface CurrentDutyGroup {
  department: { id: number; name: string } | null;
  ward: { id: number; name: string } | null;
  shift: { id: number; name: string; startTime: string; endTime: string };
  practitioners: Array<{ id: number; name: string; role: Role; specialty?: string | null }>;
}

/** Response shape of the current-duty lookup endpoint (not paginated). */
export interface CurrentDutyResponse {
  data: CurrentDutyGroup[];
  meta: { asOf: string; timezone: string; date: string };
}

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role | PlatformRole;
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

export type AdminUserStatus = 'active' | 'inactive';

/**
 * Tenant (hospital) user as returned by the hospital-admin user management
 * API. Safe projection: identity, role, access state and profile details —
 * never credentials.
 */
export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: Role;
  isActive: boolean;
  status: AdminUserStatus;
  organizationId?: number | null;
  branchId?: number | null;
  branchName?: string | null;
  specialty?: string | null;
  phone?: string | null;
  avatarUrl?: string | null;
  createdAt?: string;
}

/** Outstanding hospital-user invitation (never exposes the token). */
export interface PendingAdminUserInvitation {
  id: number;
  email: string;
  name?: string | null;
  role: Role;
  inviterName?: string | null;
  expiresAt?: string | null;
  createdAt: string;
}

/** Single-use hospital-user invitation returned exactly once by invite(). */
export interface AdminUserInvitationResult {
  token: string;
  email: string;
  role: Role;
  name?: string | null;
  expiresAt: string;
  acceptUrl: string;
}

/** Hospital user directory response: paginated users plus outstanding invites. */
export interface AdminUsersResponse extends Paginated<AdminUser> {
  pending: PendingAdminUserInvitation[];
}

/** GET /admin/organization: the tenant's organization and its branches. */
export interface AdminOrganizationResponse {
  organization: { id: number; name: string } | null;
  branches: Array<{ id: number; name: string }>;
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
  card?: { id: number; cardNumber: string; status: string } | null;
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

export interface PatientCard {
  id: number;
  cardNumber: string;
  status: 'active' | 'inactive' | 'expired' | 'lost';
  issuedAt: string;
  expiresAt?: string | null;
  metadata?: Record<string, unknown> | null;
  patient?: { id: number; name: string; role: Role; avatarUrl?: string | null };
  issuer?: { id: number; name: string; role: string } | null;
  createdAt: string;
}

export interface AuditLog {
  id: number;
  action: string;
  metadata?: Record<string, unknown> | null;
  ipAddress?: string | null;
  userAgent?: string | null;
  auditableType?: string | null;
  auditableId?: number | null;
  user?: User | null;
  createdAt: string;
}

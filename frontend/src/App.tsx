import type { ReactNode } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { PlatformRoute } from './components/PlatformRoute';
import { ModuleRoute } from './components/ModuleRoute';
import { ProtectedRoute } from './components/ProtectedRoute';
import { TenantRoute } from './components/TenantRoute';
import { canAccessPlatform, platformRouteRoles, type PlatformRouteGroup } from './auth/roleAccess';
import { useAuth } from './context/AuthContext';
import { AddMedicinePage } from './pages/AddMedicinePage';
import { DashboardLayout } from './layouts/DashboardLayout';
import { AdminPanel } from './pages/AdminPanel';
import { AdminUsersPage } from './pages/AdminUsersPage';
import { AdminUserDetailPage } from './pages/AdminUserDetailPage';
import { AdminInvitationAcceptPage } from './pages/AdminInvitationAcceptPage';
import { DepartmentsPage } from './pages/admin/structure/DepartmentsPage';
import { WardsPage } from './pages/admin/structure/WardsPage';
import { RoomsPage } from './pages/admin/structure/RoomsPage';
import { BedsPage } from './pages/admin/structure/BedsPage';
import { AppointmentsPage } from './pages/AppointmentsPage';
import { AuthPage } from './pages/AuthPage';
import { BlogPage } from './pages/BlogPage';
import { CareServicesPage } from './pages/CareServicesPage';
import { ChatPage } from './pages/ChatPage';
import { DashboardPage } from './pages/DashboardPage';
import { DrugInventoryPage } from './pages/DrugInventoryPage';
import { EnterpriseDashboard } from './pages/EnterpriseDashboard';
import { EnterpriseModulesPage } from './pages/EnterpriseModulesPage';
import { LandingPage } from './pages/LandingPage';
import { LaboratoryPage } from './pages/LaboratoryPage';
import { MedicalRecordsPage } from './pages/MedicalRecordsPage';
import { PharmacyRequestPage } from './pages/PharmacyRequestPage';
import { NurseStationPage } from './pages/NurseStationPage';
import { ProfilePage } from './pages/ProfilePage';
import { ProfilesPage } from './pages/ProfilesPage';
import { ActivityLogPage } from './pages/ActivityLogPage';
import { PatientCardPage } from './pages/PatientCardPage';
import { SettingsPage } from './pages/SettingsPage';
import { VideoConsultationPage } from './pages/VideoConsultationPage';
import { PlatformDashboardPage } from './pages/PlatformDashboardPage';
import { PlatformLoginPage } from './pages/PlatformLoginPage';
import { PlatformInvitationAcceptPage } from './pages/PlatformInvitationAcceptPage';
import { PlatformHospitalsPage } from './pages/PlatformHospitalsPage';
import { PlatformHospitalDetailPage } from './pages/PlatformHospitalDetailPage';
import { PlatformApplicationsPage } from './pages/PlatformApplicationsPage';
import { PlatformApplicationDetailPage } from './pages/PlatformApplicationDetailPage';
import { PlatformAuditLogPage } from './pages/PlatformAuditLogPage';
import { PlatformUsersPage } from './pages/PlatformUsersPage';
import { PlatformUserDetailPage } from './pages/PlatformUserDetailPage';
import { HospitalBrandingPage } from './pages/admin/settings/HospitalBrandingPage';
import { HospitalModulesPage } from './pages/admin/settings/HospitalModulesPage';
import { HospitalRolesPage } from './pages/admin/settings/HospitalRolesPage';
import { HospitalGeneralSettingsPage } from './pages/admin/settings/HospitalGeneralSettingsPage';
import { HospitalInfoPage } from './pages/admin/settings/HospitalInfoPage';
import { HospitalSettingsLayout } from './layouts/HospitalSettingsLayout';
import { UnknownHospitalPage } from './pages/UnknownHospitalPage';
import { PlatformShell } from './layouts/PlatformShell';
import { routeRoles } from './auth/roleAccess';
import { useTenant } from './context/TenantContext';
/**
 * Host-aware routing:
 *
 *  - Platform hosts (apex / control-plane subdomains, and localhost in dev)
 *    render the control plane: platform login + authenticated shell.
 *  - Tenant hosts render the hospital-facing app. A tenant-looking host that
 *    the API cannot resolve shows the "hospital not found" page instead.
 */
/**
 * Guards a platform section by role; users without access are returned to the
 * platform dashboard instead of reaching an empty/erroring page.
 */
function PlatformSection({ group, children }: { group: PlatformRouteGroup; children: ReactNode }) {
  const { platformUser } = useAuth();

  if (!canAccessPlatform(platformUser?.role, platformRouteRoles[group])) {
    return <Navigate to="/platform" replace />;
  }

  return <>{children}</>;
}

function PlatformRoutes() {
  return (
    <Routes>
      <Route path="login" element={<PlatformLoginPage />} />
      <Route path="invitations/:token/accept" element={<PlatformInvitationAcceptPage />} />
      <Route path="" element={<PlatformShell />}>
        <Route index element={<PlatformDashboardPage />} />
        <Route
          path="hospitals"
          element={
            <PlatformSection group="tenants">
              <PlatformHospitalsPage />
            </PlatformSection>
          }
        />
        <Route
          path="hospitals/:id"
          element={
            <PlatformSection group="tenants">
              <PlatformHospitalDetailPage />
            </PlatformSection>
          }
        />
        <Route
          path="applications"
          element={
            <PlatformSection group="hospitalApplications">
              <PlatformApplicationsPage />
            </PlatformSection>
          }
        />
        <Route
          path="applications/:id"
          element={
            <PlatformSection group="hospitalApplications">
              <PlatformApplicationDetailPage />
            </PlatformSection>
          }
        />
        <Route
          path="audit-logs"
          element={
            <PlatformSection group="auditLogs">
              <PlatformAuditLogPage />
            </PlatformSection>
          }
        />
        <Route
          path="users"
          element={
            <PlatformSection group="users">
              <PlatformUsersPage />
            </PlatformSection>
          }
        />
        <Route
          path="users/:id"
          element={
            <PlatformSection group="users">
              <PlatformUserDetailPage />
            </PlatformSection>
          }
        />
      </Route>
      <Route path="*" element={<Navigate to="/platform" replace />} />
    </Routes>
  );
}

function TenantRoutes() {
  const { context } = useTenant();

  if (context === 'none') {
    return <UnknownHospitalPage />;
  }

  return (
    <Routes>
      <Route path="/" element={<LandingPage />} />
      <Route
        path="/blog"
        element={
          <ModuleRoute module="blog" fallback="/">
            <BlogPage />
          </ModuleRoute>
        }
      />
      <Route path="/login" element={<AuthPage mode="login" />} />
      <Route path="/register" element={<AuthPage mode="register" />} />
      <Route path="/invitations/:token/accept" element={<AdminInvitationAcceptPage />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<DashboardLayout />}>
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route element={<ProtectedRoute roles={routeRoles.care} />}>
            <Route path="/care-services" element={<CareServicesPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.appointments} />}>
            <Route element={<ModuleRoute module="appointments" />}>
              <Route path="/appointments" element={<AppointmentsPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.records} />}>
            <Route element={<ModuleRoute module="ehr" />}>
              <Route path="/records" element={<MedicalRecordsPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.chat} />}>
            <Route element={<ModuleRoute module="messaging" />}>
              <Route path="/chat" element={<ChatPage />} />
              <Route path="/chat/:userId" element={<ChatPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.profiles} />}>
            <Route path="/profiles" element={<ProfilesPage />} />
            <Route path="/profiles/:id" element={<ProfilePage />} />
          </Route>
          <Route element={<ModuleRoute module="patient_portal" />}>
            <Route path="/my-card" element={<PatientCardPage />} />
          </Route>
          <Route path="/activity-log" element={<ActivityLogPage />} />
          <Route path="/settings" element={<SettingsPage />} />
          <Route element={<ModuleRoute module="telemedicine" />}>
            <Route path="/consultations/:appointmentId" element={<VideoConsultationPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.enterpriseOverview} />}>
            <Route element={<ModuleRoute module="enterprise" />}>
              <Route path="/enterprise" element={<EnterpriseDashboard />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.enterprise} />}>
            <Route element={<ModuleRoute module="enterprise" />}>
              <Route path="/enterprise/modules" element={<EnterpriseModulesPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.nurseStation} />}>
            <Route element={<ModuleRoute module="nurse_station" />}>
              <Route path="/nurse/station" element={<NurseStationPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.laboratory} />}>
            <Route element={<ModuleRoute module="laboratory" />}>
              <Route path="/laboratory" element={<LaboratoryPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.pharmacy} />}>
            <Route element={<ModuleRoute module="pharmacy" />}>
              <Route path="/pharmacy/inventory" element={<DrugInventoryPage />} />
              <Route path="/pharmacy/medicines/new" element={<AddMedicinePage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.pharmacyRequests} />}>
            <Route element={<ModuleRoute module="pharmacy" />}>
              <Route path="/pharmacy/requests" element={<PharmacyRequestPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.admin} />}>
            <Route path="/admin" element={<AdminPanel />} />
            <Route path="/admin/users" element={<AdminUsersPage />} />
            <Route path="/admin/users/:id" element={<AdminUserDetailPage />} />
            <Route path="/admin/departments" element={<DepartmentsPage />} />
            <Route path="/admin/wards" element={<WardsPage />} />
            <Route path="/admin/rooms" element={<RoomsPage />} />
            <Route path="/admin/beds" element={<BedsPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.admin} />}>
            <Route path="/admin/settings" element={<HospitalSettingsLayout />}>
              <Route index element={<HospitalBrandingPage />} />
              <Route path="modules" element={<HospitalModulesPage />} />
              <Route path="roles" element={<HospitalRolesPage />} />
              <Route path="general" element={<HospitalGeneralSettingsPage />} />
              <Route path="hospital" element={<HospitalInfoPage />} />
            </Route>
          </Route>
        </Route>
      </Route>
    </Routes>
  );
}

export default function App() {
  return (
    <Routes>
      <Route
        path="/platform/*"
        element={
          <PlatformRoute>
            <PlatformRoutes />
          </PlatformRoute>
        }
      />
      <Route
        path="/*"
        element={
          <TenantRoute>
            <TenantRoutes />
          </TenantRoute>
        }
      />
    </Routes>
  );
}

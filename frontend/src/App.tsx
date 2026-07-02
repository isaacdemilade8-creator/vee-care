import { Route, Routes } from 'react-router-dom';
import { ProtectedRoute } from './components/ProtectedRoute';
import { AddMedicinePage } from './pages/AddMedicinePage';
import { DashboardLayout } from './layouts/DashboardLayout';
import { AdminPanel } from './pages/AdminPanel';
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
import { SettingsPage } from './pages/SettingsPage';
import { VideoConsultationPage } from './pages/VideoConsultationPage';
import { routeRoles } from './auth/roleAccess';

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<LandingPage />} />
      <Route path="/blog" element={<BlogPage />} />
      <Route path="/login" element={<AuthPage mode="login" />} />
      <Route path="/register" element={<AuthPage mode="register" />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<DashboardLayout />}>
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route element={<ProtectedRoute roles={routeRoles.care} />}>
            <Route path="/care-services" element={<CareServicesPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.appointments} />}>
            <Route path="/appointments" element={<AppointmentsPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.records} />}>
            <Route path="/records" element={<MedicalRecordsPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.chat} />}>
            <Route path="/chat" element={<ChatPage />} />
            <Route path="/chat/:userId" element={<ChatPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.profiles} />}>
            <Route path="/profiles" element={<ProfilesPage />} />
            <Route path="/profiles/:id" element={<ProfilePage />} />
          </Route>
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="/consultations/:appointmentId" element={<VideoConsultationPage />} />
          <Route element={<ProtectedRoute roles={routeRoles.enterpriseOverview} />}>
            <Route path="/enterprise" element={<EnterpriseDashboard />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.enterprise} />}>
            <Route path="/enterprise/modules" element={<EnterpriseModulesPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.nurseStation} />}>
            <Route path="/nurse/station" element={<NurseStationPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.laboratory} />}>
            <Route path="/laboratory" element={<LaboratoryPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.pharmacy} />}>
            <Route path="/pharmacy/inventory" element={<DrugInventoryPage />} />
            <Route path="/pharmacy/medicines/new" element={<AddMedicinePage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.pharmacyRequests} />}>
            <Route path="/pharmacy/requests" element={<PharmacyRequestPage />} />
          </Route>
          <Route element={<ProtectedRoute roles={routeRoles.admin} />}>
            <Route path="/admin" element={<AdminPanel />} />
          </Route>
        </Route>
      </Route>
    </Routes>
  );
}

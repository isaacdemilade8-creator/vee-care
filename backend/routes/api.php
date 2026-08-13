<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\EnterpriseController;
use App\Http\Controllers\Api\ImageUploadController;
use App\Http\Controllers\Api\HospitalStructureController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\MedicineOrderController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PatientCardController;
use App\Http\Controllers\Api\PharmacyRequestController;
use App\Http\Controllers\Api\Platform\HospitalApplicationController;
use App\Http\Controllers\Api\Platform\PlatformAuditLogController;
use App\Http\Controllers\Api\Platform\PlatformDashboardController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use App\Http\Controllers\Api\Platform\TenantConfigurationController as PlatformTenantConfigurationController;
use App\Http\Controllers\Api\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\Api\PlatformAuthController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PractitionerReviewController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\TenantConfigurationController;
use App\Http\Controllers\Api\TenantContextController;
use App\Http\Controllers\Api\UrgentCareRequestController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\VideoConsultationController;
use Illuminate\Support\Facades\Route;

Route::get('/tenant-context', [TenantContextController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Platform (control plane) routes
|--------------------------------------------------------------------------
|
| Served on the Vee-Care platform domain. Platform administrators manage
| tenants, domains, subscriptions and tenant migrations here. Authentication
| runs against the control database via the dedicated "platform" guard.
|
*/

Route::post('/platform/auth/login', [PlatformAuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/platform/hospital-applications', [HospitalApplicationController::class, 'store'])
    ->middleware('throttle:hospital-applications');
Route::post('/platform/hospital-applications/invitations/{token}/accept', [HospitalApplicationController::class, 'acceptInvitation'])
    ->middleware('throttle:invitation-accept');
Route::post('/platform/users/invitations/{token}/accept', [PlatformUserController::class, 'acceptInvitation'])
    ->middleware('throttle:invitation-accept');

Route::middleware(['auth:platform', 'throttle:120,1'])->group(function (): void {
    Route::get('/platform/me', [PlatformAuthController::class, 'me']);
    Route::post('/platform/auth/logout', [PlatformAuthController::class, 'logout']);

    Route::prefix('platform/users')->middleware('role:platform_super_admin,platform_admin')->group(function (): void {
        Route::get('/', [PlatformUserController::class, 'index']);
        Route::post('/', [PlatformUserController::class, 'invite'])->middleware('throttle:platform-user-invite');
        Route::get('/{user}', [PlatformUserController::class, 'show']);
        Route::patch('/{user}', [PlatformUserController::class, 'update']);
        Route::post('/{user}/activate', [PlatformUserController::class, 'activate']);
        Route::post('/{user}/deactivate', [PlatformUserController::class, 'deactivate']);
        Route::delete('/invitations/{invitation}', [PlatformUserController::class, 'revokeInvitation']);
    });

    Route::prefix('platform/hospital-applications')->middleware('role:platform_super_admin,platform_admin')->group(function (): void {
        Route::get('/', [HospitalApplicationController::class, 'index']);
        Route::get('/{application}', [HospitalApplicationController::class, 'show']);
        Route::patch('/{application}', [HospitalApplicationController::class, 'review']);
        Route::post('/{application}/approve', [HospitalApplicationController::class, 'approve']);
        Route::post('/{application}/reject', [HospitalApplicationController::class, 'reject']);
    });

    Route::prefix('platform')->middleware('role:platform_super_admin,platform_admin')->group(function (): void {
        Route::get('/summary', [PlatformDashboardController::class, 'summary']);
        Route::get('/audit-logs', [PlatformAuditLogController::class, 'index']);
    });

    Route::prefix('platform/tenants')->middleware('role:platform_super_admin,platform_admin')->group(function (): void {
        Route::get('/', [PlatformTenantController::class, 'index']);
        Route::post('/', [PlatformTenantController::class, 'store']);
        Route::get('/{tenant}', [PlatformTenantController::class, 'show']);
        Route::patch('/{tenant}', [PlatformTenantController::class, 'update']);
        Route::get('/{tenant}/configuration', [PlatformTenantConfigurationController::class, 'show']);
        Route::patch('/{tenant}/configuration', [PlatformTenantConfigurationController::class, 'update']);
        Route::post('/{tenant}/domains', [PlatformTenantController::class, 'addDomain']);
        Route::delete('/{tenant}/domains/{domain}', [PlatformTenantController::class, 'removeDomain']);
        Route::post('/{tenant}/migrate', [PlatformTenantController::class, 'migrate']);
    });
});

/*
|--------------------------------------------------------------------------
| Tenant application routes
|--------------------------------------------------------------------------
|
| Served on tenant subdomains (e.g. hospital-one.vee-care.test). The
| ResolveTenant middleware binds the current tenant's database before these
| routes execute, so all models below query the tenant database.
|
*/

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::get('/posts', [PostController::class, 'index'])->middleware('module:blog');
Route::get('/posts/{post}', [PostController::class, 'show'])->middleware('module:blog');

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Hospital-admin self-service configuration. Resolves the active tenant
    // from the request host (never a client-supplied id) and is restricted to
    // hospital_admin. Platform admins configure tenants via the platform API.
    Route::get('/configuration', [TenantConfigurationController::class, 'show'])
        ->middleware('role:hospital_admin');
    Route::patch('/configuration', [TenantConfigurationController::class, 'update'])
        ->middleware('role:hospital_admin');

    Route::get('/doctors', [AppointmentController::class, 'doctors']);
    Route::post('/uploads/images', [ImageUploadController::class, 'store']);
    Route::get('/profiles', [UserProfileController::class, 'index']);
    Route::patch('/profiles/me', [UserProfileController::class, 'update']);
    Route::get('/profiles/{user}', [UserProfileController::class, 'show'])->whereNumber('user');
    Route::get('/profiles/{user}/reviews', [PractitionerReviewController::class, 'index'])->whereNumber('user');
    Route::post('/profiles/{user}/reviews', [PractitionerReviewController::class, 'store'])->middleware('role:patient')->whereNumber('user');

    Route::post('/posts', [PostController::class, 'store'])->middleware('role:hospital_admin', 'module:blog');
    Route::patch('/posts/{post}', [PostController::class, 'update'])->middleware('role:hospital_admin', 'module:blog');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->middleware('role:hospital_admin', 'module:blog');
    Route::post('/posts/{post}/comments', [PostController::class, 'comment'])->middleware('module:blog');
    Route::delete('/post-comments/{comment}', [PostController::class, 'destroyComment'])->middleware('role:hospital_admin', 'module:blog');

    Route::get('/appointments', [AppointmentController::class, 'index'])
        ->middleware('role:patient,doctor,hospital_admin');
    Route::post('/appointments', [AppointmentController::class, 'store'])
        ->middleware('role:patient');
    Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update'])
        ->middleware('role:doctor,hospital_admin');
    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::get('/medical-records', [MedicalRecordController::class, 'index'])
        ->middleware('role:patient,doctor,nurse,lab_technician,hospital_admin');
    Route::post('/medical-records', [MedicalRecordController::class, 'store'])
        ->middleware('role:patient,doctor,nurse,lab_technician,hospital_admin');
    Route::get('/prescriptions', [PrescriptionController::class, 'index'])
        ->middleware('role:doctor,patient,hospital_admin', 'module:prescriptions');
    Route::post('/prescriptions', [PrescriptionController::class, 'store'])
        ->middleware('role:doctor', 'module:prescriptions');

    Route::get('/chat/contacts', [ChatController::class, 'contacts']);
    Route::get('/chat/thread/{user}', [ChatController::class, 'thread']);
    Route::post('/chat/messages', [ChatController::class, 'send']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    Route::get('/patient-cards/my-card', [PatientCardController::class, 'myCard']);
    Route::post('/patient-cards/request', [PatientCardController::class, 'requestCard'])
        ->middleware('role:patient');
    Route::get('/patient-cards', [PatientCardController::class, 'index'])
        ->middleware('role:patient,nurse,hospital_admin');
    Route::post('/patient-cards', [PatientCardController::class, 'store'])
        ->middleware('role:hospital_admin');
    Route::get('/patient-cards/{patientCard}', [PatientCardController::class, 'show']);
    Route::patch('/patient-cards/{patientCard}', [PatientCardController::class, 'update'])
        ->middleware('role:nurse,hospital_admin');

    Route::get('/urgent-care-requests', [UrgentCareRequestController::class, 'index'])
        ->middleware('role:patient,doctor,nurse,hospital_admin', 'module:urgent_care');

    Route::post('/urgent-care-requests', [UrgentCareRequestController::class, 'store'])
        ->middleware('role:patient', 'module:urgent_care');
    Route::patch('/urgent-care-requests/{urgentCareRequest}', [UrgentCareRequestController::class, 'update'])
        ->middleware('role:doctor,nurse,hospital_admin', 'module:urgent_care');

    Route::prefix('pharmacy')->middleware('module:pharmacy')->group(function (): void {
        Route::get('/medicines', [MedicineOrderController::class, 'medicines'])
            ->middleware('role:doctor,hospital_admin,pharmacist');
        Route::get('/requests', [PharmacyRequestController::class, 'index'])
            ->middleware('role:doctor,patient,hospital_admin,pharmacist');
        Route::post('/requests', [PharmacyRequestController::class, 'store'])
            ->middleware('role:doctor');
        Route::get('/requests/{pharmacyRequest}', [PharmacyRequestController::class, 'show'])
            ->middleware('role:doctor,patient,hospital_admin,pharmacist');
        Route::patch('/requests/items/{pharmacyRequestItem}', [PharmacyRequestController::class, 'updateItem'])
            ->middleware('role:hospital_admin,pharmacist');
        Route::post('/requests/items/{pharmacyRequestItem}/dispense', [PharmacyRequestController::class, 'dispenseItem'])
            ->middleware('role:hospital_admin,pharmacist');
        Route::post('/requests/items/{pharmacyRequestItem}/give', [PharmacyRequestController::class, 'giveItem'])
            ->middleware('role:hospital_admin,pharmacist');
        Route::post('/requests/{pharmacyRequest}/complete', [PharmacyRequestController::class, 'completeReview'])
            ->middleware('role:hospital_admin,pharmacist');
    });

    Route::prefix('enterprise')->group(function (): void {
        // The enterprise-analytics and staff-management surfaces are gated by
        // the `enterprise` module. Clinical/shared endpoints below (patients,
        // ehr, vitals, ehr/entries) stay available to their own role-gated
        // surfaces, and the laboratory/pharmacy/urgent-care sub-routes keep
        // their own module gates so those modules work independently.
        Route::get('/dashboard', [EnterpriseController::class, 'dashboard'])
            ->middleware('role:hospital_admin,lab_technician,pharmacist', 'module:enterprise');
        Route::get('/patients', [EnterpriseController::class, 'patients'])
            ->middleware('role:hospital_admin,doctor,nurse');
        Route::get('/staff', [EnterpriseController::class, 'staff'])
            ->middleware('role:hospital_admin', 'module:enterprise');
        Route::get('/ehr', [EnterpriseController::class, 'ehr'])
            ->middleware('role:hospital_admin,doctor,nurse,lab_technician');
        Route::get('/vitals', [EnterpriseController::class, 'vitals'])
            ->middleware('role:hospital_admin,doctor,nurse');
        Route::get('/lab-tests', [EnterpriseController::class, 'labTests'])
            ->middleware('role:hospital_admin,doctor,nurse,lab_technician', 'module:laboratory');
        Route::post('/lab-tests', [EnterpriseController::class, 'createLabTest'])
            ->middleware('role:doctor,nurse,hospital_admin', 'module:laboratory');
        Route::get('/billing', [EnterpriseController::class, 'billing'])
            ->middleware('role:hospital_admin', 'module:enterprise');
        Route::get('/pharmacy', [EnterpriseController::class, 'pharmacy'])
            ->middleware('role:hospital_admin,pharmacist', 'module:pharmacy');
        Route::post('/medicines', [EnterpriseController::class, 'createMedicine'])
            ->middleware('role:hospital_admin,pharmacist', 'module:pharmacy');
        Route::patch('/medicines/{medicine}', [EnterpriseController::class, 'updateMedicine'])
            ->middleware('role:hospital_admin,pharmacist', 'module:pharmacy');
        Route::delete('/medicines/{medicine}', [EnterpriseController::class, 'deleteMedicine'])
            ->middleware('role:hospital_admin,pharmacist', 'module:pharmacy');
        Route::post('/ai/patient-summary', [EnterpriseController::class, 'aiSummary'])
            ->middleware('role:doctor,hospital_admin', 'module:enterprise');
        Route::post('/ehr/entries', [EnterpriseController::class, 'createEhrEntry'])->middleware('role:doctor,hospital_admin');
        Route::post('/vitals', [EnterpriseController::class, 'recordVitals'])->middleware('role:nurse,doctor');
        Route::patch('/lab-tests/{labTest}', [EnterpriseController::class, 'updateLabResult'])->middleware('role:lab_technician,doctor,hospital_admin', 'module:laboratory');
        Route::post('/lab-tests/{labTest}/result', [EnterpriseController::class, 'updateLabResult'])->middleware('role:lab_technician,doctor,hospital_admin', 'module:laboratory');
        Route::patch('/medicines/{medicine}/stock', [EnterpriseController::class, 'adjustMedicineStock'])->middleware('role:pharmacist,hospital_admin', 'module:pharmacy');
        Route::post('/staff', [EnterpriseController::class, 'registerStaff'])->middleware('role:hospital_admin', 'module:enterprise');
        Route::post('/staff/invitations', [EnterpriseController::class, 'registerStaff'])->middleware('role:hospital_admin', 'module:enterprise');
        Route::post('/emergency-requests', [EnterpriseController::class, 'emergencyRequest'])->middleware('role:patient', 'module:urgent_care');
    });

    Route::get('/video-consultations/{appointment}', [VideoConsultationController::class, 'show'])
        ->middleware('role:patient,doctor', 'module:telemedicine');
    Route::post('/video-consultations/{appointment}/signal', [VideoConsultationController::class, 'signal'])
        ->middleware('role:patient,doctor', 'module:telemedicine');

    Route::prefix('admin')->middleware('role:hospital_admin')->group(function (): void {
        Route::get('/analytics', [AdminController::class, 'analytics']);
        Route::get('/appointments', [AdminController::class, 'appointments']);
        Route::get('/organization', [AdminUserController::class, 'organization']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'storeUser'])->middleware('throttle:admin-user-invite');
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::patch('/users/{user}', [AdminUserController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminUserController::class, 'destroyUser']);
        Route::post('/users/{user}/activate', [AdminUserController::class, 'activate']);
        Route::post('/users/{user}/deactivate', [AdminUserController::class, 'deactivate']);
        Route::post('/users/invitations', [AdminUserController::class, 'invite'])->middleware('throttle:admin-user-invite');
        Route::delete('/users/invitations/{invitation}', [AdminUserController::class, 'revokeInvitation']);

        Route::get('/departments', [HospitalStructureController::class, 'indexDepartments']);
        Route::post('/departments', [HospitalStructureController::class, 'storeDepartment']);
        Route::get('/departments/{department}', [HospitalStructureController::class, 'showDepartment']);
        Route::patch('/departments/{department}', [HospitalStructureController::class, 'updateDepartment']);
        Route::delete('/departments/{department}', [HospitalStructureController::class, 'destroyDepartment']);

        Route::get('/wards', [HospitalStructureController::class, 'indexWards']);
        Route::post('/wards', [HospitalStructureController::class, 'storeWard']);
        Route::get('/wards/{ward}', [HospitalStructureController::class, 'showWard']);
        Route::patch('/wards/{ward}', [HospitalStructureController::class, 'updateWard']);
        Route::delete('/wards/{ward}', [HospitalStructureController::class, 'destroyWard']);

        Route::get('/rooms', [HospitalStructureController::class, 'indexRooms']);
        Route::post('/rooms', [HospitalStructureController::class, 'storeRoom']);
        Route::get('/rooms/{room}', [HospitalStructureController::class, 'showRoom']);
        Route::patch('/rooms/{room}', [HospitalStructureController::class, 'updateRoom']);
        Route::delete('/rooms/{room}', [HospitalStructureController::class, 'destroyRoom']);

        Route::get('/beds', [HospitalStructureController::class, 'indexBeds']);
        Route::post('/beds', [HospitalStructureController::class, 'storeBed']);
        Route::get('/beds/{bed}', [HospitalStructureController::class, 'showBed']);
        Route::patch('/beds/{bed}', [HospitalStructureController::class, 'updateBed']);
        Route::delete('/beds/{bed}', [HospitalStructureController::class, 'destroyBed']);
    });
});

Route::post('/admin/users/invitations/{token}/accept', [AdminUserController::class, 'acceptInvitation'])
    ->middleware('throttle:invitation-accept');

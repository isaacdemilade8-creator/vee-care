<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\EnterpriseController;
use App\Http\Controllers\Api\ImageUploadController;
use App\Http\Controllers\Api\MedicineOrderController;
use App\Http\Controllers\Api\PharmacyRequestController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PractitionerReviewController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\UrgentCareRequestController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\VideoConsultationController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/doctors', [AppointmentController::class, 'doctors']);
    Route::post('/uploads/images', [ImageUploadController::class, 'store']);
    Route::get('/profiles', [UserProfileController::class, 'index']);
    Route::patch('/profiles/me', [UserProfileController::class, 'update']);
    Route::get('/profiles/{user}', [UserProfileController::class, 'show'])->whereNumber('user');
    Route::get('/profiles/{user}/reviews', [PractitionerReviewController::class, 'index'])->whereNumber('user');
    Route::post('/profiles/{user}/reviews', [PractitionerReviewController::class, 'store'])->middleware('role:patient')->whereNumber('user');

    Route::post('/posts', [PostController::class, 'store'])->middleware('role:admin,super_admin');
    Route::patch('/posts/{post}', [PostController::class, 'update'])->middleware('role:admin,super_admin');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->middleware('role:admin,super_admin');
    Route::post('/posts/{post}/comments', [PostController::class, 'comment']);
    Route::delete('/post-comments/{comment}', [PostController::class, 'destroyComment'])->middleware('role:admin,super_admin');

    Route::get('/appointments', [AppointmentController::class, 'index'])
        ->middleware('role:patient,doctor,admin,super_admin');
    Route::post('/appointments', [AppointmentController::class, 'store'])
        ->middleware('role:patient');
    Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update'])
        ->middleware('role:doctor,admin,super_admin');
    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::get('/medical-records', [MedicalRecordController::class, 'index'])
        ->middleware('role:patient,doctor,nurse,lab_technician,admin,super_admin');
    Route::post('/medical-records', [MedicalRecordController::class, 'store'])
        ->middleware('role:patient,doctor,nurse,lab_technician,admin');
    Route::get('/prescriptions', [PrescriptionController::class, 'index'])
        ->middleware('role:doctor,patient,admin,super_admin');
    Route::post('/prescriptions', [PrescriptionController::class, 'store'])
        ->middleware('role:doctor');

    Route::get('/chat/contacts', [ChatController::class, 'contacts']);
    Route::get('/chat/thread/{user}', [ChatController::class, 'thread']);
    Route::post('/chat/messages', [ChatController::class, 'send']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::get('/urgent-care-requests', [UrgentCareRequestController::class, 'index'])
        ->middleware('role:patient,doctor,nurse,admin,super_admin');
    Route::post('/urgent-care-requests', [UrgentCareRequestController::class, 'store'])
        ->middleware('role:patient');
    Route::patch('/urgent-care-requests/{urgentCareRequest}', [UrgentCareRequestController::class, 'update'])
        ->middleware('role:doctor,nurse,admin,super_admin');

    Route::prefix('pharmacy')->group(function (): void {
        Route::get('/medicines', [MedicineOrderController::class, 'medicines'])
            ->middleware('role:doctor,admin,pharmacist,super_admin');
        Route::get('/requests', [PharmacyRequestController::class, 'index'])
            ->middleware('role:doctor,patient,admin,pharmacist,super_admin');
        Route::post('/requests', [PharmacyRequestController::class, 'store'])
            ->middleware('role:doctor');
        Route::get('/requests/{pharmacyRequest}', [PharmacyRequestController::class, 'show'])
            ->middleware('role:doctor,patient,admin,pharmacist,super_admin');
        Route::patch('/requests/items/{pharmacyRequestItem}', [PharmacyRequestController::class, 'updateItem'])
            ->middleware('role:admin,pharmacist,super_admin');
        Route::post('/requests/items/{pharmacyRequestItem}/dispense', [PharmacyRequestController::class, 'dispenseItem'])
            ->middleware('role:admin,pharmacist,super_admin');
        Route::post('/requests/items/{pharmacyRequestItem}/give', [PharmacyRequestController::class, 'giveItem'])
            ->middleware('role:admin,pharmacist,super_admin');
        Route::post('/requests/{pharmacyRequest}/complete', [PharmacyRequestController::class, 'completeReview'])
            ->middleware('role:admin,pharmacist,super_admin');
    });

    Route::prefix('enterprise')->group(function (): void {
        Route::get('/dashboard', [EnterpriseController::class, 'dashboard'])
            ->middleware('role:admin,lab_technician,pharmacist,super_admin');
        Route::get('/patients', [EnterpriseController::class, 'patients'])
            ->middleware('role:admin,doctor,nurse,super_admin');
        Route::get('/staff', [EnterpriseController::class, 'staff'])
            ->middleware('role:admin,super_admin');
        Route::get('/ehr', [EnterpriseController::class, 'ehr'])
            ->middleware('role:admin,doctor,nurse,lab_technician,super_admin');
        Route::get('/vitals', [EnterpriseController::class, 'vitals'])
            ->middleware('role:admin,doctor,nurse,super_admin');
        Route::get('/lab-tests', [EnterpriseController::class, 'labTests'])
            ->middleware('role:admin,doctor,nurse,lab_technician,super_admin');
        Route::post('/lab-tests', [EnterpriseController::class, 'createLabTest'])
            ->middleware('role:doctor,nurse,admin,super_admin');
        Route::get('/billing', [EnterpriseController::class, 'billing'])
            ->middleware('role:admin,super_admin');
        Route::get('/pharmacy', [EnterpriseController::class, 'pharmacy'])
            ->middleware('role:admin,pharmacist,super_admin');
        Route::post('/medicines', [EnterpriseController::class, 'createMedicine'])
            ->middleware('role:admin,pharmacist,super_admin');
        Route::patch('/medicines/{medicine}', [EnterpriseController::class, 'updateMedicine'])
            ->middleware('role:admin,pharmacist,super_admin');
        Route::delete('/medicines/{medicine}', [EnterpriseController::class, 'deleteMedicine'])
            ->middleware('role:admin,pharmacist,super_admin');
        Route::post('/ai/patient-summary', [EnterpriseController::class, 'aiSummary'])
            ->middleware('role:doctor,admin');
        Route::post('/ehr/entries', [EnterpriseController::class, 'createEhrEntry'])->middleware('role:doctor,admin');
        Route::post('/vitals', [EnterpriseController::class, 'recordVitals'])->middleware('role:nurse,doctor');
        Route::patch('/lab-tests/{labTest}', [EnterpriseController::class, 'updateLabResult'])->middleware('role:lab_technician,doctor,admin,super_admin');
        Route::post('/lab-tests/{labTest}/result', [EnterpriseController::class, 'updateLabResult'])->middleware('role:lab_technician,doctor,admin,super_admin');
        Route::patch('/medicines/{medicine}/stock', [EnterpriseController::class, 'adjustMedicineStock'])->middleware('role:pharmacist,admin,super_admin');
        Route::post('/staff', [EnterpriseController::class, 'registerStaff'])->middleware('role:admin');
        Route::post('/staff/invitations', [EnterpriseController::class, 'registerStaff'])->middleware('role:admin');
        Route::post('/emergency-requests', [EnterpriseController::class, 'emergencyRequest'])->middleware('role:patient');
    });

    Route::get('/video-consultations/{appointment}', [VideoConsultationController::class, 'show'])
        ->middleware('role:patient,doctor');
    Route::post('/video-consultations/{appointment}/signal', [VideoConsultationController::class, 'signal'])
        ->middleware('role:patient,doctor');

    Route::prefix('admin')->middleware('role:super_admin,admin')->group(function (): void {
        Route::get('/analytics', [AdminController::class, 'analytics']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users', [AdminController::class, 'storeUser']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser']);
        Route::get('/appointments', [AdminController::class, 'appointments']);
    });
});

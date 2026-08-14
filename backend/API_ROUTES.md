# HealthTech API Routes

Base URL: `http://127.0.0.1:8000/api`

Authenticated requests use `Authorization: Bearer <token>`.

## Auth
- `POST /auth/register` - register patient or doctor.
- `POST /auth/login` - issue Sanctum token.
- `GET /auth/me` - current user.
- `POST /auth/logout` - revoke current token.

## Hospital configuration (hospital_admin only)
- `GET /configuration` - read the hospital's branding, modules, roles, settings and name.
- `PATCH /configuration` - partial update; accepts `name`, `branding`, `modules`, `roles`, `settings`.
- `GET /platform/tenants/{tenant}/configuration` - platform view of a tenant's configuration.
- `PATCH /platform/tenants/{tenant}/configuration` - platform update of a tenant's configuration.

## Appointments
- `GET /appointments?status=pending&search=smith&page=1`
- `POST /appointments` - patient books an appointment.
- `PATCH /appointments/{id}` - doctor/admin updates status.
- `GET /doctors` - list doctors for booking.

## Medical Records
- `GET /medical-records?search=blood`
- `POST /medical-records` - multipart upload with `title`, optional `description`, optional `patient_id`, and `file`.

## Chat
- `GET /chat/contacts`
- `GET /chat/thread/{user}`
- `POST /chat/messages` - send `receiver_id` and `body`.

## Prescriptions
- `GET /prescriptions`
- `POST /prescriptions` - doctors create/update a prescription for an appointment.

## Notifications
- `GET /notifications`
- `GET /notifications?unread=1`
- `PATCH /notifications/{id}/read`
- `POST /notifications/read-all`

Real-time notifications are broadcast on the private channel `private-users.{id}` using the `notification.created` event.

## Video Consultations
- `GET /video-consultations/{appointment}` - load and authorize an approved appointment room.
- `POST /video-consultations/{appointment}/signal` - relay WebRTC signaling payloads.

Signals are broadcast on `private-video.appointments.{appointmentId}` using the `video.signal` event.

## Admin
- `GET /admin/analytics`
- `GET /admin/users?role=doctor&search=care`
- `PATCH /admin/users/{user}`
- `GET /admin/appointments?status=approved`

## Hospital structure (hospital_admin only)
- `GET /admin/departments`, `POST /admin/departments`, `GET/PATCH/DELETE /admin/departments/{department}`
- `GET /admin/wards`, `POST /admin/wards`, `GET/PATCH/DELETE /admin/wards/{ward}`
- `GET /admin/rooms`, `POST /admin/rooms`, `GET/PATCH/DELETE /admin/rooms/{room}`
- `GET /admin/beds`, `POST /admin/beds`, `GET/PATCH/DELETE /admin/beds/{bed}`

## Admissions (hospital_admin only)
- `GET /admin/admissions?status=admitted&search=smith&page=1` - list admissions (history retained).
- `POST /admin/admissions` - admit a patient: `patient_id`, `department_id`, `ward_id`, `room_id`, `bed_id`, optional `practitioner_id`, `admitted_at`, `reason`, `notes`. Marks the bed `occupied`; one active admission per patient/bed.
- `GET /admin/admissions/{admission}` - single admission with patient/department/ward/room/bed/practitioner.
- `PATCH /admin/admissions/{admission}` - metadata only (`reason`, `notes`, `practitioner_id`).
- `POST /admin/admissions/{admission}/discharge` - discharge; sets `discharged_at`, releases the bed to `available`.
- `DELETE /admin/admissions/{admission}` - delete (releases bed if still admitted).
- `GET /admin/beds/availability` - list beds with admission context (filter `?status=&room_id=&search=`), for admission pickers.
- `GET /admin/occupancy?department_id=&page=1` - per-ward occupancy (capacity/available/occupied/reserved/unavailable) with room→bed layout.

Double-booking is prevented with `lockForUpdate()` plus partial unique indexes on `(bed_id, status)` and `(patient_id, status)` where `status='admitted'`.

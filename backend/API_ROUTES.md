# HealthTech API Routes

Base URL: `http://127.0.0.1:8000/api`

Authenticated requests use `Authorization: Bearer <token>`.

## Auth
- `POST /auth/register` - register patient or doctor.
- `POST /auth/login` - issue Sanctum token.
- `GET /auth/me` - current user.
- `POST /auth/logout` - revoke current token.

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

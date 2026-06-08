# Enterprise HealthTech SaaS Architecture

## Tenancy

- `organizations` own tenant data.
- `branches` support multi-location hospitals and clinics.
- Tenant-scoped records carry `organization_id`.
- `tenant` middleware blocks organization APIs for users without a tenant context.
- `super_admin` can operate outside tenant context.

## RBAC

Supported roles:

- `super_admin`
- `hospital_admin`
- `doctor`
- `nurse`
- `receptionist`
- `patient`
- `lab_technician`
- `pharmacist`

Role middleware protects route groups. Policies protect appointment, record, prescription, and messaging actions.

## Security

- Sanctum bearer tokens.
- Role middleware.
- Tenant middleware.
- Audit logging service.
- Session/device tracking schema.
- 2FA-ready user fields.
- Sensitive EHR body fields stored as encrypted text.
- File upload validation on medical records.

## Modules

- Organization and branch management.
- Patients, vitals, emergency contacts, chronic conditions.
- EHR entries, lab tests, medical uploads.
- Appointments, telemedicine, chat.
- Prescriptions and pharmacy inventory.
- Billing, invoices, transactions.
- Insurance claims.
- Notifications over in-app API and realtime broadcasting.
- AI clinical assistant interface for future model integration.

## Realtime

- Laravel broadcasting + Pusher/Echo for in-app notifications and WebRTC signaling.
- Socket.io client seam exists for teams that deploy a Socket.io gateway.

## DevOps

- Docker Compose includes MySQL, Redis, Laravel API, and Vite frontend.
- Queue connection can be set to Redis for production-style async jobs.
- PWA support is enabled in the Vite build.

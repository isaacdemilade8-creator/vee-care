# vee-care HealthTech

Production-ready fullstack HealthTech starter with a decoupled Laravel API and React TypeScript frontend.

## Stack

- Backend: Laravel API, Sanctum token auth, MySQL, REST resources, role middleware.
- Frontend: React TSX, React Router, TanStack Query, Context API, Axios, React Hook Form, Zod, SCSS Modules.

## Run Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Create a MySQL database named `healthtech`, then run:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Demo logins all use password `password`:

- `admin@healthtech.test`
- `doctor@healthtech.test`
- `patient@healthtech.test`

## Run Frontend

```bash
cd frontend
cp .env.example .env
npm install
npm run dev
```

Frontend default: `http://127.0.0.1:5173`

API default: `http://127.0.0.1:8000/api`

### Local tenant hosts

Tenant and platform subdomains (`hospital-one.vee-care.test`, `admin.vee-care.test`, …) resolve locally via the OS hosts file; see
[Local tenant-host development setup](MULTI_TENANCY_ARCHITECTURE.md#local-tenant-host-development-setup) in
[MULTI_TENANCY_ARCHITECTURE.md](MULTI_TENANCY_ARCHITECTURE.md).

## API Routes

See [backend/API_ROUTES.md](backend/API_ROUTES.md).

## Hospital structure & admissions

Departments, wards, rooms and beds are managed under `/admin/*` (hospital_admin). Admissions link a patient to a bed,
track the discharge lifecycle with history retention, and are protected against double-booking via row locks and
partial unique indexes. See [backend/API_ROUTES.md](backend/API_ROUTES.md#admissions-hospital_admin-only).

## Real-Time Notifications

See [REALTIME_NOTIFICATIONS.md](REALTIME_NOTIFICATIONS.md) for Laravel Echo + Pusher setup and event flow.

## Video Consultations

See [VIDEO_CONSULTATION.md](VIDEO_CONSULTATION.md) for the WebRTC room flow and signaling setup.

## Enterprise SaaS Architecture

See [ENTERPRISE_ARCHITECTURE.md](ENTERPRISE_ARCHITECTURE.md) for tenancy, RBAC, security, realtime, and DevOps notes.

## Docker

```bash
docker compose up --build
```

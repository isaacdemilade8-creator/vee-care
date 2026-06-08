# Real-Time Notifications

This app uses Laravel broadcasting, Pusher Channels, Laravel Echo, and persistent notification rows.

## Flow

1. A backend action happens, such as booking an appointment, approving an appointment, sending a chat message, or issuing a prescription.
2. `App\Services\NotificationService` creates a row in the `notifications` table.
3. The same service broadcasts `App\Events\CareNotificationCreated`.
4. Laravel sends that event to Pusher on `private-users.{id}`.
5. React uses Laravel Echo to subscribe to `private-users.{currentUser.id}`.
6. When `.notification.created` arrives, the frontend updates the React Query notification cache and shows a toast.

## Backend Setup

Set these values in `backend/.env`:

```env
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=your-pusher-app-id
PUSHER_APP_KEY=your-pusher-key
PUSHER_APP_SECRET=your-pusher-secret
PUSHER_APP_CLUSTER=mt1
PUSHER_PORT=443
PUSHER_SCHEME=https
```

For local development without Pusher credentials, keep:

```env
BROADCAST_CONNECTION=log
```

Run:

```bash
cd backend
php artisan config:clear
php artisan migrate
```

## Frontend Setup

Set these values in `frontend/.env`:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000/api
VITE_PUSHER_APP_KEY=your-pusher-key
VITE_PUSHER_APP_CLUSTER=mt1
```

Run:

```bash
cd frontend
npm install
npm run dev
```

## Channels and Events

- Channel: `private-users.{id}`
- Event name: `.notification.created`
- Auth endpoint: `http://127.0.0.1:8000/broadcasting/auth`

The auth endpoint is protected with `auth:sanctum`, so Echo sends the same bearer token used for API calls.

## Notification API

- `GET /api/notifications`
- `GET /api/notifications?unread=1`
- `PATCH /api/notifications/{id}/read`
- `POST /api/notifications/read-all`

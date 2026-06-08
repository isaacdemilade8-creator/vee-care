# Video Consultation

This app uses native browser WebRTC for audio/video and Laravel + Pusher/Echo for signaling.

## How It Works

1. Patient or doctor opens an approved appointment from `/appointments`.
2. The frontend loads `/api/video-consultations/{appointment}` to verify the user belongs to that appointment.
3. The user clicks `Join consultation`, and the browser asks for camera/microphone access.
4. React creates an `RTCPeerConnection` with a public STUN server.
5. Signaling messages are sent to Laravel:
   - `ready`
   - `offer`
   - `answer`
   - `ice-candidate`
   - `leave`
6. Laravel broadcasts those messages over the private channel `private-video.appointments.{appointmentId}`.
7. The other browser receives the signal through Laravel Echo and completes the peer-to-peer WebRTC connection.

## Backend Routes

- `GET /api/video-consultations/{appointment}` - load/authorize the room.
- `POST /api/video-consultations/{appointment}/signal` - relay WebRTC signaling payloads.

## Broadcast Channel

- `video.appointments.{appointmentId}`

Only the appointment patient and doctor can subscribe.

## Required Realtime Config

Video signaling needs a live broadcaster. Use Pusher locally or in production:

```env
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=your-pusher-app-id
PUSHER_APP_KEY=your-pusher-key
PUSHER_APP_SECRET=your-pusher-secret
PUSHER_APP_CLUSTER=mt1
PUSHER_PORT=443
PUSHER_SCHEME=https
```

Frontend:

```env
VITE_PUSHER_APP_KEY=your-pusher-key
VITE_PUSHER_APP_CLUSTER=mt1
```

Then run:

```bash
cd backend
php artisan config:clear
php artisan serve

cd ../frontend
npm run dev
```

## Notes

- This is a simple one-to-one consultation room.
- It uses STUN only. For production reliability across strict networks, add a TURN server.
- Recording is not implemented. Medical apps should treat recording as a separate compliance feature with explicit consent.

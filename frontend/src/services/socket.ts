import { io, Socket } from 'socket.io-client';
import { TOKEN_KEY } from './api';

let socket: Socket | null = null;

export function getSocketClient() {
  const url = import.meta.env.VITE_SOCKET_URL;

  if (!url) {
    return null;
  }

  if (socket) {
    return socket;
  }

  socket = io(url, {
    transports: ['websocket'],
    auth: { token: localStorage.getItem(TOKEN_KEY) },
  });

  return socket;
}

export function disconnectSocket() {
  socket?.disconnect();
  socket = null;
}

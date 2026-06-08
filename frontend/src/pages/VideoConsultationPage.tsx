import { Mic, MicOff, PhoneOff, Video, VideoOff } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { endpoints } from '../services/endpoints';
import { getEchoClient } from '../services/echo';
import type { Appointment, VideoSignal } from '../types';
import styles from './VideoConsultationPage.module.scss';

const rtcConfig: RTCConfiguration = {
  iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
};

export function VideoConsultationPage() {
  const { appointmentId } = useParams();
  const id = Number(appointmentId);
  const { user } = useAuth();
  const localVideo = useRef<HTMLVideoElement>(null);
  const remoteVideo = useRef<HTMLVideoElement>(null);
  const localStream = useRef<MediaStream | null>(null);
  const peer = useRef<RTCPeerConnection | null>(null);
  const [appointment, setAppointment] = useState<Appointment | null>(null);
  const [status, setStatus] = useState('Preparing consultation...');
  const [cameraOn, setCameraOn] = useState(true);
  const [micOn, setMicOn] = useState(true);
  const [joined, setJoined] = useState(false);

  const sendSignal = useCallback((type: VideoSignal['type'], payload: Record<string, unknown> = {}) => {
    if (!id) {
      return;
    }

    endpoints.sendVideoSignal(id, { type, payload }).catch(() => {
      setStatus('Could not send signaling data.');
    });
  }, [id]);

  const createPeer = useCallback(() => {
    if (peer.current) {
      return peer.current;
    }

    const connection = new RTCPeerConnection(rtcConfig);
    peer.current = connection;

    localStream.current?.getTracks().forEach((track) => {
      connection.addTrack(track, localStream.current as MediaStream);
    });

    connection.ontrack = (event) => {
      const [stream] = event.streams;
      if (remoteVideo.current && stream) {
        remoteVideo.current.srcObject = stream;
        setStatus('Connected');
      }
    };

    connection.onicecandidate = (event) => {
      if (event.candidate) {
        sendSignal('ice-candidate', { candidate: event.candidate.toJSON() });
      }
    };

    connection.onconnectionstatechange = () => {
      setStatus(connection.connectionState === 'connected' ? 'Connected' : `Connection ${connection.connectionState}`);
    };

    return connection;
  }, [sendSignal]);

  const createOffer = useCallback(async () => {
    const connection = createPeer();
    const offer = await connection.createOffer();
    await connection.setLocalDescription(offer);
    sendSignal('offer', { description: offer });
    setStatus('Calling participant...');
  }, [createPeer, sendSignal]);

  const handleSignal = useCallback(async (signal: VideoSignal) => {
    if (!user || signal.fromUserId === user.id) {
      return;
    }

    const connection = createPeer();

    if (signal.type === 'ready' && user.role === 'patient') {
      await createOffer();
      return;
    }

    if (signal.type === 'offer') {
      await connection.setRemoteDescription(signal.payload?.description as RTCSessionDescriptionInit);
      const answer = await connection.createAnswer();
      await connection.setLocalDescription(answer);
      sendSignal('answer', { description: answer });
      setStatus('Answer sent');
      return;
    }

    if (signal.type === 'answer') {
      await connection.setRemoteDescription(signal.payload?.description as RTCSessionDescriptionInit);
      setStatus('Connecting...');
      return;
    }

    if (signal.type === 'ice-candidate' && signal.payload?.candidate) {
      await connection.addIceCandidate(new RTCIceCandidate(signal.payload.candidate as RTCIceCandidateInit));
      return;
    }

    if (signal.type === 'leave') {
      setStatus('The other participant left.');
      if (remoteVideo.current) {
        remoteVideo.current.srcObject = null;
      }
    }
  }, [createOffer, createPeer, sendSignal, user]);

  const join = useCallback(async () => {
    setStatus('Requesting camera and microphone...');
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    localStream.current = stream;

    if (localVideo.current) {
      localVideo.current.srcObject = stream;
    }

    createPeer();
    setJoined(true);
    setStatus('Waiting for participant...');
    sendSignal('ready');

    if (user?.role === 'patient') {
      window.setTimeout(() => void createOffer(), 900);
    }
  }, [createOffer, createPeer, sendSignal, user?.role]);

  useEffect(() => {
    endpoints.videoConsultation(id).then((response) => {
      setAppointment(response.data);
      setStatus('Ready to join');
    }).catch(() => setStatus('Unable to load this consultation.'));
  }, [id]);

  useEffect(() => {
    if (!id || !user) {
      return;
    }

    const echo = getEchoClient();
    if (!echo) {
      setStatus('Add Pusher keys to enable real-time video signaling.');
      return;
    }

    const channelName = `video.appointments.${id}`;
    const channel = echo.private(channelName);
    channel.listen('.video.signal', (signal: VideoSignal) => {
      void handleSignal(signal);
    });

    return () => {
      echo.leave(channelName);
    };
  }, [handleSignal, id, user]);

  useEffect(() => () => {
    sendSignal('leave');
    peer.current?.close();
    localStream.current?.getTracks().forEach((track) => track.stop());
  }, [sendSignal]);

  const toggleCamera = () => {
    localStream.current?.getVideoTracks().forEach((track) => {
      track.enabled = !track.enabled;
      setCameraOn(track.enabled);
    });
  };

  const toggleMic = () => {
    localStream.current?.getAudioTracks().forEach((track) => {
      track.enabled = !track.enabled;
      setMicOn(track.enabled);
    });
  };

  if (!appointment) {
    return <SkeletonRows rows={3} />;
  }

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div>
          <p>{appointment.reason}</p>
          <h2>{appointment.patient?.name} with {appointment.doctor?.name}</h2>
          <span>{status}</span>
        </div>
        <Link to="/appointments">
          <Button variant="ghost">Back</Button>
        </Link>
      </header>

      <section className={styles.stage}>
        <video ref={remoteVideo} autoPlay playsInline className={styles.remote} />
        <video ref={localVideo} autoPlay muted playsInline className={styles.local} />
        {!joined ? (
          <div className={styles.join}>
            <Card>
              <h3>Video consultation</h3>
              <p>Join with camera and microphone. The room is private to this appointment.</p>
              <Button onClick={() => void join()}>Join consultation</Button>
            </Card>
          </div>
        ) : null}
      </section>

      <div className={styles.controls}>
        <Button variant="secondary" onClick={toggleMic}>{micOn ? <Mic size={18} /> : <MicOff size={18} />} Mic</Button>
        <Button variant="secondary" onClick={toggleCamera}>{cameraOn ? <Video size={18} /> : <VideoOff size={18} />} Camera</Button>
        <Link to="/appointments">
          <Button variant="ghost" onClick={() => sendSignal('leave')}><PhoneOff size={18} /> Leave</Button>
        </Link>
      </div>
    </div>
  );
}

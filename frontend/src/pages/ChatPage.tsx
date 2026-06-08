import { MessageCircle, Search, Send, UserRound } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { Button } from '../components/Button';
import { Card } from '../components/Card';
import { SkeletonRows } from '../components/Skeleton';
import { useAuth } from '../context/AuthContext';
import { useApiMutation, useChatContacts, useProfile, useThread } from '../hooks/useApi';
import { getEchoClient } from '../services/echo';
import { endpoints } from '../services/endpoints';
import type { Message, Paginated } from '../types';
import styles from './ChatPage.module.scss';

export function ChatPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const { userId } = useParams();
  const [searchParams] = useSearchParams();
  const contacts = useChatContacts();
  const parsedDirectUserId = Number(userId ?? searchParams.get('user'));
  const directUserId = Number.isFinite(parsedDirectUserId) && parsedDirectUserId > 0 ? parsedDirectUserId : undefined;
  const [activeId, setActiveId] = useState<number | undefined>(
    directUserId,
  );
  const [contactSearch, setContactSearch] = useState('');
  const [body, setBody] = useState('');
  const [typingName, setTypingName] = useState('');
  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const typingTimeoutRef = useRef<number | undefined>(undefined);
  const lastTypingWhisperRef = useRef(0);
  const thread = useThread(activeId);
  const directProfile = useProfile(activeId);
  const send = useApiMutation((payload: { receiver_id: number; body: string }) => endpoints.sendMessage(payload), ['thread', activeId], 'Message sent');

  const active = contacts.data?.data.find((contact) => contact.id === activeId) ?? directProfile.data;
  const contactRows = useMemo(() => {
    const query = contactSearch.trim().toLowerCase();

    return (contacts.data?.data ?? []).filter((contact) => {
      const haystack = `${contact.name} ${contact.role} ${contact.specialty ?? ''}`.toLowerCase();
      return !query || haystack.includes(query);
    });
  }, [contactSearch, contacts.data?.data]);
  const messageRows = thread.data?.data.slice().reverse() ?? [];
  const chatChannelName = user && activeId ? `chat.${Math.min(user.id, activeId)}.${Math.max(user.id, activeId)}` : '';

  useEffect(() => {
    if (directUserId) {
      setActiveId(directUserId);
    }
  }, [directUserId]);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }, [messageRows.length, activeId, typingName]);

  useEffect(() => {
    if (!user || !activeId || !chatChannelName) {
      return;
    }

    const echo = getEchoClient();

    if (!echo) {
      return;
    }

    const channel = echo.private(chatChannelName) as unknown as {
      listen: (event: string, callback: (message: Message) => void) => void;
      listenForWhisper: (event: string, callback: (payload: { userId: number; name: string; isTyping: boolean }) => void) => void;
    };

    channel.listen('.message.sent', (message) => {
      const isCurrentThread = [message.sender.id, message.receiver.id].includes(user.id)
        && [message.sender.id, message.receiver.id].includes(activeId);

      if (!isCurrentThread) {
        return;
      }

      queryClient.setQueryData<Paginated<Message>>(['thread', activeId], (current) => {
        if (!current) {
          return { data: [message] };
        }

        return {
          ...current,
          data: [message, ...current.data.filter((item) => item.id !== message.id)],
          meta: current.meta ? { ...current.meta, total: current.meta.total + 1 } : current.meta,
        };
      });

      if (message.sender.id === activeId) {
        setTypingName('');
      }
    });

    channel.listenForWhisper('typing', (payload) => {
      if (payload.userId !== activeId) {
        return;
      }

      window.clearTimeout(typingTimeoutRef.current);
      setTypingName(payload.isTyping ? payload.name : '');

      if (payload.isTyping) {
        typingTimeoutRef.current = window.setTimeout(() => setTypingName(''), 2500);
      }
    });

    return () => {
      window.clearTimeout(typingTimeoutRef.current);
      setTypingName('');
      echo.leave(chatChannelName);
    };
  }, [activeId, chatChannelName, queryClient, user]);

  useEffect(() => {
    if (!user || !activeId || !chatChannelName) {
      return;
    }

    const echo = getEchoClient();

    if (!echo) {
      return;
    }

    const now = Date.now();
    const shouldSend = !body.trim() || now - lastTypingWhisperRef.current > 900;

    if (!shouldSend) {
      return;
    }

    lastTypingWhisperRef.current = now;
    (echo.private(chatChannelName) as unknown as { whisper: (event: string, payload: unknown) => void }).whisper('typing', {
      userId: user.id,
      name: user.name,
      isTyping: Boolean(body.trim()),
    });
  }, [activeId, body, chatChannelName, user]);

  return (
    <div className={styles.chat}>
      <Card className={styles.contacts}>
        <div className={styles.contactsHeader}>
          <div>
            <span>Messages</span>
            <h2>Contacts</h2>
          </div>
          <MessageCircle size={22} />
        </div>
        <label className={styles.searchBox}>
          <Search size={16} />
          <input value={contactSearch} onChange={(event) => setContactSearch(event.target.value)} placeholder="Search contacts" />
        </label>
        {contacts.isLoading ? <SkeletonRows rows={3} /> : contactRows.map((contact) => (
          <button key={contact.id} className={contact.id === activeId ? styles.active : ''} onClick={() => setActiveId(contact.id)}>
            <span className={styles.avatar}>{contact.avatarUrl ? <img src={contact.avatarUrl} alt="" /> : <UserRound size={18} />}</span>
            <span className={styles.contactCopy}>
              <strong>{contact.name}</strong>
              <small>{contact.specialty || contact.role.replace('_', ' ')}</small>
            </span>
          </button>
        ))}
        {active && !contacts.data?.data.some((contact) => contact.id === active.id) ? (
          <button className={styles.active} onClick={() => setActiveId(active.id)}>
            <span className={styles.avatar}>{active.avatarUrl ? <img src={active.avatarUrl} alt="" /> : <UserRound size={18} />}</span>
            <span className={styles.contactCopy}>
              <strong>{active.name}</strong>
              <small>{active.specialty || active.role.replace('_', ' ')}</small>
            </span>
          </button>
        ) : null}
        {!contacts.isLoading && !contactRows.length && !active ? (
          <div className={styles.emptyContacts}>
            <MessageCircle size={22} />
            <p>No contacts found.</p>
          </div>
        ) : null}
      </Card>
      <Card className={styles.thread}>
        <div className={styles.threadHeader}>
          <div className={styles.threadIdentity}>
            <span className={styles.avatar}>{active?.avatarUrl ? <img src={active.avatarUrl} alt="" /> : <UserRound size={19} />}</span>
            <div>
              <h2>{active ? active.name : activeId ? 'Direct message' : 'Select a contact'}</h2>
              <p>{active ? active.specialty || active.role.replace('_', ' ') : 'Choose someone to start a conversation'}</p>
            </div>
          </div>
          {activeId ? <Link to={`/profiles/${activeId}`}>View profile</Link> : null}
        </div>
        <div className={styles.messages}>
          {thread.isLoading && activeId ? <SkeletonRows rows={4} /> : null}
          {!thread.isLoading && activeId && !messageRows.length ? (
            <div className={styles.emptyThread}>
              <MessageCircle size={28} />
              <h3>No messages yet</h3>
              <p>Send the first message to start this conversation.</p>
            </div>
          ) : null}
          {!activeId ? (
            <div className={styles.emptyThread}>
              <MessageCircle size={28} />
              <h3>Select a contact</h3>
              <p>Your conversation will open here.</p>
            </div>
          ) : null}
          {messageRows.map((message) => (
            <article key={message.id} className={message.sender.id === user?.id ? styles.mine : ''}>
              <span>{message.sender.name}</span>
              <p>{message.body}</p>
              <small>{new Date(message.createdAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small>
            </article>
          ))}
          {typingName ? (
            <div className={styles.typingIndicator}>
              <span>{typingName} is typing</span>
              <i />
              <i />
              <i />
            </div>
          ) : null}
          <div ref={messagesEndRef} />
        </div>
        {activeId ? (
          <form onSubmit={(event) => {
            event.preventDefault();
            if (body.trim()) {
              send.mutate({ receiver_id: activeId, body: body.trim() }, { onSuccess: () => setBody('') });
            }
          }}>
            <input value={body} onChange={(event) => setBody(event.target.value)} placeholder="Write a message" />
            <Button aria-label="Send message" disabled={send.isPending || !body.trim()}><Send size={18} /></Button>
          </form>
        ) : null}
      </Card>
    </div>
  );
}

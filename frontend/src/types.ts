export interface EventRecord {
  id: number;
  name: string;
  description: string | null;
  start_date: string;
  end_date: string;
}
export interface SessionRecord {
  id: number;
  event_id: number;
  event_name: string;
  name: string;
  start_date: string;
  end_date: string;
}
export interface SubscriptionRecord {
  id: number;
  person_id: number;
  fullname: string;
  email: string;
  phone: string;
  active: number;
  datetime: string;
}
export interface AttendanceRecord {
  id: number;
  person_id: number;
  fullname: string;
  event_name: string;
  session_name: string;
  event_session_id: number;
  log_time: string;
}
export interface AppData {
  events: EventRecord[];
  sessions: SessionRecord[];
  subscriptions: SubscriptionRecord[];
  attendance: AttendanceRecord[];
}
export type Page = 'inicio' | 'eventos' | 'sesiones' | 'participantes' | 'asistencia' | 'informes';
export type FormKind = 'event' | 'session' | 'subscription' | 'attendance' | 'upload';
export interface Editor { kind: FormKind; id?: number }

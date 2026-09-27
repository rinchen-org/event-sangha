import { ArrowUpRight, CalendarDays, Search, Sprout } from 'lucide-react';
import type { ReactNode } from 'react';
import type { EventRecord } from '../types';
import { eventStatus, formatDate } from '../data';

export function Empty({ title = 'Aún no hay registros', children }: { title?: string; children?: ReactNode }) {
  return <div className="empty"><span className="empty-icon"><Sprout size={27} strokeWidth={1.3} /></span><h3>{title}</h3><p>{children || 'Los nuevos registros aparecerán aquí.'}</p></div>;
}
export function SearchInput({ value, onChange, placeholder = 'Buscar…' }: { value: string; onChange: (s: string) => void; placeholder?: string }) {
  return <div className="search-field"><Search size={18} /><input aria-label={placeholder} placeholder={placeholder} value={value} onChange={e => onChange(e.target.value)} />{value && <button onClick={() => onChange('')} aria-label="Limpiar búsqueda">×</button>}</div>;
}
export function SectionTitle({ title, subtitle, children }: { title: string; subtitle?: string; children?: ReactNode }) {
  return <div className="section-title"><div><h2>{title}</h2>{subtitle && <p>{subtitle}</p>}</div>{children}</div>;
}
export function EventCard({ event, sessions, onSelect, index = 0 }: { event: EventRecord; sessions: number; onSelect: () => void; index?: number }) {
  const status = eventStatus(event.start_date, event.end_date);
  return <button className={`event-card art-${index % 3}`} onClick={onSelect}>
    <div className="event-art" aria-hidden="true"><div className="art-sun" /><div className="art-hill hill-back" /><div className="art-hill hill-front" /><span className="art-caption">SAKYA RINCHEN LING</span></div>
    <div className="event-body"><span className={`badge ${status.className}`}>{status.label}</span><h3>{event.name}</h3><p>{event.description || 'Un espacio de práctica y encuentro en comunidad.'}</p><div className="event-meta"><span><CalendarDays size={15} />{formatDate(event.start_date, { day: 'numeric', month: 'short' })}</span><span>{sessions} {sessions === 1 ? 'sesión' : 'sesiones'}</span><ArrowUpRight size={18} /></div></div>
  </button>;
}

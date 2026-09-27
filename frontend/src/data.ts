import type { AppData } from './types';

export const emptyData: AppData = { events: [], sessions: [], subscriptions: [], attendance: [] };
export const preview = import.meta.env.DEV && new URLSearchParams(location.search).get('preview') === '1';
export const templatesUrl = import.meta.env.DEV
  ? new URL('/templates/', location.origin)
  : new URL('./', location.href);
export const legacyUrl = (path: string) => new URL(path, templatesUrl).href;

export async function loadData(signal: AbortSignal): Promise<AppData> {
  if (preview) return (await import('./preview')).previewData;
  const endpoints = {
    events: 'event/list.php', sessions: 'event-session/list.php',
    subscriptions: 'subscription/list.php', attendance: 'attendance/list.php',
  };
  const results = await Promise.all(Object.entries(endpoints).map(async ([key, path]) => {
    const response = await fetch(legacyUrl(`${path}?format=json`), { signal, credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok) throw new Error('No pudimos cargar los datos. Comprueba la conexión y vuelve a intentarlo.');
    const body = await response.json();
    if (!body.success || !Array.isArray(body.data)) throw new Error('La respuesta del servidor no es válida. Inténtalo de nuevo.');
    return [key, body.data];
  }));
  return Object.fromEntries(results) as unknown as AppData;
}

// SQLite stores these timestamps in UTC; all UI dates use the project's timezone.
export function dateValue(value: string) {
  return new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
}
export function formatDate(value: string, options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }) {
  const date = dateValue(value);
  return Number.isNaN(date.getTime()) ? 'Sin fecha' : new Intl.DateTimeFormat('es-BO', { ...options, timeZone: 'America/La_Paz' }).format(date);
}
export function inputDate(value: string, withTime = false) {
  const parts = new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'America/La_Paz', year: 'numeric', month: '2-digit', day: '2-digit',
    ...(withTime ? { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' as const } : {}),
  }).formatToParts(dateValue(value));
  const get = (part: string) => parts.find(p => p.type === part)?.value;
  return `${get('year')}-${get('month')}-${get('day')}${withTime ? `T${get('hour')}:${get('minute')}` : ''}`;
}
export function eventStatus(start: string, end: string, now = Date.now()) {
  if (dateValue(end).getTime() < now) return { label: 'Finalizado', className: 'neutral' };
  if (dateValue(start).getTime() > now) return { label: 'Próximamente', className: 'upcoming' };
  return { label: 'En curso', className: 'active' };
}
export function initials(name: string) {
  return name.trim().split(/\s+/).slice(0, 2).map(n => n[0]).join('').toUpperCase();
}
export function matchesSearch(values: unknown[], query: string) {
  const normalize = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
  return normalize(values.join(' ')).includes(normalize(query.trim()));
}
export function csvContent(headers: string[], rows: unknown[][]) {
  const escape = (value: unknown) => {
    let text = String(value ?? '');
    // Prevent spreadsheet formulas in exported participant-supplied values.
    if (/^[\s]*[=+\-@]/.test(text)) text = `'${text}`;
    return `"${text.replaceAll('"', '""')}"`;
  };
  return '\uFEFF' + [headers, ...rows].map(row => row.map(escape).join(',')).join('\r\n');
}
export function downloadCsv(filename: string, headers: string[], rows: unknown[][]) {
  const url = URL.createObjectURL(new Blob([csvContent(headers, rows)], { type: 'text/csv;charset=utf-8;' }));
  const anchor = document.createElement('a');
  anchor.href = url; anchor.download = filename; anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

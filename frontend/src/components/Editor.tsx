import { useEffect, useRef, useState } from 'react';
import { ArrowRight, CalendarDays, Check, Upload, X } from 'lucide-react';
import type { AppData, Editor as EditorType } from '../types';
import { inputDate, legacyUrl, preview } from '../data';

const labels = { event: 'Nuevo evento', session: 'Nueva sesión', subscription: 'Nueva inscripción', attendance: 'Registrar asistencia', upload: 'Importar participantes' };

export function Editor({ editor, data, close }: { editor: EditorType; data: AppData; close: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const event = data.events.find(e => e.id === editor.id);
  const session = data.sessions.find(e => e.id === editor.id);
  const [eventId, setEventId] = useState(String(session?.event_id || data.events[0]?.id || ''));
  const [startDate, setStartDate] = useState(editor.kind === 'event' && event ? inputDate(event.start_date) : editor.kind === 'session' && session ? inputDate(session.start_date, true) : '');
  const [force, setForce] = useState(false);
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    dialog.current?.showModal();
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = overflow; previous?.focus(); };
  }, []);
  const action = {
    event: `event/form.php${editor.id ? `?id=${editor.id}` : ''}`,
    session: 'event-session/form.php', subscription: 'subscription/form.php',
    attendance: 'attendance/form.php', upload: 'subscription/upload.php',
  }[editor.kind];
  const title = editor.id ? (editor.kind === 'event' ? 'Editar evento' : 'Editar sesión') : labels[editor.kind];
  return <dialog ref={dialog} className="editor" aria-labelledby="editor-title" onCancel={close} onClick={e => { if (e.target === dialog.current) close(); }}>
    <div className="editor-inner">
      <div className="editor-top"><span className="eyebrow">UN ESPACIO PARA CONECTAR</span><button className="icon-button" aria-label="Cerrar formulario" onClick={close}><X size={20} /></button></div>
      <h2 id="editor-title">{title}</h2>
      <p className="muted">{editor.kind === 'upload' ? 'Añade inscripciones con el formato CSV de Google Forms que utiliza el centro.' : 'Cada encuentro comienza con un pequeño paso.'}</p>
      <form action={legacyUrl(action)} method="POST" encType={editor.kind === 'upload' ? 'multipart/form-data' : undefined} onSubmit={e => {
        if (preview) { e.preventDefault(); setMessage('Esta es una vista previa. No se han guardado datos.'); return; }
        if (busy) { e.preventDefault(); return; }
        setBusy(true);
      }}>
        {editor.kind === 'subscription' && <>
          <label>Nombre completo<input autoComplete="name" name="fullname" required placeholder="Nombre y apellidos" /></label>
          <label>Correo electrónico<input autoComplete="email" type="email" name="email" required placeholder="nombre@ejemplo.com" /></label>
          <label>Teléfono<input autoComplete="tel" type="tel" name="phone" required placeholder="Número de contacto" /></label>
          <p className="form-note">La inscripción utiliza el proceso de confirmación y envío de QR del centro.</p>
        </>}
        {(editor.kind === 'event' || editor.kind === 'session') && <>
          {editor.kind === 'session' && <label>Evento<select name="event_id" required value={eventId} onChange={e => setEventId(e.target.value)}><option value="" disabled>Selecciona un evento</option>{data.events.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}</select></label>}
          <label>{editor.kind === 'event' ? 'Nombre del evento' : 'Nombre de la sesión'}<input name="name" required defaultValue={editor.kind === 'event' ? event?.name : session?.name} placeholder="Un nuevo encuentro" /></label>
          {editor.kind === 'event' && <label>Descripción<textarea name="description" rows={4} defaultValue={event?.description || ''} placeholder="¿Qué compartiremos en este encuentro?" /></label>}
          <div className="form-grid"><label>Inicio<input type={editor.kind === 'event' ? 'date' : 'datetime-local'} name={editor.kind === 'event' ? 'start_date' : 'start_datetime'} required value={startDate} onChange={e => setStartDate(e.target.value)} /></label>
            <label>Fin<input type={editor.kind === 'event' ? 'date' : 'datetime-local'} name={editor.kind === 'event' ? 'end_date' : 'end_datetime'} min={startDate || undefined} required defaultValue={editor.kind === 'event' && event ? inputDate(event.end_date) : editor.kind === 'session' && session ? inputDate(session.end_date, true) : ''} /></label></div>
          {editor.kind === 'session' && <input type="hidden" name="event_session_id" value={editor.id || ''} />}
          <p className="form-note"><CalendarDays size={15} /> Fechas y horas de Bolivia · America/La_Paz</p>
        </>}
        {editor.kind === 'attendance' && <>
          <label>Participante<select name="person" required defaultValue=""><option value="" disabled>Selecciona una inscripción activa</option>{data.subscriptions.filter(s => s.active === 1).map(s => <option value={s.person_id} key={s.id}>{s.fullname} · {s.email}</option>)}</select></label>
          <label>Evento<select name="event" required value={eventId} onChange={e => setEventId(e.target.value)}><option value="" disabled>Selecciona un evento</option>{data.events.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}</select></label>
          <label>Sesión<select key={eventId} name="event_session" required defaultValue=""><option value="" disabled>Selecciona una sesión</option>{data.sessions.filter(s => String(s.event_id) === eventId).map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
          <input type="hidden" name="force" value={force ? '1' : '0'} />
          <label className="checkbox-label"><input type="checkbox" checked={force} onChange={e => setForce(e.target.checked)} /> Registrar como excepción</label>
          <p className="form-note">{force ? 'La excepción omite las comprobaciones habituales de asistencia. Úsala solo si corresponde.' : 'Se comprobarán el horario y los requisitos de asistencia de la sesión.'}</p>
        </>}
        {editor.kind === 'upload' && <label className="upload-zone"><Upload size={30} /><strong>Selecciona tu archivo CSV</strong><span>Utiliza las mismas columnas que en las importaciones anteriores.</span><input name="csv_file" type="file" accept=".csv,text/csv" required /></label>}
        {message && <p role="status" className="inline-message"><Check size={18} />{message}</p>}
        <div className="form-actions"><button type="button" className="button secondary" onClick={close}>Cancelar</button><button className="button primary" disabled={busy}>{busy ? 'Enviando…' : editor.kind === 'upload' ? 'Importar archivo' : 'Guardar y continuar'}<ArrowRight size={17} /></button></div>
      </form>
    </div>
  </dialog>;
}

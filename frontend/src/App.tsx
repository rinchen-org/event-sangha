import { TestEmailSender } from './components/TestEmailSender';
import { ParticipantQr } from './components/ParticipantQr';
import { useCallback, useEffect, useRef, useState } from 'react';
import { LogOut, ArrowDownToLine, ArrowRight, ArrowUpRight, CalendarDays, Check, ChevronLeft, ChevronRight, CircleHelp, Clock3, Flower2, LayoutDashboard, ListChecks, Menu, Plus, Printer, RefreshCw, Sprout, Upload, Users, X, ChartNoAxesCombined } from 'lucide-react';
import type { AppData, EventDetailData, EventRecord, Editor as EditorType, Page, SubscriptionRecord } from './types';
import { AuthenticationError, getCsrfToken, loginUrl, dateValue, downloadCsv, emptyData, eventStatus, formatDate, initials, legacyUrl, loadData, loadEventData, loadEventOptions, matchesSearch, preview, readEntryNotice } from './data';
import { Editor } from './components/Editor';
import { Empty, EventCard, SearchInput, SectionTitle } from './components/Shared';
import logo from '../../src/static/images/logo-head-square.png';

const navigation = [
  { id: 'inicio', label: 'Vista general', icon: LayoutDashboard },
  { id: 'eventos', label: 'Eventos', icon: CalendarDays },
  { id: 'sesiones', label: 'Sesiones', icon: Clock3 },
  { id: 'participantes', label: 'Participantes', icon: Users },
  { id: 'asistencia', label: 'Asistencia', icon: ListChecks },
  { id: 'informes', label: 'Informes', icon: ChartNoAxesCombined },
] as const;
const titles: Record<Page, [string, string]> = {
  inicio: ['Todo comienza con un encuentro.', 'Un espacio para organizar, conectar y compartir.'],
  eventos: ['Encuentros que nos acercan.', 'Organiza los espacios de práctica de nuestra comunidad.'],
  sesiones: ['Tiempo para estar presentes.', 'Cada sesión, en su lugar. Cada detalle, con atención.'],
  participantes: ['Una comunidad, muchas historias.', 'Acompaña a cada persona desde su primera inscripción.'],
  asistencia: ['Bienvenidos al encuentro.', 'Gestiona la llegada y la participación en cada sesión.'],
  informes: ['Una mirada a lo compartido.', 'Consulta la participación y prepara tus informes.'],
};
function currentPage(): Page {
  if (/^#eventos\/[^/]+(?:\/participantes)?$/.test(location.hash)) return 'participantes';
  const id = location.hash.slice(1).split('/')[0];
  return navigation.some(n => n.id === id) ? id as Page : 'inicio';
}

export function App() {
  const [page, setPage] = useState<Page>(currentPage);
  const [route, setRoute] = useState(location.hash);
  const participantAlias = route.match(/^#eventos\/([^/]+)(?:\/participantes)?$/);
  const participantRoute = participantAlias ? `#participantes/${participantAlias[1]}` : route;
  const needsGlobalData = page !== 'participantes' || participantRoute === '#participantes/todos';
  const eventRoute = page === 'eventos' && route.startsWith('#eventos/') ? route.slice(9) : null;
  const [mobileOpen, setMobileOpen] = useState(false);
  const [smallScreen, setSmallScreen] = useState(() => window.matchMedia?.('(max-width: 760px)').matches ?? false);
  const sidebar = useRef<HTMLElement>(null);
  const menuButton = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    const query = window.matchMedia?.('(max-width: 760px)');
    if (!query) return;
    const update = () => { setSmallScreen(query.matches); if (!query.matches) setMobileOpen(false); };
    query.addEventListener('change', update);
    return () => query.removeEventListener('change', update);
  }, []);
  const [data, setData] = useState<AppData>(emptyData);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [authRequired, setAuthRequired] = useState(false);
  const [testMode, setTestMode] = useState(false);
  const [sessionToken, setSessionToken] = useState('');
  const onSession = useCallback((enabled: boolean) => { setTestMode(enabled); setSessionToken(getCsrfToken()); }, []);
  const [revision, setRevision] = useState(0);
  const [editor, setEditor] = useState<EditorType | null>(null);
  const [entryNotice] = useState(readEntryNotice);
  const [notice, setNotice] = useState(entryNotice?.text || '');
  const [noticeType, setNoticeType] = useState(entryNotice?.type || 'info');
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    const restore = (event: PageTransitionEvent) => { if (event.persisted && !preview) location.reload(); };
    window.addEventListener('pageshow', restore);
    return () => window.removeEventListener('pageshow', restore);
  }, []);
  useEffect(() => {
    const change = () => { setPage(currentPage()); setRoute(location.hash); setMobileOpen(false); };
    window.addEventListener('hashchange', change);
    return () => window.removeEventListener('hashchange', change);
  }, []);
  useEffect(() => {
    if (participantAlias) {
      history.replaceState(history.state, '', participantRoute);
      setRoute(participantRoute);
    }
  }, [route, participantRoute]);
  useEffect(() => { document.title = `${navigation.find(n => n.id === page)?.label} · Rinchen`; heading.current?.focus(); }, [page]);
  useEffect(() => {
    if (!needsGlobalData) { setLoading(false); setError(''); setAuthRequired(false); return; }
    const controller = new AbortController();
    setLoading(true); setError(''); setAuthRequired(false); setTestMode(false);
    loadData(controller.signal, onSession).then(result => { if (!controller.signal.aborted) setData(result); })
      .catch(err => { if (controller.signal.aborted) return; if (err instanceof AuthenticationError) { setAuthRequired(true); setSessionToken(''); setData(emptyData); setError(err.message); return; } setError(err instanceof Error && err.message.startsWith('No pudimos') ? err.message : 'No pudimos conectar con el centro. Vuelve a intentarlo en un momento.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [revision, needsGlobalData, onSession]);
  useEffect(() => {
    if (!mobileOpen) return;
    sidebar.current?.querySelector<HTMLButtonElement>('button')?.focus();
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { setMobileOpen(false); menuButton.current?.focus(); }
      if (event.key === 'Tab') {
        const items = sidebar.current?.querySelectorAll<HTMLElement>('a[href], button');
        if (!items?.length) return;
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener('keydown', escape);
    return () => document.removeEventListener('keydown', escape);
  }, [mobileOpen]);
  const open = (kind: EditorType['kind'], id?: number) => setEditor({ kind, id });
  const ready = !loading && !error;
  return <div className="app-shell">
    <a className="skip-link" href="#main-content" onClick={e => { e.preventDefault(); (heading.current || document.querySelector<HTMLHeadingElement>('.event-detail h1'))?.focus(); }}>Saltar al contenido</a>
    <aside ref={sidebar} className={`sidebar ${mobileOpen ? 'is-open' : ''}`} inert={smallScreen && !mobileOpen}>
      <button className="icon-button sidebar-close" aria-label="Cerrar navegación" onClick={() => { setMobileOpen(false); menuButton.current?.focus(); }}><X size={20} /></button>
      <a href="#inicio" className="brand" aria-label="Rinchen, inicio"><img src={logo} alt="" /><span>rinchen<span className="brand-subtitle">SAKYA RINCHEN LING</span></span></a>
      <div className="workspace"><span className="workspace-icon"><Flower2 size={19} /></span><div>Espacio de comunidad<small>Gestión de encuentros</small></div><span className="workspace-dot" /></div>
      <div className="nav-label">ORGANIZAR</div>
      {!preview && testMode && !authRequired && <a className="nav-link" href={legacyUrl('testing/index.php')}><ListChecks size={19} /><span>Pruebas · Laboratorio QR</span></a>}
      <nav aria-label="Navegación principal">{navigation.map(({ id, label, icon: Icon }) => <a key={id} href={`#${id}`} onClick={() => setMobileOpen(false)} className={page === id ? 'nav-link selected' : 'nav-link'} aria-current={page === id ? 'page' : undefined}><Icon size={19} strokeWidth={1.65} /><span>{label}</span>{id === 'eventos' && ready && <span className="nav-count">{data.events.length}</span>}</a>)}</nav>
      <div className="sidebar-bottom"><div className="sidebar-note"><Sprout size={24} strokeWidth={1.4} /><p>Pequeños encuentros.<br /><strong>Grandes conexiones.</strong></p><span>Cultivemos comunidad, juntos.</span></div><form action={legacyUrl('logout.php')} method="POST" className="logout-form"><input type="hidden" name="csrf_token" value={sessionToken} /><button className="external-link" type="submit" disabled={preview || !sessionToken}><LogOut size={16} /> Cerrar sesión</button></form><div className="center-signature"><span className="center-avatar">RL</span><div>Sakya Rinchen Ling<small>La Paz, Bolivia</small></div></div></div>
    </aside>
    {mobileOpen && <button tabIndex={-1} className="nav-backdrop" aria-label="Cerrar panel lateral" onClick={() => setMobileOpen(false)} />}
    <div className="main-shell">
      <header className="topbar"><div className="breadcrumb"><button ref={menuButton} className="icon-button mobile-menu" aria-label={mobileOpen ? 'Cerrar menú' : 'Abrir menú'} aria-expanded={mobileOpen} onClick={() => setMobileOpen(!mobileOpen)}>{mobileOpen ? <X size={22} /> : <Menu size={22} />}</button><span>Mi comunidad</span><ChevronRight size={13} /><strong>{navigation.find(n => n.id === page)?.label}</strong></div><div className="topbar-right"><span className="location"><span className="status-dot" /> La Paz, Bolivia</span><button className="icon-button" aria-label="Ayuda" onClick={() => { setNoticeType('info'); setNotice(notice ? '' : 'Organiza eventos y sesiones, gestiona inscripciones y registra la asistencia. Usa la navegación para acceder a las herramientas y los informes.'); }}><CircleHelp size={19} /></button><span className="profile-mark" aria-label="Sakya Rinchen Ling">RL</span></div></header>
      <main id="main-content">
        {preview && <div className="preview-banner"><Sprout size={16} /> Vista previa · Datos de ejemplo · No se guardan cambios</div>}
        {notice && <div className="notice" role={noticeType === 'error' ? 'alert' : 'status'}><p>{noticeType === 'error' ? `No se confirmó la acción: ${notice}` : notice}</p><button className="icon-button" aria-label="Cerrar ayuda" onClick={() => setNotice('')}><X size={18} /></button></div>}
        {eventRoute === null && page !== 'participantes' && <div className="page-heading"><div><span className="eyebrow">{page === 'inicio' ? 'NUESTRA COMUNIDAD' : navigation.find(n => n.id === page)?.label.toUpperCase()}</span><h1 ref={heading} tabIndex={-1}>{titles[page][0]}</h1><p>{titles[page][1]}</p></div><button className="button primary" disabled={!ready} onClick={() => open(page === 'sesiones' ? 'session' : page === 'asistencia' ? 'attendance' : 'event')}><Plus size={17} />{page === 'sesiones' ? 'Nueva sesión' : page === 'asistencia' ? 'Registrar asistencia' : 'Crear evento'}</button></div>}
        {page === 'inicio' && <TestEmailSender />}
        {page === 'inicio' && <section className="panel setup-event-panel"><div><h2>Evento de noviembre de 2026</h2><p>Comprueba la base y las sesiones; completa solo los pasos pendientes.</p></div>{preview ? <button className="button secondary" disabled>Preparar evento 2026</button> : <a className="button secondary" href={legacyUrl('../scripts/setup.php?mode=prepare2026')}><CalendarDays size={17} /> Preparar evento 2026</a>}</section>}
        {page === 'participantes' ? <ParticipantDirectory route={participantRoute} data={data} loading={loading} error={error} authRequired={authRequired} retry={() => setRevision(value => value + 1)} onTestMode={onSession} /> : eventRoute !== null ? <EventDetail key={eventRoute.split('/')[0]} route={eventRoute} /> : loading ? <div className="loading-state" role="status"><RefreshCw className="spin" size={23} /><span>Preparando tu espacio…</span></div> : error ? <div className="connection-state" role="alert"><CircleHelp size={34} strokeWidth={1.2} /><h2>{authRequired ? 'Inicia sesión para continuar' : 'Volvamos a conectar'}</h2><p>{error}</p>{authRequired ? <a className="button primary" href={loginUrl()}>Iniciar sesión</a> : <button className="button primary" onClick={() => setRevision(r => r + 1)}><RefreshCw size={16} /> Volver a intentar</button>}</div> : <>
          {page === 'inicio' && <>
            <section className="stats event-overview-stats" aria-label="Resumen de los eventos">{[
              { title: 'Eventos', value: data.events.length, note: `${data.events.filter(e => eventStatus(e.start_date, e.end_date).className !== 'neutral').length} próximos o en curso`, icon: CalendarDays, color: 'rose' },
              { title: 'Sesiones', value: data.sessions.length, note: 'Agenda de los encuentros', icon: Clock3, color: 'sage' },
            ].map(({ title, value, note, icon: Icon, color }) => <div className="stat" key={title}><div className="stat-top"><span>{title}</span><span className={`stat-icon ${color}`}><Icon size={19} strokeWidth={1.6} /></span></div><strong>{value.toLocaleString('es-BO')}</strong><small>{note}</small></div>)}</section>
            <section className="welcome-banner"><div><span className="eyebrow">VOLVER A LO ESENCIAL</span><h2>Hacer espacio para<br /><em>lo que nos une.</em></h2><p>Cada enseñanza, cada práctica, cada encuentro.<br />Todo empieza con nuestra comunidad.</p><a href="#eventos" className="banner-link">Explorar los eventos <ArrowRight size={17} /></a></div><div className="zen-art" aria-hidden="true"><span className="zen-orbit" /><span className="zen-orbit orbit-two" /><span className="zen-sun" /><span className="zen-stone stone-one" /><span className="zen-stone stone-two" /><span className="zen-stone stone-three" /><span className="zen-base" /><Flower2 className="zen-flower" size={100} strokeWidth={0.6} /></div><span className="banner-caption">PRESENCIA · SABIDURÍA · COMPASIÓN</span></section>
            <div className="dashboard-grid"><section><SectionTitle title="Nuestros encuentros" subtitle="Espacios para aprender y compartir."><a className="text-link" href="#eventos">Ver todos <ArrowRight size={16} /></a></SectionTitle><div className="event-grid compact">{[...data.events].sort((a, b) => dateValue(b.start_date).getTime() - dateValue(a.start_date).getTime()).slice(0, 2).map((event, i) => <EventCard key={event.id} event={event} index={i} sessions={data.sessions.filter(s => s.event_id === event.id).length} />)}</div>{!data.events.length && <Empty title="El próximo encuentro empieza aquí">Crea tu primer evento para reunir a la comunidad.</Empty>}</section>
              <section className="agenda-panel"><SectionTitle title="En la agenda"><a className="icon-button" href="#sesiones" aria-label="Ver todas las sesiones"><ArrowUpRight size={18} /></a></SectionTitle><Agenda data={data} open={id => open('session', id)} /><div className="agenda-footer"><Clock3 size={14} /> Hora de Bolivia (GMT−4)</div></section></div>
          </>}
          {page === 'eventos' && <Events data={data} />}
          {page === 'sesiones' && <Sessions data={data} open={id => open('session', id)} />}
          {page === 'asistencia' && <Attendance data={data} />}
          {page === 'informes' && <Reports data={data} />}
        </>}
        <footer className="page-footer"><span><Flower2 size={15} strokeWidth={1.4} /> Hecho para cultivar comunidad.</span><span>Sakya Rinchen Ling · Bolivia</span><span>Versión {__APP_VERSION__}</span><span>Desarrollado por <a href="https://scistitch.com/" target="_blank" rel="noopener noreferrer">SciStitch</a></span></footer>
      </main>
    </div>
    {editor && <Editor editor={editor} data={data} close={() => setEditor(null)} />}
  </div>;
}
function ParticipantDirectory({ route, data, loading, error, authRequired, retry, onTestMode }: {
  route: string; data: AppData; loading: boolean; error: string; authRequired: boolean;
  retry: () => void; onTestMode: (enabled: boolean) => void;
}) {
  const [events, setEvents] = useState<EventRecord[]>([]);
  const [optionsLoading, setOptionsLoading] = useState(true);
  const [optionsError, setOptionsError] = useState('');
  const [optionsAuth, setOptionsAuth] = useState(false);
  const [revision, setRevision] = useState(0);
  const match = route.match(/^#participantes(?:\/([^/]+))?$/);
  const selection = match?.[1] || '';
  const eventId = /^[1-9][0-9]*$/.test(selection) && Number.isSafeInteger(Number(selection)) ? Number(selection) : 0;
  const valid = !!match && (!selection || selection === 'todos' || eventId > 0);
  useEffect(() => {
    const controller = new AbortController();
    setOptionsLoading(true); setOptionsError(''); setOptionsAuth(false);
    loadEventOptions(controller.signal, onTestMode).then(result => { if (!controller.signal.aborted) setEvents(result); })
      .catch(failure => { if (!controller.signal.aborted) { setOptionsError(failure instanceof Error ? failure.message : 'No se pudieron cargar los eventos.'); setOptionsAuth(failure instanceof AuthenticationError); } })
      .finally(() => { if (!controller.signal.aborted) setOptionsLoading(false); });
    return () => controller.abort();
  }, [revision, onTestMode]);
  return <section aria-label="Participantes por evento">
    <div className="page-heading"><div><span className="eyebrow">ORGANIZAR</span><h1>Participantes</h1><p>Selecciona el evento para consultar su lista, vincular inscripciones o importar su CSV.</p></div></div>
    <div className="participant-event-picker panel">
      <label>Evento de participantes<select aria-label="Evento de participantes" value={valid ? selection : ''} disabled={optionsLoading || optionsAuth || !!optionsError}
        onChange={e => { location.hash = e.target.value ? `#participantes/${e.target.value}` : '#participantes'; }}>
        <option value="">Selecciona un evento</option>
        {events.map(event => <option key={event.id} value={event.id}>{event.name}</option>)}
        {eventId > 0 && !events.some(event => event.id === eventId) && <option value={eventId}>Evento #{eventId}</option>}
        <option value="todos">Inscripciones generales (histórico)</option>
      </select></label>
      <p>Importar requiere un evento y nunca envía correos. Una persona puede participar en varios eventos; abrir una lista no crea vínculos ni cambia la admisión por QR.</p>
      {optionsLoading && <small>Cargando selección de eventos…</small>}
      {optionsError && <div role="alert"><p>{optionsError}</p>{optionsAuth ? <a href={loginUrl()}>Iniciar sesión</a> : <button className="button secondary" onClick={() => setRevision(value => value + 1)}>Reintentar selección de eventos</button>}</div>}
    </div>
    {!valid ? <div className="connection-state" role="alert"><h2>El evento no existe.</h2><p>Selecciona un evento válido de la lista.</p></div>
      : optionsAuth ? null : eventId > 0 ? <EventDetail key={eventId} route={`${eventId}/participantes`} />
      : selection === 'todos' ? <>
        <h2>Inscripciones generales (histórico)</h2><p className="event-context-note">Esta vista no equivale a la lista de un evento. Los registros anteriores no se asignan automáticamente. Selecciona un evento para vincular inscripciones existentes o importar un CSV; aquí no se crean inscripciones sin evento.</p>
        {loading ? <p role="status">Cargando inscripciones generales…</p> : error ? <div role="alert"><p>{error}</p>{authRequired ? <a href={loginUrl()}>Iniciar sesión</a> : <button className="button secondary" onClick={retry}>Volver a intentar</button>}</div> : <Participants data={data} />}
      </> : <Empty title={events.length ? 'Elige un evento para ver sus participantes' : optionsLoading || optionsError ? 'Selecciona un evento' : 'No hay eventos disponibles'}>No se ha seleccionado ningún evento ni se ha modificado ningún registro. <a href="#eventos">Ver o crear eventos</a>.</Empty>}
  </section>;
}

function EventDetail({ route }: { route: string }) {
  const [rawId, rawTab] = route.split('/');
  const eventId = /^[1-9][0-9]*$/.test(rawId) && Number.isSafeInteger(Number(rawId)) ? Number(rawId) : 0;
  const tab = ['participantes', 'sesiones', 'asistencia'].includes(rawTab) ? rawTab : 'participantes';
  const [detail, setDetail] = useState<EventDetailData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [authRequired, setAuthRequired] = useState(false);
  const [revision, setRevision] = useState(0);
  const [editor, setEditor] = useState<EditorType | null>(null);
  const title = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    const controller = new AbortController();
    setDetail(null); setError(''); setAuthRequired(false); setLoading(true);
    if (!eventId) { setError('El evento no existe. Vuelve a la lista de eventos.'); setLoading(false); return; }
    loadEventData(eventId, controller.signal).then(result => { if (!controller.signal.aborted) setDetail(result); })
      .catch(failure => { if (!controller.signal.aborted) { setAuthRequired(failure instanceof AuthenticationError); setError(failure instanceof Error ? failure.message : 'No pudimos cargar este evento.'); } })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [eventId, revision]);
  useEffect(() => {
    if (detail) { document.title = `${detail.event.name} · Rinchen`; title.current?.focus(); }
  }, [detail, tab]);
  if (loading) return <div className="loading-state" role="status"><RefreshCw className="spin" size={23} /> Cargando evento…</div>;
  if (error || !detail) return <section className="connection-state" role="alert"><h1>{authRequired ? 'Inicia sesión para continuar' : 'No se pudo abrir el evento'}</h1><p>{error}</p>{authRequired ? <a className="button primary" href={loginUrl()}>Iniciar sesión</a> : <button className="button primary" onClick={() => setRevision(value => value + 1)}>Volver a intentar</button>}<a href="#eventos">Volver a eventos</a></section>;
  const data: AppData = { events: [detail.event], sessions: detail.sessions, subscriptions: detail.subscriptions, attendance: detail.attendance };
  return <section className="event-detail" aria-label="Detalle del evento">
    <a className="text-link" href="#eventos"><ChevronLeft size={16} /> Todos los eventos</a>
    <div className="page-heading"><div><span className="eyebrow">ENCUENTRO</span><h1 ref={title} tabIndex={-1}>{detail.event.name}</h1><p>{detail.event.description}</p><p>{formatDate(detail.event.start_date)} – {formatDate(detail.event.end_date)} · Bolivia</p></div><button className="button secondary" onClick={() => setEditor({ kind: 'event', id: eventId, eventId })}>Editar evento</button></div>
    <div className="toolbar event-detail-tools"><div className="toolbar-actions"><button className="button primary" onClick={() => setEditor({ kind: 'upload', eventId })}><Upload size={17} /> Importar CSV al evento</button>{preview ? <button className="button secondary" disabled>Vincular inscripción existente</button> : <a className="button secondary" href={legacyUrl(`event/register.php?event_id=${eventId}`)}>Vincular inscripción existente</a>}</div><button className="icon-button" aria-label="Actualizar evento" onClick={() => setRevision(value => value + 1)}><RefreshCw size={17} /></button></div>
    <nav className="tabs event-tabs" aria-label="Secciones del evento">{[
      ['participantes', 'Participantes', detail.subscriptions.length], ['sesiones', 'Sesiones', detail.sessions.length], ['asistencia', 'Asistencia', detail.attendance.length],
    ].map(([id, label, count]) => <a key={id} className={tab === id ? 'active' : ''} aria-current={tab === id ? 'page' : undefined} href={id === 'participantes' ? `#participantes/${eventId}` : `#eventos/${eventId}/${id}`}>{label} <span>{count}</span></a>)}</nav>
    {tab === 'participantes' && <><p className="event-context-note">Solo se muestran las inscripciones vinculadas a este evento. Las inscripciones anteriores no se asignan automáticamente: puedes vincularlas explícitamente o importar su CSV. Importar no envía correos: selecciona destinatarios o envía a todos en una acción separada con revisión. El estado mostrado es general; vincular no lo modifica.</p><Participants key={eventId} data={data} eventScoped eventId={eventId} upload={() => setEditor({ kind: 'upload', eventId })} /></>}
    {tab === 'sesiones' && <><div className="toolbar"><h2>Sesiones de este evento</h2><button className="button secondary" onClick={() => setEditor({ kind: 'session', eventId })}><Plus size={17} /> Nueva sesión</button></div><Sessions data={data} open={id => setEditor({ kind: 'session', id, eventId })} /></>}
    {tab === 'asistencia' && <><p className="event-context-note">Asistencias registradas en las sesiones de este evento, incluidas las históricas aunque la persona aún no esté vinculada. Los QR existentes siguen usando el evento configurado para admisión; abrir esta página no cambia esa selección.</p><Attendance data={data} /></>}
    {editor && <Editor editor={editor} data={data} close={() => setEditor(null)} />}
  </section>;
}
function Agenda({ data, open }: { data: AppData; open: (id: number) => void }) {
  const sessions = [...data.sessions].filter(s => dateValue(s.end_date).getTime() >= Date.now()).sort((a, b) => dateValue(a.start_date).getTime() - dateValue(b.start_date).getTime()).slice(0, 3);
  return sessions.length ? <div className="agenda-list">{sessions.map(s => <button className="agenda-item" key={s.id} onClick={() => open(s.id)}><span className="date-tile"><strong>{formatDate(s.start_date, { day: '2-digit' })}</strong><small>{formatDate(s.start_date, { month: 'short' }).replace('.', '')}</small></span><span><strong>{s.name}</strong><small>{formatDate(s.start_date, { hour: '2-digit', minute: '2-digit' })} · {s.event_name}</small></span></button>)}</div> : <Empty title="Tiempo para un nuevo encuentro">Las próximas sesiones aparecerán aquí.</Empty>;
}
function Events({ data }: { data: AppData }) {
  const [search, setSearch] = useState(''); const [filter, setFilter] = useState('all');
  const rows = data.events.filter(e => matchesSearch([e.name, e.description], search) && (filter === 'all' || (filter === 'upcoming' ? eventStatus(e.start_date, e.end_date).className !== 'neutral' : eventStatus(e.start_date, e.end_date).className === 'neutral')));
  return <><div className="toolbar"><div className="tabs" aria-label="Filtrar eventos">{[['all', 'Todos'], ['upcoming', 'Próximos y en curso'], ['past', 'Finalizados']].map(([key, label]) => <button key={key} className={filter === key ? 'active' : ''} aria-pressed={filter === key} onClick={() => setFilter(key)}>{label}</button>)}</div><SearchInput value={search} onChange={setSearch} placeholder="Buscar eventos…" /></div><div className="event-grid">{rows.map((e, i) => <EventCard key={e.id} event={e} index={i} sessions={data.sessions.filter(s => s.event_id === e.id).length} />)}</div>{!rows.length && <Empty title={search ? 'No encontramos ese encuentro' : 'Aún no hay eventos en esta vista'}>Prueba otro filtro o crea un nuevo evento.</Empty>}</>;
}
function Sessions({ data, open }: { data: AppData; open: (id: number) => void }) {
  const [filter, setFilter] = useState('all');
  const rows = [...data.sessions].filter(s => filter === 'all' || String(s.event_id) === filter).sort((a, b) => dateValue(a.start_date).getTime() - dateValue(b.start_date).getTime());
  return <section className="panel"><div className="toolbar panel-toolbar"><h2>Agenda de sesiones</h2><select aria-label="Filtrar por evento" value={filter} onChange={e => setFilter(e.target.value)}><option value="all">Todos los eventos</option>{data.events.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}</select></div>{rows.length ? <div className="session-list">{rows.map(s => <button className="session-row" key={s.id} onClick={() => open(s.id)}><span className="date-tile"><strong>{formatDate(s.start_date, { day: '2-digit' })}</strong><small>{formatDate(s.start_date, { month: 'short' })}</small></span><span className="session-name"><strong>{s.name}</strong><small>{s.event_name}</small></span><span className="session-time"><Clock3 size={15} />{formatDate(s.start_date, { hour: '2-digit', minute: '2-digit' })} – {formatDate(s.end_date, { hour: '2-digit', minute: '2-digit' })}<small>{formatDate(s.start_date)}</small></span><ArrowUpRight size={19} /></button>)}</div> : <Empty title="Tu agenda tiene espacio">Crea una sesión para comenzar.</Empty>}<div className="table-footer">{rows.length} sesiones · Hora de Bolivia</div></section>;
}
function Participants({ data, upload, eventScoped = false, eventId }: { data: AppData; upload?: () => void; eventScoped?: boolean; eventId?: number }) {
  const [search, setSearch] = useState(''); const [filter, setFilter] = useState('all'); const [page, setPage] = useState(0);
  const [pageSize, setPageSize] = useState('10');
  const [emailIds, setEmailIds] = useState<number[]>([]);
  const rows = data.subscriptions.filter(s => matchesSearch([s.fullname, s.email, s.phone], search) && (filter === 'all' || String(s.active) === filter));
  const perPage = pageSize === 'all' ? Math.max(1, rows.length) : Number(pageSize);
  const pages = Math.max(1, Math.ceil(rows.length / perPage));
  const currentPage = Math.min(page, pages - 1);
  const selectedIds = emailIds.filter(id => data.subscriptions.some(s => s.id === id));
  const visibleRows = rows.slice(currentPage * perPage, (currentPage + 1) * perPage);
  const printUrl = legacyUrl(eventScoped ? `subscription/print.php?event_id=${eventId}` : 'subscription/print.php?scope=general');
  const selectEmail = (ids: number[], checked: boolean) => setEmailIds(current => checked ? [...new Set([...current, ...ids])] : current.filter(id => !ids.includes(id)));
  return <section className="panel"><div className="toolbar panel-toolbar"><SearchInput value={search} onChange={s => { setSearch(s); setPage(0); }} placeholder="Buscar nombre, correo o teléfono…" /><div className="toolbar-actions"><select aria-label="Estado de inscripción" value={filter} onChange={e => { setFilter(e.target.value); setPage(0); }}><option value="all">Todos los estados</option><option value="1">Activas</option><option value="0">Inactivas</option></select><button className="button secondary" disabled={!upload} onClick={upload}><Upload size={16} /> Importar CSV</button></div></div>
    <div className="participant-print-tools">
      {preview ? <button className="button secondary" disabled><Printer size={16} /> Imprimir lista completa</button>
        : <a className="button secondary" href={printUrl} target="_blank" rel="noopener noreferrer" aria-label="Imprimir lista completa (nueva pestaña)"><Printer size={16} /> Imprimir lista completa</a>}
      <small>Abre una vista con todos los participantes y sus QR, sin filtros ni paginación. {eventScoped ? 'Solo del evento seleccionado.' : 'Inscripciones generales; no corresponde a un evento.'}</small>
    </div>
    {eventScoped && <div className="event-email-tools">
      <p>Correos: {selectedIds.length} seleccionado(s), incluso en otras páginas o filtros. «Todos» incluye los {data.subscriptions.length} participantes del evento, sin aplicar filtros.</p>
      <form action={legacyUrl('event/email.php')} method="POST" onSubmit={e => { if (preview) e.preventDefault(); }}>
        <input type="hidden" name="csrf_token" value={getCsrfToken()} /><input type="hidden" name="event_id" value={eventId} /><input type="hidden" name="subscription_ids" value={selectedIds.join(',')} />
        <button className="button secondary" name="mode" value="selected" disabled={preview || !selectedIds.length}>Enviar a seleccionados</button>
        <button className="button secondary" name="mode" value="all" disabled={preview || !data.subscriptions.length}>Enviar a todos</button>
      </form>
      {!preview && <a href={legacyUrl(`event/email.php?event_id=${eventId}`)}>Historial de correos</a>}
      <p>Los botones abren una revisión de la plantilla y los destinatarios. No envían hasta confirmar.</p>
    </div>}
    <ParticipantTable rows={visibleRows} eventScoped={eventScoped} eventId={eventId} emailSelection={eventScoped ? { ids: selectedIds, toggle: selectEmail } : undefined} />
    <div className="table-footer"><span aria-live="polite">{rows.length ? `Mostrando ${currentPage * perPage + 1}–${Math.min((currentPage + 1) * perPage, rows.length)} de ${rows.length} participantes` : '0 participantes'}</span>
      <div className="table-footer-controls"><label className="page-size-label">Participantes por página<select value={pageSize} onChange={e => { if (['10', '25', '50', '100', 'all'].includes(e.target.value)) { setPageSize(e.target.value); setPage(0); } }}>
        {[10, 25, 50, 100].map(size => <option key={size} value={size}>{size}</option>)}<option value="all">Todos</option>
      </select></label><div className="pagination"><button className="icon-button" aria-label="Página anterior" disabled={currentPage === 0} onClick={() => setPage(currentPage - 1)}><ChevronLeft size={18} /></button><span>Página {currentPage + 1} de {pages}</span><button className="icon-button" aria-label="Página siguiente" disabled={currentPage + 1 >= pages} onClick={() => setPage(currentPage + 1)}><ChevronRight size={18} /></button></div></div>
    </div></section>;
}
function ParticipantTable({ rows, eventScoped = false, eventId, emailSelection }: { rows: SubscriptionRecord[]; eventScoped?: boolean; eventId?: number; emailSelection?: { ids: number[]; toggle: (ids: number[], checked: boolean) => void } }) {
  const [selected, setSelected] = useState<number | null>(null);
  if (!rows.length) return <Empty title="No hay participantes en esta vista">Las nuevas inscripciones aparecerán aquí. Si estás buscando, prueba otro nombre.</Empty>;
  return <div className="table-scroll" tabIndex={0} role="region" aria-label="Lista de participantes"><table><thead><tr>{emailSelection && <th><input type="checkbox" aria-label="Seleccionar página para correo" disabled={preview} checked={rows.every(s => emailSelection.ids.includes(s.id))} onChange={e => emailSelection.toggle(rows.map(s => s.id), e.target.checked)} /></th>}<th>Participante</th><th>Contacto</th><th>{eventScoped ? 'Vínculo al evento' : 'Inscripción'}</th><th>{eventScoped ? 'Estado general' : 'Estado'}</th><th>QR</th>{!eventScoped && <th><span className="sr-only">Acciones</span></th>}</tr></thead><tbody>{rows.map(s => <tr key={s.id}>{emailSelection && <td><input type="checkbox" aria-label={`Seleccionar a ${s.fullname} para correo`} disabled={preview} checked={emailSelection.ids.includes(s.id)} onChange={e => emailSelection.toggle([s.id], e.target.checked)} /></td>}<td><div className="person"><span className={`person-avatar avatar-${s.id % 4}`}>{initials(s.fullname)}</span><span><strong>{s.fullname}</strong><small>#{String(s.id).padStart(4, '0')}</small></span></div></td><td><span className="cell-email">{s.email}</span><small>{s.phone}</small></td><td>{formatDate(eventScoped ? s.registered_at || s.datetime : s.datetime)}</td><td><span className={`badge ${s.active === 1 ? 'active' : 'neutral'}`}><span className="badge-dot" />{s.active === 1 ? 'Activa' : 'Inactiva'}</span></td><td className="qr-action"><ParticipantQr qr={s.qr_preview} fullname={s.fullname} preview={preview} /><LegacyAction path="subscription/regenerate_qr.php" label="Regenerar QR" fields={{ subscription_id: s.id, ...(eventScoped && eventId ? { event_id: eventId } : {}) }} /></td>{!eventScoped && <td><button className="icon-button" aria-label={`Acciones de ${s.fullname}`} aria-expanded={selected === s.id} onClick={() => setSelected(selected === s.id ? null : s.id)}><ArrowUpRight size={18} /></button>{selected === s.id && <div className="row-actions"><LegacyAction path="subscription/activation.php" label={s.active === 1 ? 'Desactivar' : 'Activar'} fields={{ id: s.id, active: s.active === 1 ? 0 : 1 }} /><LegacyAction path="subscription/resend_email.php" label="Reenviar correo" fields={{ id: s.id }} /><LegacyAction path="subscription/log_attendance.php" label="Registrar asistencia" fields={{ id: s.id }} /><LegacyAction path="subscription/log_attendance_force.php" label="Forzar asistencia" fields={{ id: s.id }} /></div>}</td>}</tr>)}</tbody></table></div>;
}
function LegacyAction({ path, fields, label }: { path: string; fields: Record<string, number>; label: string }) {
  return <form action={legacyUrl(path)} method="POST" onSubmit={e => { if (preview) e.preventDefault(); }}>
    <input type="hidden" name="csrf_token" value={getCsrfToken()} />
    {Object.entries(fields).map(([key, value]) => <input type="hidden" name={key} value={value} key={key} />)}<button disabled={preview} type="submit">{label}</button>
  </form>;
}
function Attendance({ data }: { data: AppData }) {
  const [search, setSearch] = useState(''); const [session, setSession] = useState('all');
  const rows = data.attendance.filter(a => matchesSearch([a.fullname, a.event_name, a.session_name], search) && (session === 'all' || String(a.event_session_id) === session));
  return <section className="panel"><div className="toolbar panel-toolbar"><SearchInput value={search} onChange={setSearch} placeholder="Buscar una asistencia…" /><select aria-label="Filtrar por sesión" value={session} onChange={e => setSession(e.target.value)}><option value="all">Todas las sesiones</option>{Array.from(new Map(data.attendance.map(a => [a.event_session_id, a.session_name]))).map(([id, name]) => <option key={id} value={id}>{name}</option>)}</select></div>{rows.length ? <div className="table-scroll" tabIndex={0} role="region" aria-label="Registros de asistencia"><table><thead><tr><th>Participante</th><th>Encuentro y sesión</th><th>Registro</th><th>Asistencia</th></tr></thead><tbody>{rows.map(a => <tr key={a.id}><td><div className="person"><span className={`person-avatar avatar-${a.person_id % 4}`}>{initials(a.fullname)}</span><strong>{a.fullname}</strong></div></td><td>{a.session_name}<small>{a.event_name}</small></td><td>{formatDate(a.log_time)}<small>{formatDate(a.log_time, { hour: '2-digit', minute: '2-digit' })}</small></td><td><span className="badge active"><Check size={13} />Registrada</span></td></tr>)}</tbody></table></div> : <Empty title="Aún no hay asistencias en esta vista">Los registros aparecerán aquí al recibir a los participantes.</Empty>}<div className="table-footer">{rows.length} registros de asistencia</div></section>;
}
function Reports({ data }: { data: AppData }) {
  return <><div className="report-grid"><section className="panel report-card"><span className="stat-icon rose"><Users size={23} /></span><h2>Participantes</h2><p>Un listado de las inscripciones de la comunidad, con sus datos de contacto y estado.</p><strong className="report-number">{data.subscriptions.length}<small>inscripciones</small></strong><button className="button secondary" onClick={() => downloadCsv('participantes.csv', ['Nombre', 'Correo', 'Teléfono', 'Estado'], data.subscriptions.map(s => [s.fullname, s.email, s.phone, s.active === 1 ? 'Activa' : 'Inactiva']))}><ArrowDownToLine size={16} /> Descargar CSV</button></section><section className="panel report-card"><span className="stat-icon sage"><ListChecks size={23} /></span><h2>Asistencia</h2><p>Todos los encuentros compartidos, organizados por participante, evento y sesión.</p><strong className="report-number">{data.attendance.length}<small>asistencias registradas</small></strong><button className="button secondary" onClick={() => downloadCsv('asistencia.csv', ['Nombre', 'Evento', 'Sesión', 'Fecha (Bolivia)'], data.attendance.map(a => [a.fullname, a.event_name, a.session_name, formatDate(a.log_time, { dateStyle: 'short', timeStyle: 'short' })]))}><ArrowDownToLine size={16} /> Descargar CSV</button></section></div><a className="legacy-report" href={legacyUrl('attendance/report.php')}><span className="stat-icon sand"><ChartNoAxesCombined size={23} /></span><div><h3>Informe detallado de asistencia</h3><p>Consulta el informe clásico y sus opciones de impresión, Excel y PDF.</p></div><ArrowUpRight size={22} /></a></>;
}

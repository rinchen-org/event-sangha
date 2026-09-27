import { useEffect, useRef, useState } from 'react';
import { ArrowDownToLine, ArrowRight, ArrowUpRight, CalendarDays, Check, ChevronLeft, ChevronRight, CircleHelp, Clock3, Flower2, LayoutDashboard, ListChecks, Menu, Plus, RefreshCw, Sprout, Upload, Users, X, ChartNoAxesCombined, ExternalLink } from 'lucide-react';
import type { AppData, Editor as EditorType, Page, SubscriptionRecord } from './types';
import { dateValue, downloadCsv, emptyData, eventStatus, formatDate, initials, legacyUrl, loadData, matchesSearch, preview } from './data';
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
  const id = location.hash.slice(1);
  return navigation.some(n => n.id === id) ? id as Page : 'inicio';
}

export function App() {
  const [page, setPage] = useState<Page>(currentPage);
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
  const [revision, setRevision] = useState(0);
  const [editor, setEditor] = useState<EditorType | null>(null);
  const [notice, setNotice] = useState('');
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    const change = () => { setPage(currentPage()); setMobileOpen(false); };
    window.addEventListener('hashchange', change);
    return () => window.removeEventListener('hashchange', change);
  }, []);
  useEffect(() => { document.title = `${navigation.find(n => n.id === page)?.label} · Rinchen`; heading.current?.focus(); }, [page]);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setError('');
    loadData(controller.signal).then(result => { if (!controller.signal.aborted) setData(result); })
      .catch(err => { if (!controller.signal.aborted) setError(err instanceof Error && err.message.startsWith('No pudimos') ? err.message : 'No pudimos conectar con el centro. Vuelve a intentarlo en un momento.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [revision]);
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
  const active = data.subscriptions.filter(s => s.active === 1).length;
  const open = (kind: EditorType['kind'], id?: number) => setEditor({ kind, id });
  const ready = !loading && !error;
  return <div className="app-shell">
    <a className="skip-link" href="#main-content" onClick={e => { e.preventDefault(); heading.current?.focus(); }}>Saltar al contenido</a>
    <aside ref={sidebar} className={`sidebar ${mobileOpen ? 'is-open' : ''}`} inert={smallScreen && !mobileOpen}>
      <button className="icon-button sidebar-close" aria-label="Cerrar navegación" onClick={() => { setMobileOpen(false); menuButton.current?.focus(); }}><X size={20} /></button>
      <a href="#inicio" className="brand" aria-label="Rinchen, inicio"><img src={logo} alt="" /><span>rinchen<span className="brand-subtitle">SAKYA RINCHEN LING</span></span></a>
      <div className="workspace"><span className="workspace-icon"><Flower2 size={19} /></span><div>Espacio de comunidad<small>Gestión de encuentros</small></div><span className="workspace-dot" /></div>
      <div className="nav-label">ORGANIZAR</div>
      <nav aria-label="Navegación principal">{navigation.map(({ id, label, icon: Icon }) => <a key={id} href={`#${id}`} onClick={() => setMobileOpen(false)} className={page === id ? 'nav-link selected' : 'nav-link'} aria-current={page === id ? 'page' : undefined}><Icon size={19} strokeWidth={1.65} /><span>{label}</span>{id === 'eventos' && ready && <span className="nav-count">{data.events.length}</span>}</a>)}</nav>
      <div className="sidebar-bottom"><div className="sidebar-note"><Sprout size={24} strokeWidth={1.4} /><p>Pequeños encuentros.<br /><strong>Grandes conexiones.</strong></p><span>Cultivemos comunidad, juntos.</span></div><a className="external-link" href={legacyUrl('index.php?legacy=1')}><ExternalLink size={16} /> Menú clásico <ArrowUpRight size={14} /></a><div className="center-signature"><span className="center-avatar">RL</span><div>Sakya Rinchen Ling<small>La Paz, Bolivia</small></div></div></div>
    </aside>
    {mobileOpen && <button tabIndex={-1} className="nav-backdrop" aria-label="Cerrar panel lateral" onClick={() => setMobileOpen(false)} />}
    <div className="main-shell">
      <header className="topbar"><div className="breadcrumb"><button ref={menuButton} className="icon-button mobile-menu" aria-label={mobileOpen ? 'Cerrar menú' : 'Abrir menú'} aria-expanded={mobileOpen} onClick={() => setMobileOpen(!mobileOpen)}>{mobileOpen ? <X size={22} /> : <Menu size={22} />}</button><span>Mi comunidad</span><ChevronRight size={13} /><strong>{navigation.find(n => n.id === page)?.label}</strong></div><div className="topbar-right"><span className="location"><span className="status-dot" /> La Paz, Bolivia</span><button className="icon-button" aria-label="Ayuda" onClick={() => setNotice(notice ? '' : 'Organiza eventos y sesiones, gestiona inscripciones y registra la asistencia. El menú clásico conserva todas las herramientas anteriores.')}><CircleHelp size={19} /></button><span className="profile-mark" aria-label="Sakya Rinchen Ling">RL</span></div></header>
      <main id="main-content">
        {preview && <div className="preview-banner"><Sprout size={16} /> Vista previa · Datos de ejemplo · No se guardan cambios</div>}
        {notice && <div className="notice" role="status"><p>{notice}</p><button className="icon-button" aria-label="Cerrar ayuda" onClick={() => setNotice('')}><X size={18} /></button></div>}
        <div className="page-heading"><div><span className="eyebrow">{page === 'inicio' ? 'NUESTRA COMUNIDAD' : navigation.find(n => n.id === page)?.label.toUpperCase()}</span><h1 ref={heading} tabIndex={-1}>{titles[page][0]}</h1><p>{titles[page][1]}</p></div><button className="button primary" disabled={!ready} onClick={() => open(page === 'sesiones' ? 'session' : page === 'asistencia' ? 'attendance' : page === 'participantes' ? 'subscription' : 'event')}><Plus size={17} />{page === 'sesiones' ? 'Nueva sesión' : page === 'asistencia' ? 'Registrar asistencia' : page === 'participantes' ? 'Nueva inscripción' : 'Crear evento'}</button></div>
        {loading ? <div className="loading-state" role="status"><RefreshCw className="spin" size={23} /><span>Preparando tu espacio…</span></div> : error ? <div className="connection-state" role="alert"><CircleHelp size={34} strokeWidth={1.2} /><h2>Volvamos a conectar</h2><p>{error}</p><button className="button primary" onClick={() => setRevision(r => r + 1)}><RefreshCw size={16} /> Volver a intentar</button><a href={legacyUrl('index.php?legacy=1')}>Abrir el menú clásico</a></div> : <>
          {page === 'inicio' && <>
            <section className="stats" aria-label="Resumen de la comunidad">{[
              { title: 'Eventos', value: data.events.length, note: `${data.events.filter(e => eventStatus(e.start_date, e.end_date).className !== 'neutral').length} próximos o en curso`, icon: CalendarDays, color: 'rose' },
              { title: 'Participantes', value: data.subscriptions.length, note: 'Inscripciones de la comunidad', icon: Users, color: 'sand' },
              { title: 'Inscripciones activas', value: active, note: 'Listas para el encuentro', icon: Check, color: 'sage' },
              { title: 'Asistencias', value: data.attendance.length, note: 'Encuentros compartidos', icon: ListChecks, color: 'lilac' },
            ].map(({ title, value, note, icon: Icon, color }) => <div className="stat" key={title}><div className="stat-top"><span>{title}</span><span className={`stat-icon ${color}`}><Icon size={19} strokeWidth={1.6} /></span></div><strong>{value.toLocaleString('es-BO')}</strong><small>{note}</small></div>)}</section>
            <section className="welcome-banner"><div><span className="eyebrow">VOLVER A LO ESENCIAL</span><h2>Hacer espacio para<br /><em>lo que nos une.</em></h2><p>Cada enseñanza, cada práctica, cada encuentro.<br />Todo empieza con nuestra comunidad.</p><a href="#eventos" className="banner-link">Explorar los eventos <ArrowRight size={17} /></a></div><div className="zen-art" aria-hidden="true"><span className="zen-orbit" /><span className="zen-orbit orbit-two" /><span className="zen-sun" /><span className="zen-stone stone-one" /><span className="zen-stone stone-two" /><span className="zen-stone stone-three" /><span className="zen-base" /><Flower2 className="zen-flower" size={100} strokeWidth={0.6} /></div><span className="banner-caption">PRESENCIA · SABIDURÍA · COMPASIÓN</span></section>
            <div className="dashboard-grid"><section><SectionTitle title="Nuestros encuentros" subtitle="Espacios para aprender y compartir."><a className="text-link" href="#eventos">Ver todos <ArrowRight size={16} /></a></SectionTitle><div className="event-grid compact">{[...data.events].sort((a, b) => dateValue(b.start_date).getTime() - dateValue(a.start_date).getTime()).slice(0, 2).map((event, i) => <EventCard key={event.id} event={event} index={i} sessions={data.sessions.filter(s => s.event_id === event.id).length} onSelect={() => open('event', event.id)} />)}</div>{!data.events.length && <Empty title="El próximo encuentro empieza aquí">Crea tu primer evento para reunir a la comunidad.</Empty>}</section>
              <section className="agenda-panel"><SectionTitle title="En la agenda"><a className="icon-button" href="#sesiones" aria-label="Ver todas las sesiones"><ArrowUpRight size={18} /></a></SectionTitle><Agenda data={data} open={id => open('session', id)} /><div className="agenda-footer"><Clock3 size={14} /> Hora de Bolivia (GMT−4)</div></section></div>
            <section className="panel recent-panel"><SectionTitle title="La comunidad crece" subtitle="Últimas inscripciones"><a href="#participantes" className="text-link">Ver participantes <ArrowRight size={16} /></a></SectionTitle><ParticipantTable rows={[...data.subscriptions].sort((a, b) => dateValue(b.datetime).getTime() - dateValue(a.datetime).getTime()).slice(0, 4)} compact /></section>
          </>}
          {page === 'eventos' && <Events data={data} open={id => open('event', id)} />}
          {page === 'sesiones' && <Sessions data={data} open={id => open('session', id)} />}
          {page === 'participantes' && <Participants data={data} upload={() => open('upload')} />}
          {page === 'asistencia' && <Attendance data={data} />}
          {page === 'informes' && <Reports data={data} />}
        </>}
        <footer className="page-footer"><span><Flower2 size={15} strokeWidth={1.4} /> Hecho para cultivar comunidad.</span><span>Sakya Rinchen Ling · Bolivia</span></footer>
      </main>
    </div>
    {editor && <Editor editor={editor} data={data} close={() => setEditor(null)} />}
  </div>;
}
function Agenda({ data, open }: { data: AppData; open: (id: number) => void }) {
  const sessions = [...data.sessions].filter(s => dateValue(s.end_date).getTime() >= Date.now()).sort((a, b) => dateValue(a.start_date).getTime() - dateValue(b.start_date).getTime()).slice(0, 3);
  return sessions.length ? <div className="agenda-list">{sessions.map(s => <button className="agenda-item" key={s.id} onClick={() => open(s.id)}><span className="date-tile"><strong>{formatDate(s.start_date, { day: '2-digit' })}</strong><small>{formatDate(s.start_date, { month: 'short' }).replace('.', '')}</small></span><span><strong>{s.name}</strong><small>{formatDate(s.start_date, { hour: '2-digit', minute: '2-digit' })} · {s.event_name}</small></span></button>)}</div> : <Empty title="Tiempo para un nuevo encuentro">Las próximas sesiones aparecerán aquí.</Empty>;
}
function Events({ data, open }: { data: AppData; open: (id: number) => void }) {
  const [search, setSearch] = useState(''); const [filter, setFilter] = useState('all');
  const rows = data.events.filter(e => matchesSearch([e.name, e.description], search) && (filter === 'all' || (filter === 'upcoming' ? eventStatus(e.start_date, e.end_date).className !== 'neutral' : eventStatus(e.start_date, e.end_date).className === 'neutral')));
  return <><div className="toolbar"><div className="tabs" aria-label="Filtrar eventos">{[['all', 'Todos'], ['upcoming', 'Próximos y en curso'], ['past', 'Finalizados']].map(([key, label]) => <button key={key} className={filter === key ? 'active' : ''} aria-pressed={filter === key} onClick={() => setFilter(key)}>{label}</button>)}</div><SearchInput value={search} onChange={setSearch} placeholder="Buscar eventos…" /></div><div className="event-grid">{rows.map((e, i) => <EventCard key={e.id} event={e} index={i} sessions={data.sessions.filter(s => s.event_id === e.id).length} onSelect={() => open(e.id)} />)}</div>{!rows.length && <Empty title={search ? 'No encontramos ese encuentro' : 'Aún no hay eventos en esta vista'}>Prueba otro filtro o crea un nuevo evento.</Empty>}</>;
}
function Sessions({ data, open }: { data: AppData; open: (id: number) => void }) {
  const [filter, setFilter] = useState('all');
  const rows = [...data.sessions].filter(s => filter === 'all' || String(s.event_id) === filter).sort((a, b) => dateValue(a.start_date).getTime() - dateValue(b.start_date).getTime());
  return <section className="panel"><div className="toolbar panel-toolbar"><h2>Agenda de sesiones</h2><select aria-label="Filtrar por evento" value={filter} onChange={e => setFilter(e.target.value)}><option value="all">Todos los eventos</option>{data.events.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}</select></div>{rows.length ? <div className="session-list">{rows.map(s => <button className="session-row" key={s.id} onClick={() => open(s.id)}><span className="date-tile"><strong>{formatDate(s.start_date, { day: '2-digit' })}</strong><small>{formatDate(s.start_date, { month: 'short' })}</small></span><span className="session-name"><strong>{s.name}</strong><small>{s.event_name}</small></span><span className="session-time"><Clock3 size={15} />{formatDate(s.start_date, { hour: '2-digit', minute: '2-digit' })} – {formatDate(s.end_date, { hour: '2-digit', minute: '2-digit' })}<small>{formatDate(s.start_date)}</small></span><ArrowUpRight size={19} /></button>)}</div> : <Empty title="Tu agenda tiene espacio">Crea una sesión para comenzar.</Empty>}<div className="table-footer">{rows.length} sesiones · Hora de Bolivia</div></section>;
}
function Participants({ data, upload }: { data: AppData; upload: () => void }) {
  const [search, setSearch] = useState(''); const [filter, setFilter] = useState('all'); const [page, setPage] = useState(0);
  const rows = data.subscriptions.filter(s => matchesSearch([s.fullname, s.email, s.phone], search) && (filter === 'all' || String(s.active) === filter));
  const pages = Math.max(1, Math.ceil(rows.length / 10));
  return <section className="panel"><div className="toolbar panel-toolbar"><SearchInput value={search} onChange={s => { setSearch(s); setPage(0); }} placeholder="Buscar nombre, correo o teléfono…" /><div className="toolbar-actions"><select aria-label="Estado de inscripción" value={filter} onChange={e => { setFilter(e.target.value); setPage(0); }}><option value="all">Todos los estados</option><option value="1">Activas</option><option value="0">Inactivas</option></select><button className="button secondary" onClick={upload}><Upload size={16} /> Importar CSV</button></div></div><ParticipantTable rows={rows.slice(page * 10, page * 10 + 10)} /><div className="table-footer"><span>{rows.length} participantes</span><div className="pagination"><button className="icon-button" aria-label="Página anterior" disabled={page === 0} onClick={() => setPage(p => p - 1)}><ChevronLeft size={18} /></button><span>{page + 1} de {pages}</span><button className="icon-button" aria-label="Página siguiente" disabled={page + 1 >= pages} onClick={() => setPage(p => p + 1)}><ChevronRight size={18} /></button></div></div></section>;
}
function ParticipantTable({ rows, compact = false }: { rows: SubscriptionRecord[]; compact?: boolean }) {
  const [selected, setSelected] = useState<number | null>(null);
  if (!rows.length) return <Empty title="No hay participantes en esta vista">Las nuevas inscripciones aparecerán aquí. Si estás buscando, prueba otro nombre.</Empty>;
  return <div className="table-scroll" tabIndex={0} role="region" aria-label="Lista de participantes"><table><thead><tr><th>Participante</th><th>Contacto</th><th>Inscripción</th><th>Estado</th>{!compact && <th><span className="sr-only">Acciones</span></th>}</tr></thead><tbody>{rows.map(s => <tr key={s.id}><td><div className="person"><span className={`person-avatar avatar-${s.id % 4}`}>{initials(s.fullname)}</span><span><strong>{s.fullname}</strong><small>#{String(s.id).padStart(4, '0')}</small></span></div></td><td><span className="cell-email">{s.email}</span><small>{s.phone}</small></td><td>{formatDate(s.datetime)}</td><td><span className={`badge ${s.active === 1 ? 'active' : 'neutral'}`}><span className="badge-dot" />{s.active === 1 ? 'Activa' : 'Inactiva'}</span></td>{!compact && <td><button className="icon-button" aria-label={`Acciones de ${s.fullname}`} aria-expanded={selected === s.id} onClick={() => setSelected(selected === s.id ? null : s.id)}><ArrowUpRight size={18} /></button>{selected === s.id && <div className="row-actions"><LegacyAction path="subscription/activation.php" label={s.active === 1 ? 'Desactivar' : 'Activar'} fields={{ id: s.id, active: s.active === 1 ? 0 : 1 }} /><LegacyAction path="subscription/resend_email.php" label="Reenviar correo" fields={{ id: s.id }} /><LegacyAction path="subscription/log_attendance.php" label="Registrar asistencia" fields={{ id: s.id }} /><LegacyAction path="subscription/log_attendance_force.php" label="Forzar asistencia" fields={{ id: s.id }} /></div>}</td>}</tr>)}</tbody></table></div>;
}
function LegacyAction({ path, fields, label }: { path: string; fields: Record<string, number>; label: string }) {
  return <form action={legacyUrl(path)} method="POST" onSubmit={e => { if (preview) e.preventDefault(); }}>
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

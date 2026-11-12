import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { App } from './App';
import { previewData } from './preview';
import { Editor } from './components/Editor';

const event1 = { event: previewData.events[0], sessions: previewData.sessions, subscriptions: [previewData.subscriptions[0]],
  attendance: [{ ...previewData.attendance[0], event_name: previewData.events[0].name, event_session_id: 1 }] };
const event2 = { event: previewData.events[1], sessions: [{ ...previewData.sessions[0], id: 100, event_id: 2, name: 'Solo evento dos' }], subscriptions: [previewData.subscriptions[1]], attendance: [] };
const respond = (data: unknown, status = 200) => new Response(JSON.stringify({ success: status === 200, data }), { status });
const normalFetch = async (input: RequestInfo | URL) => {
  const url = new URL(String(input));
  if (url.pathname.endsWith('/session.php')) return new Response(JSON.stringify({ success: true, csrfToken: 'event-csrf' }));
  if (url.pathname.endsWith('/event/detail.php')) {
    const id = url.searchParams.get('event_id');
    return id === '1' ? respond(event1) : id === '2' ? respond(event2) : respond(null, 404);
  }
  const resource = url.pathname.split('/').at(-2);
  return respond(({ event: previewData.events, 'event-session': previewData.sessions, subscription: previewData.subscriptions, attendance: previewData.attendance } as Record<string, unknown>)[resource || '']);
};
beforeEach(() => { location.hash = ''; vi.stubGlobal('fetch', vi.fn(normalFetch)); });
const navigate = async (hash: string) => { await act(async () => { location.hash = hash; window.dispatchEvent(new HashChangeEvent('hashchange')); }); };

it('opens an event page from its card rather than opening the edit form', async () => {
  render(<App />);
  const card = await screen.findByRole('link', { name: /Un encuentro con la calma Tres días/ });
  expect(card).toHaveAttribute('href', '#eventos/1/participantes');
  await userEvent.click(card);
  expect(await screen.findByRole('heading', { level: 1, name: event1.event.name })).toBeInTheDocument();
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(screen.getByText('Ana Flores')).toBeInTheDocument();
  expect(screen.queryByText('Diego Molina')).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Vincular inscripción existente' })).toHaveAttribute('href', expect.stringContaining('event/register.php?event_id=1'));
  await userEvent.click(screen.getByRole('button', { name: 'Editar evento' }));
  expect(screen.getByRole('dialog', { name: 'Editar evento' })).toBeInTheDocument();
});

it('binds CSV import to the displayed event and includes CSRF without duplicating backend policy', async () => {
  location.hash = '#eventos/2/participantes';
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: event2.event.name });
  await userEvent.click(screen.getByRole('button', { name: 'Importar CSV al evento' }));
  const dialog = screen.getByRole('dialog', { name: 'Importar participantes' });
  const form = within(dialog).getByRole('button', { name: 'Revisar CSV' }).closest('form')!;
  expect(form).toHaveAttribute('action', expect.stringContaining('/subscription/upload.php'));
  expect(form).toHaveAttribute('method', 'POST');
  expect(form.querySelector('[name="event_id"]')).toHaveValue('2');
  expect(form.querySelector('[name="csrf_token"]')).toHaveValue('event-csrf');
  expect(within(dialog).getByText(event2.event.name)).toBeInTheDocument();
  expect(form.querySelector('[name="csv_file"]')).toBeRequired();
  expect(within(dialog).getByText(/La importación no envía correos/)).toBeInTheDocument();
});

it('offers separately reviewed selected/all mail with event and CSRF binding', async () => {
  location.hash = '#eventos/1/participantes';
  render(<App />);
  const selected = await screen.findByRole('button', { name: 'Enviar a seleccionados' });
  expect(selected).toBeDisabled();
  const all = screen.getByRole('button', { name: 'Enviar a todos' });
  expect(all).toBeEnabled();
  expect(all).toHaveAttribute('name', 'mode');
  expect(all).toHaveAttribute('value', 'all');
  const form = selected.closest('form')!;
  expect(form).toHaveAttribute('action', expect.stringContaining('/event/email.php'));
  expect(form).toHaveAttribute('method', 'POST');
  expect(form.querySelector('[name="event_id"]')).toHaveValue('1');
  expect(form.querySelector('[name="csrf_token"]')).toHaveValue('event-csrf');
  await userEvent.click(screen.getByRole('checkbox', { name: 'Seleccionar a Ana Flores para correo' }));
  expect(selected).toBeEnabled();
  expect(form.querySelector('[name="subscription_ids"]')).toHaveValue('1');
  expect(selected).toHaveAttribute('value', 'selected');
  expect(screen.getByRole('link', { name: 'Historial de correos' })).toHaveAttribute('href', expect.stringContaining('event/email.php?event_id=1'));
  await navigate('#eventos/2/participantes');
  expect(await screen.findByRole('checkbox', { name: 'Seleccionar a Diego Molina para correo' })).not.toBeChecked();
  expect(screen.getByRole('button', { name: 'Enviar a seleccionados' })).toBeDisabled();
});

it('keeps explicit selection across pages and filters while all targets the whole event', async () => {
  const subscriptions = Array.from({ length: 12 }, (_, i) => ({ ...previewData.subscriptions[0], id: 100 + i, person_id: 500 + i, fullname: `Test ${i}` }));
  vi.mocked(fetch).mockImplementation(async input => String(input).includes('event/detail.php') ? respond({ ...event1, subscriptions }) : normalFetch(input));
  location.hash = '#eventos/1/participantes';
  render(<App />);
  await userEvent.click(await screen.findByRole('checkbox', { name: 'Seleccionar página para correo' }));
  await userEvent.click(screen.getByRole('button', { name: 'Página siguiente' }));
  await userEvent.click(screen.getByRole('checkbox', { name: 'Seleccionar a Test 11 para correo' }));
  const form = screen.getByRole('button', { name: 'Enviar a seleccionados' }).closest('form')!;
  expect(form.querySelector('[name="subscription_ids"]')).toHaveValue('100,101,102,103,104,105,106,107,108,109,111');
  await userEvent.type(screen.getByRole('textbox', { name: 'Buscar nombre, correo o teléfono…' }), 'Test 10');
  expect(screen.getByText(/11 seleccionado\(s\)/)).toHaveTextContent('12 participantes del evento, sin aplicar filtros');
  expect(form.querySelector('[name="subscription_ids"]')).toHaveValue('100,101,102,103,104,105,106,107,108,109,111');
});

it('requires an explicit event when importing from the global participant view', () => {
  render(<Editor editor={{ kind: 'upload' }} data={previewData} close={() => {}} />);
  expect(screen.getByLabelText('Evento de destino')).toBeRequired();
  expect(screen.getByLabelText('Evento de destino')).toHaveValue('');
});

it('offers per-person QR review with event/subscription/CSRF binding, not legacy attendance actions', async () => {
  location.hash = '#eventos/2/participantes';
  render(<App />);
  const button = await screen.findByRole('button', { name: 'Regenerar QR' });
  const form = button.closest('form')!;
  expect(button).toBeEnabled();
  expect(form).toHaveAttribute('action', expect.stringContaining('/subscription/regenerate_qr.php'));
  expect(form).toHaveAttribute('method', 'POST');
  expect(form.querySelector('[name="subscription_id"]')).toHaveValue(String(event2.subscriptions[0].id));
  expect(form.querySelector('[name="event_id"]')).toHaveValue('2');
  expect(form.querySelector('[name="csrf_token"]')).toHaveValue('event-csrf');
  expect(form.querySelector('[name="action"]')).toBeNull(); // default is review, never confirm
  expect(screen.queryByRole('button', { name: /Acciones de/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Forzar asistencia' })).not.toBeInTheDocument();
});

it('offers the same QR review from the global list without inventing an event scope', async () => {
  location.hash = '#participantes';
  render(<App />);
  const button = (await screen.findAllByRole('button', { name: 'Regenerar QR' }))[0];
  const form = button.closest('form')!;
  expect(form.querySelector('[name="subscription_id"]')).toHaveValue(String(previewData.subscriptions[0].id));
  expect(form.querySelector('[name="event_id"]')).toBeNull();
  expect(form.querySelector('[name="csrf_token"]')).toHaveValue('event-csrf');
});

it('keeps participants, sessions and attendance isolated when navigating and returning', async () => {
  location.hash = '#eventos/1/participantes';
  render(<App />);
  await screen.findByText('Ana Flores');
  await navigate('#eventos/2/participantes');
  await screen.findByText('Diego Molina');
  expect(screen.queryByText('Ana Flores')).not.toBeInTheDocument();
  await userEvent.click(within(screen.getByRole('navigation', { name: 'Secciones del evento' })).getByRole('link', { name: /Sesiones/ }));
  expect(await screen.findByText('Solo evento dos')).toBeInTheDocument();
  expect(screen.queryByText('Enseñanzas y meditación')).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Nueva sesión' }));
  expect(within(screen.getByRole('dialog')).getByLabelText('Evento')).toHaveValue('2');
  await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancelar' }));
  await navigate('#eventos/2/asistencia');
  expect(await screen.findByText('Aún no hay asistencias en esta vista')).toBeInTheDocument();
  await navigate('#eventos/1/asistencia');
  expect(await screen.findByRole('region', { name: 'Registros de asistencia' })).toHaveTextContent('Ana Flores');
});

it('does not expose global activation or hardcoded-event attendance shortcuts inside an event', async () => {
  location.hash = '#eventos/1/participantes';
  render(<App />);
  await screen.findByText('Ana Flores');
  expect(screen.getByRole('columnheader', { name: 'Estado general' })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Acciones de Ana Flores' })).not.toBeInTheDocument();
});

it('shows an empty event roster instead of inventing links from the global list', async () => {
  location.hash = '#eventos/1';
  vi.mocked(fetch).mockImplementation(async input => String(input).includes('event/detail.php') ? respond({ ...event1, subscriptions: [] }) : normalFetch(input));
  render(<App />);
  expect(await screen.findByText('No hay participantes en esta vista')).toBeInTheDocument();
  expect(screen.queryByText('Ana Flores')).not.toBeInTheDocument();
  expect(screen.getByText(/Las inscripciones anteriores no se asignan automáticamente/)).toBeInTheDocument();
});

it('loads event details independently of unrelated global list failures', async () => {
  location.hash = '#eventos/1';
  vi.mocked(fetch).mockImplementation(async input => String(input).includes('/list.php') ? respond(null, 503) : normalFetch(input));
  render(<App />);
  expect(await screen.findByText('Ana Flores')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Importar CSV al evento' })).toBeEnabled();
});

it.each([401, 404, 503])('does not replace a failed event request (%i) with global or preview participants', async status => {
  location.hash = '#eventos/1';
  vi.mocked(fetch).mockImplementation(async input => String(input).includes('event/detail.php') ? respond(null, status) : normalFetch(input));
  render(<App />);
  expect(await screen.findByRole('alert')).toBeInTheDocument();
  expect(screen.queryByText('Ana Flores')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Importar CSV al evento' })).not.toBeInTheDocument();
  if (status === 401) expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toBeInTheDocument();
});

it('rejects invalid routes without issuing a request for another event', async () => {
  location.hash = '#eventos/invalid';
  render(<App />);
  await screen.findByRole('alert');
  await waitFor(() => expect(fetch).toHaveBeenCalled());
  expect(vi.mocked(fetch).mock.calls.some(([url]) => String(url).includes('event/detail.php'))).toBe(false);
});

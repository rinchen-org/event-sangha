import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from './App';
import { Editor } from './components/Editor';
import { emptyData } from './data';
import { previewData } from './preview';

const endpointData: Record<string, unknown> = {
  '/templates/event/list.php': previewData.events,
  '/templates/event-session/list.php': previewData.sessions,
  '/templates/subscription/list.php': previewData.subscriptions,
  '/templates/attendance/list.php': previewData.attendance,
};
beforeEach(() => {
  location.hash = '';
  vi.stubGlobal('fetch', vi.fn(async (url: string) => ({ ok: true, status: 200, json: async () => new URL(url).pathname.endsWith('/session.php') ? { success: true, csrfToken: 'test-csrf-token' } : { success: true, data: endpointData[new URL(url).pathname] } })));
});
describe('React management views', () => {
  it('hides the test menu unless the server explicitly enables it', async () => {
    render(<App />);
    await screen.findByRole('heading', { name: 'Nuestros encuentros' });
    expect(screen.queryByRole('link', { name: 'Pruebas · Laboratorio QR' })).not.toBeInTheDocument();
  });
  it('shows the enabled test menu even when production dashboard data cannot load', async () => {
    vi.mocked(fetch).mockImplementation(async (url) => {
      if (String(url).includes('session.php')) return new Response(JSON.stringify({ success: true, csrfToken: 'test-token', testMode: true }), { status: 200 });
      return new Response('', { status: 503 });
    });
    render(<App />);
    expect(await screen.findByRole('link', { name: 'Pruebas · Laboratorio QR' })).toHaveAttribute('href', expect.stringContaining('/templates/testing/index.php'));
    expect(await screen.findByRole('alert')).toHaveTextContent('Volvamos a conectar');
  });
  it('renders real endpoint counts without a legacy menu', async () => {
    render(<App />);
    expect(await screen.findByRole('heading', { name: 'Nuestros encuentros' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /menú clásico/i })).not.toBeInTheDocument();
    expect(document.querySelector('a[href*="legacy=1"], a[href*="menu.php"]')).toBeNull();
    const credit = within(screen.getByRole('contentinfo')).getByRole('link', { name: 'SciStitch' });
    expect(credit).toHaveAttribute('href', 'https://scistitch.com/');
    expect(credit).toHaveAttribute('rel', 'noopener noreferrer');
    expect(fetch).toHaveBeenCalledTimes(5);
    expect(screen.getByRole('region', { name: 'Resumen de los eventos' })).toHaveTextContent('3');
    expect(screen.queryByText('Últimas inscripciones')).not.toBeInTheDocument();
    expect(screen.queryByText('Ana Flores')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Ayuda' }));
    expect(screen.getByRole('status')).toHaveTextContent('Usa la navegación');
    expect(screen.queryByText(/menú clásico/i)).not.toBeInTheDocument();
  });
  it('shows connection failures without substituting sample data and allows retry', async () => {
    vi.mocked(fetch).mockRejectedValueOnce(new Error('Offline'));
    render(<App />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Volvamos a conectar');
    expect(screen.queryByRole('link', { name: /menú clásico/i })).not.toBeInTheDocument();
    expect(screen.queryByText('Ana Flores')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Preparar evento 2026' })).toHaveAttribute('href', expect.stringContaining('/scripts/setup.php?mode=prepare2026'));
    await userEvent.click(screen.getByRole('button', { name: 'Volver a intentar' }));
    expect(await screen.findByRole('heading', { name: 'Nuestros encuentros' })).toBeInTheDocument();
  });
  it('keeps event preparation available while dashboard data is loading', () => {
    vi.mocked(fetch).mockImplementation(() => new Promise(() => {}));
    render(<App />);
    expect(screen.getByRole('link', { name: 'Preparar evento 2026' })).toHaveAttribute('href', expect.stringContaining('/scripts/setup.php?mode=prepare2026'));
    expect(screen.getByRole('button', { name: 'Crear evento' })).toBeDisabled();
  });
  it('retains the PHP detailed report from the React navigation', async () => {
    location.hash = '#informes';
    render(<App />);
    expect(await screen.findByRole('link', { name: /Informe detallado de asistencia/ })).toHaveAttribute('href', expect.stringContaining('/templates/attendance/report.php'));
    expect(screen.queryByRole('link', { name: /menú clásico/i })).not.toBeInTheDocument();
  });
  it('filters participants by accent-insensitive search and activation state', async () => {
    location.hash = '#participantes';
    render(<App />);
    const search = await screen.findByRole('textbox', { name: 'Buscar nombre, correo o teléfono…' });
    await userEvent.type(search, 'lucia');
    expect(screen.getByText('Lucía Ríos')).toBeInTheDocument();
    expect(screen.queryByText('Ana Flores')).not.toBeInTheDocument();
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Estado de inscripción' }), '0');
    expect(screen.getByText('No hay participantes en esta vista')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Limpiar búsqueda' }));
    expect(screen.getByText('Mateo Vargas')).toBeInTheDocument();
  });
  it('opens an accessible event form and restores its trigger after closing', async () => {
    render(<App />);
    const trigger = await screen.findByRole('button', { name: 'Crear evento' });
    await waitFor(() => expect(trigger).toBeEnabled());
    await userEvent.click(trigger);
    const dialog = screen.getByRole('dialog', { name: 'Nuevo evento' });
    expect(within(dialog).getByLabelText('Nombre del evento')).toBeRequired();
    expect(within(dialog).getByLabelText('Inicio')).toHaveAttribute('name', 'start_date');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });
});
describe('compatibility forms', () => {
  it('submits CSV uploads for review with both supported formats explained', () => {
    render(<Editor editor={{ kind: 'upload' }} data={emptyData} close={() => {}} />);
    expect(screen.getByText(/CSV histórico de Google Forms.*2026/)).toBeInTheDocument();
    const form = screen.getByRole('button', { name: 'Revisar CSV' }).closest('form')!;
    expect(form).toHaveAttribute('action', expect.stringContaining('subscription/upload.php'));
    expect(form).toHaveAttribute('method', 'POST');
    expect(form).toHaveAttribute('enctype', 'multipart/form-data');
    expect(form.querySelector('input[name="csv_file"]')).toBeRequired();
  });
  it('preserves event edit IDs and converts UTC timestamps for date inputs', () => {
    const data = { ...emptyData, events: [{ id: 10, name: 'Encuentro', description: '', start_date: '2026-09-27 02:00:00', end_date: '2026-09-28 02:00:00' }] };
    render(<Editor editor={{ kind: 'event', id: 10 }} data={data} close={() => {}} />);
    expect(screen.getByLabelText('Nombre del evento').closest('form')).toHaveAttribute('action', expect.stringContaining('event/form.php?id=10'));
    expect(screen.getByLabelText('Inicio')).toHaveValue('2026-09-26');
  });
  it('uses the actual person ID and limits attendance sessions to the selected event', async () => {
    const data = { ...previewData, subscriptions: [{ ...previewData.subscriptions[0], id: 50, person_id: 91 }] };
    render(<Editor editor={{ kind: 'attendance' }} data={data} close={() => {}} />);
    expect(screen.getByRole('option', { name: /Ana Flores/ })).toHaveValue('91');
    await userEvent.selectOptions(screen.getByLabelText('Evento'), '2');
    expect(within(screen.getByLabelText('Sesión')).getAllByRole('option')).toHaveLength(1);
  });
});

it('requires login on an expired session instead of showing private data', async () => {
  vi.mocked(fetch).mockResolvedValueOnce({ ok: false, status: 401 } as Response);
  render(<App />);
  expect(await screen.findByRole('heading', { name: 'Inicia sesión para continuar' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toHaveAttribute('href', expect.stringContaining('/templates/login.php'));
  expect(screen.queryByRole('link', { name: /menú clásico/i })).not.toBeInTheDocument();
  expect(screen.queryByText('Ana Flores')).not.toBeInTheDocument();
  expect(fetch).toHaveBeenCalledTimes(1);
});
it('includes the server CSRF token in forms and logout', async () => {
  render(<App />);
  await screen.findByRole('heading', { name: 'Nuestros encuentros' });
  const logout = screen.getByRole('button', { name: 'Cerrar sesión' }).closest('form')!;
  expect(logout.querySelector('input[name="csrf_token"]')).toHaveValue('test-csrf-token');
  await userEvent.click(screen.getByRole('button', { name: 'Crear evento' }));
  const dialog = screen.getByRole('dialog');
  expect(dialog.querySelector('input[name="csrf_token"]')).toHaveValue('test-csrf-token');
});

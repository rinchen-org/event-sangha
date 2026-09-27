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
  vi.stubGlobal('fetch', vi.fn(async (url: string) => ({ ok: true, json: async () => ({ success: true, data: endpointData[new URL(url).pathname] }) })));
});
describe('React management views', () => {
  it('renders real endpoint counts and keeps legacy access', async () => {
    render(<App />);
    expect(await screen.findByRole('heading', { name: 'Nuestros encuentros' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Menú clásico/ })).toHaveAttribute('href', expect.stringContaining('index.php?legacy=1'));
    expect(fetch).toHaveBeenCalledTimes(4);
    expect(screen.getByRole('region', { name: 'Resumen de la comunidad' })).toHaveTextContent('8');
  });
  it('shows connection failures without substituting sample data and allows retry', async () => {
    vi.mocked(fetch).mockRejectedValueOnce(new Error('Offline'));
    render(<App />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Volvamos a conectar');
    expect(screen.queryByText('Ana Flores')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Volver a intentar' }));
    expect(await screen.findByRole('heading', { name: 'Nuestros encuentros' })).toBeInTheDocument();
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

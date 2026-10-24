import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import { App } from './App';

vi.mock('./data', async importOriginal => {
  const data = await importOriginal<typeof import('./data')>();
  const { previewEventData } = await import('./preview');
  return { ...data, preview: true, loadData: async () => data.emptyData, loadEventData: async (id: number) => previewEventData(id) };
});

it('does not link preview mode to live event preparation', async () => {
  location.hash = '';
  render(<App />);
  await screen.findByRole('heading', { name: 'Nuestros encuentros' });
  expect(screen.getByRole('button', { name: 'Preparar evento 2026' })).toBeDisabled();
  expect(screen.queryByRole('link', { name: 'Preparar evento 2026' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Pruebas · Laboratorio QR' })).not.toBeInTheDocument();
});

it('keeps event linking and CSV mutations disabled in preview', async () => {
  location.hash = '#eventos/1/participantes';
  vi.stubGlobal('fetch', vi.fn());
  render(<App />);
  expect(await screen.findByRole('button', { name: 'Vincular inscripción existente' })).toBeDisabled();
  expect(screen.queryByRole('link', { name: 'Vincular inscripción existente' })).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Importar CSV al evento' }));
  fireEvent.submit(screen.getByRole('button', { name: 'Revisar CSV' }).closest('form')!);
  expect(screen.getByRole('status')).toHaveTextContent('No se han guardado datos');
  expect(fetch).not.toHaveBeenCalled();
});

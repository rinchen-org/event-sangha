import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { App } from './App';

vi.mock('./data', async importOriginal => {
  const data = await importOriginal<typeof import('./data')>();
  return { ...data, preview: true, loadData: async () => data.emptyData };
});

it('does not link preview mode to live event preparation', async () => {
  render(<App />);
  await screen.findByRole('heading', { name: 'Nuestros encuentros' });
  expect(screen.getByRole('button', { name: 'Preparar evento 2026' })).toBeDisabled();
  expect(screen.queryByRole('link', { name: 'Preparar evento 2026' })).not.toBeInTheDocument();
});

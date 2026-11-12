import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import { ParticipantQr } from './ParticipantQr';
import type { QrPreview } from '../types';

const url = `https://configured.example.test/event-retiro/static/qr/${'a'.repeat(64)}.jpg`;
const qr: QrPreview = { url, error: null, warnings: [] };

it('shows the existing QR as a lazy thumbnail linking to the protected full-size image', () => {
  render(<ParticipantQr qr={qr} fullname="Test Person" />);
  const image = screen.getByRole('img', { name: 'Código QR de Test Person' });
  expect(image).toHaveAttribute('src', url);
  expect(image).toHaveAttribute('loading', 'lazy');
  expect(image).toHaveAttribute('width', '128');
  const link = screen.getByRole('link', { name: /Abrir QR de Test Person a tamaño completo/ });
  expect(link).toHaveAttribute('href', url);
  expect(link).toHaveAttribute('target', '_blank');
  expect(link).toHaveAttribute('rel', 'noopener noreferrer');
});

it('renders server warnings as text without duplicating QR-origin policy in JavaScript', () => {
  const message = 'La dirección guardada difiere de HOST_ADDRESS. <No es HTML>';
  render(<ParticipantQr qr={{ ...qr, warnings: [{ code: 'qr_base_changed', message }] }} fullname="Test" />);
  expect(screen.getByText(message)).toBeInTheDocument();
  expect(screen.getByRole('img')).toHaveAttribute('src', url);
});

it('shows missing metadata/files/configuration without a fake QR or generation request', () => {
  const { rerender } = render(<ParticipantQr fullname="Test" />);
  expect(screen.getByText('QR no disponible. Puedes regenerarlo.')).toBeInTheDocument();
  expect(screen.queryByRole('img')).not.toBeInTheDocument();
  rerender(<ParticipantQr fullname="Test" qr={{ url: null, error: 'Configura HOST_ADDRESS.', warnings: [] }} />);
  expect(screen.getByText('Configura HOST_ADDRESS.')).toBeInTheDocument();
  expect(screen.queryByRole('link')).not.toBeInTheDocument();
});

it('replaces broken images with a clear message, never retries, and displays a later regenerated URL', () => {
  const { rerender } = render(<ParticipantQr qr={qr} fullname="Test" />);
  fireEvent.error(screen.getByRole('img'));
  expect(screen.getByRole('status')).toHaveTextContent('No se pudo cargar el QR');
  expect(screen.queryByRole('img')).not.toBeInTheDocument();
  expect(screen.queryByRole('link')).not.toBeInTheDocument();
  rerender(<ParticipantQr qr={qr} fullname="Test" />);
  expect(screen.queryByRole('img')).not.toBeInTheDocument();
  const next = url.replace('a'.repeat(64), 'b'.repeat(64));
  rerender(<ParticipantQr qr={{ ...qr, url: next }} fullname="Test" />);
  expect(screen.getByRole('img')).toHaveAttribute('src', next);
});

it('never loads or links live QR assets in explicit preview mode', () => {
  render(<ParticipantQr qr={qr} fullname="Test" preview />);
  expect(screen.getByText('QR no disponible en la vista previa.')).toBeInTheDocument();
  expect(screen.queryByRole('img')).not.toBeInTheDocument();
  expect(screen.queryByRole('link')).not.toBeInTheDocument();
});

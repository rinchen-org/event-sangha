import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const staticRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../src/static');
const script = readFileSync(resolve(staticRoot, 'participants-print.js'), 'utf8');
const styles = readFileSync(resolve(staticRoot, 'participants-print.css'), 'utf8');
const image = () => document.querySelector<HTMLImageElement>('[data-participant-qr]')!;
const button = () => document.getElementById('participants-print-button') as HTMLButtonElement;
const status = () => document.getElementById('participants-print-status')!;
const dimensions = (complete: boolean, width: number) => {
  Object.defineProperty(image(), 'complete', { configurable:true, value:complete });
  Object.defineProperty(image(), 'naturalWidth', { configurable:true, value:width });
};

beforeEach(() => {
  vi.useFakeTimers();
  vi.spyOn(window, 'print').mockImplementation(() => {});
  vi.stubGlobal('fetch', vi.fn());
  document.body.innerHTML = '<button id="participants-print-button" type="button" hidden disabled>Imprimir</button><p id="participants-print-status"></p><div><img data-participant-qr src="/static/qr/existing.jpg"><span data-qr-fallback hidden>QR no disponible</span></div>';
});
afterEach(() => { vi.clearAllTimers(); vi.useRealTimers(); vi.unstubAllGlobals(); });

it('prints only on an explicit click after cached QR images are ready', () => {
  dimensions(true, 300);
  window.eval(script);
  expect(button()).toBeEnabled();
  expect(button()).not.toHaveAttribute('hidden');
  expect(window.print).not.toHaveBeenCalled();
  button().click();
  expect(window.print).toHaveBeenCalledOnce();
  expect(fetch).not.toHaveBeenCalled();
});

it('waits for all images and never prints automatically after loading', () => {
  dimensions(false, 0);
  window.eval(script);
  expect(button()).toBeDisabled();
  button().click();
  expect(window.print).not.toHaveBeenCalled();
  dimensions(true, 300);
  image().dispatchEvent(new Event('load'));
  expect(button()).toBeEnabled();
  expect(status()).toHaveTextContent('Vista lista para imprimir');
  expect(window.print).not.toHaveBeenCalled();
});

it('shows a printable error for broken images without retrying or generating a QR', () => {
  dimensions(false, 0);
  window.eval(script);
  image().dispatchEvent(new Event('error'));
  expect(image()).toHaveAttribute('hidden');
  expect(document.querySelector('[data-qr-fallback]')).not.toHaveAttribute('hidden');
  expect(status()).toHaveAttribute('role', 'alert');
  expect(status()).toHaveTextContent('1 código(s) QR no disponible(s)');
  expect(button()).toBeEnabled();
  vi.advanceTimersByTime(60000);
  expect(image()).toHaveAttribute('src', '/static/qr/existing.jpg');
  expect(fetch).not.toHaveBeenCalled();
  expect(window.print).not.toHaveBeenCalled();
});

it('does not wait forever and recognizes a late successful in-flight load without retrying', () => {
  dimensions(false, 0);
  window.eval(script);
  vi.advanceTimersByTime(30000);
  expect(button()).toBeEnabled();
  expect(status()).toHaveTextContent('1 código(s) QR no disponible(s)');
  dimensions(true, 300);
  image().dispatchEvent(new Event('load'));
  expect(image()).not.toHaveAttribute('hidden');
  expect(status()).toHaveTextContent('Vista lista para imprimir');
  expect(fetch).not.toHaveBeenCalled();
  expect(window.print).not.toHaveBeenCalled();
});

it('keeps server-reported missing QR errors visible', () => {
  document.body.innerHTML = '<button id="participants-print-button" hidden disabled></button><p id="participants-print-status"></p><span data-qr-missing>Archivo no disponible</span>';
  window.eval(script);
  expect(status()).toHaveTextContent('1 código(s) QR no disponible(s)');
  expect(button()).toBeEnabled();
  expect(window.print).not.toHaveBeenCalled();
});

it('supports an empty report without inventing QR images or printing automatically', () => {
  document.body.innerHTML = '<button id="participants-print-button" hidden disabled></button><p id="participants-print-status"></p>';
  window.eval(script);
  expect(button()).toBeEnabled();
  expect(status()).toHaveTextContent('Vista lista para imprimir');
  expect(document.querySelector('img')).toBeNull();
  expect(window.print).not.toHaveBeenCalled();
});

it('does nothing on an error screen or an unrelated page', () => {
  document.body.innerHTML = '<p role="alert">No se pudo cargar</p>';
  window.eval(script);
  expect(window.print).not.toHaveBeenCalled();
  expect(fetch).not.toHaveBeenCalled();
});

it('keeps controls off paper, repeats table headings, and sizes readable QR images', () => {
  expect(styles).toContain('@media print');
  expect(styles).toContain('.print-actions, .ui-skip, .ui-sidebar, .ui-topbar { display:none!important; }');
  expect(styles).toContain('display:table-header-group');
  expect(styles).toContain('page-break-inside:avoid');
  expect(styles).toContain('width:40mm; height:40mm');
});

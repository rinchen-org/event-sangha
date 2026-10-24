import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Read the deployed legacy script directly without Vite's asset URL rewriting.
const script = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../src/static/qr-checkin.js'), 'utf8');

let visible = 'visible';
let navigation = 'navigate';
let submit: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  visible = 'visible';
  navigation = 'navigate';
  vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => visible as DocumentVisibilityState);
  Object.defineProperty(performance, 'getEntriesByType', { configurable: true, value: () => [{ type: navigation }] });
  submit = vi.spyOn(HTMLFormElement.prototype, 'requestSubmit').mockImplementation(() => {});
  document.body.innerHTML = `<form id="qr-checkin-form" data-auto-checkin="1">
    <input name="csrf_token" value="synthetic-csrf"><input name="review_token" value="synthetic-review">
    <select id="qr-session" name="selected_session_id"><option value="1">Mañana</option><option value="2">Tarde</option></select>
    <button name="decision" value="review" data-review-session>Revisar</button>
    <button name="decision" value="automatic" data-auto-submit hidden>Auto</button>
    <textarea name="notes"></textarea><button name="decision" value="allow">Permitir participación</button>
    <p id="qr-auto-status">Registrando…</p></form>`;
});
afterEach(() => { document.body.innerHTML = ''; });

it('submits the protected automatic form once without a confirmation click', () => {
  window.eval(script);
  expect(submit).toHaveBeenCalledExactlyOnceWith(document.querySelector('[data-auto-submit]'));
  document.dispatchEvent(new Event('visibilitychange'));
  document.dispatchEvent(new Event('visibilitychange'));
  expect(submit).toHaveBeenCalledTimes(1);
  expect(document.querySelector('input[name="csrf_token"]')).toHaveValue('synthetic-csrf');
  expect(document.querySelector('input[name="review_token"]')).toHaveValue('synthetic-review');
});
it('does not auto-post a manual/warning/result screen', () => {
  document.querySelector('form')!.removeAttribute('data-auto-checkin');
  window.eval(script);
  expect(submit).not.toHaveBeenCalled();
});
it.each(['reload', 'back_forward'])('does not automatically retry on %s navigation', type => {
  navigation = type;
  window.eval(script);
  expect(submit).not.toHaveBeenCalled();
  expect(document.getElementById('qr-auto-status')).toHaveTextContent('No se ha reenviado automáticamente');
});
it('waits until visible instead of submitting from a background page', () => {
  visible = 'hidden';
  window.eval(script);
  expect(submit).not.toHaveBeenCalled();
  visible = 'visible';
  document.dispatchEvent(new Event('visibilitychange'));
  expect(submit).toHaveBeenCalledTimes(1);
});
it('changing the selected period only requests a fresh read-only review', () => {
  visible = 'hidden';
  window.eval(script);
  const select = document.getElementById('qr-session') as HTMLSelectElement;
  select.value = '2';
  select.dispatchEvent(new Event('change'));
  visible = 'visible';
  document.dispatchEvent(new Event('visibilitychange'));
  expect(submit).toHaveBeenCalledExactlyOnceWith(document.querySelector('[data-review-session]'));
});
it('staff interaction cancels pending automation and keeps manual fallback', () => {
  visible = 'hidden';
  window.eval(script);
  document.querySelector('textarea')!.dispatchEvent(new Event('input', { bubbles: true }));
  visible = 'visible';
  document.dispatchEvent(new Event('visibilitychange'));
  expect(submit).not.toHaveBeenCalled();
  expect(document.querySelector('button[value="allow"]')).toBeInTheDocument();
});
it('leaving the page cancels deferred automation', () => {
  visible = 'hidden';
  window.eval(script);
  window.dispatchEvent(new Event('pagehide'));
  visible = 'visible';
  document.dispatchEvent(new Event('visibilitychange'));
  expect(submit).not.toHaveBeenCalled();
});
it('does not retry a failed submission', () => {
  submit.mockImplementation(() => { throw new Error('submission failed'); });
  expect(() => window.eval(script)).toThrow('submission failed');
  document.dispatchEvent(new Event('visibilitychange'));
  expect(submit).toHaveBeenCalledTimes(1);
});

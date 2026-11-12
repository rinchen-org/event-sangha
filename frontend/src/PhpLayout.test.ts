import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const script = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../src/static/ui.js'), 'utf8');
let small = true;
let change: (() => void) | undefined;
beforeEach(() => {
  small = true;
  change = undefined;
  vi.stubGlobal('matchMedia', vi.fn(() => ({ get matches() { return small; }, addEventListener: (_: string, fn: () => void) => { change = fn; } })));
  document.body.innerHTML = `<aside id="ui-sidebar"><button id="ui-nav-close" hidden>Close</button><a href="#eventos">Eventos</a><button id="logout">Cerrar sesión</button></aside>
    <button id="ui-backdrop" hidden></button><div class="ui-main-shell"><button id="ui-nav-toggle" aria-expanded="false" hidden>Menú</button><form><input name="csrf_token" value="unchanged"><button id="save">Guardar</button></form></div>`;
});
afterEach(() => { document.body.innerHTML = ''; document.body.className = ''; });

it('opens and closes the mobile menu without posting or changing a protected form', () => {
  const submit = vi.spyOn(HTMLFormElement.prototype, 'requestSubmit');
  window.eval(script);
  const sidebar = document.getElementById('ui-sidebar')!;
  const toggle = document.getElementById('ui-nav-toggle')!;
  const main = document.querySelector<HTMLElement>('.ui-main-shell')!;
  expect(sidebar.inert).toBe(true);
  toggle.click();
  expect(toggle).toHaveAttribute('aria-expanded','true');
  expect(sidebar.inert).toBe(false);
  expect(main.inert).toBe(true);
  expect(document.getElementById('ui-nav-close')).toHaveFocus();
  document.dispatchEvent(new KeyboardEvent('keydown', { key:'Escape' }));
  expect(toggle).toHaveAttribute('aria-expanded','false');
  expect(main.inert).toBe(false);
  expect(toggle).toHaveFocus();
  expect(document.querySelector('input[name="csrf_token"]')).toHaveValue('unchanged');
  expect(submit).not.toHaveBeenCalled();
});

it('traps drawer keyboard focus and supports its visible close control', () => {
  window.eval(script);
  document.getElementById('ui-nav-toggle')!.click();
  document.dispatchEvent(new KeyboardEvent('keydown', { key:'Tab', shiftKey:true, cancelable:true }));
  expect(document.getElementById('logout')).toHaveFocus();
  document.dispatchEvent(new KeyboardEvent('keydown', { key:'Tab', cancelable:true }));
  expect(document.getElementById('ui-nav-close')).toHaveFocus();
  document.getElementById('ui-nav-close')!.click();
  expect(document.body).not.toHaveClass('ui-nav-open');
});

it('keeps desktop navigation accessible and resets the drawer on resize', () => {
  window.eval(script);
  document.getElementById('ui-nav-toggle')!.click();
  small = false;
  change!();
  expect(document.getElementById('ui-sidebar')!.inert).toBe(false);
  expect(document.body).not.toHaveClass('ui-nav-open');
  expect(document.querySelector<HTMLElement>('.ui-main-shell')!.inert).toBe(false);
});

it('does nothing on compact QR screens', () => {
  document.body.innerHTML = '<form id="qr-checkin-form"><input name="review_token" value="unchanged"></form>';
  window.eval(script);
  expect(document.body).not.toHaveClass('ui-enhanced');
  expect(document.querySelector('input[name="review_token"]')).toHaveValue('unchanged');
});

it('leaves navigation unhidden when the enhancement is unsupported', () => {
  vi.stubGlobal('matchMedia', undefined);
  window.eval(script);
  expect(document.body).not.toHaveClass('ui-enhanced');
  expect(document.getElementById('ui-sidebar')).not.toHaveAttribute('hidden');
  expect(document.getElementById('ui-nav-toggle')).toHaveAttribute('hidden');
});

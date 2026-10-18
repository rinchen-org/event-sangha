import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';
import { TestEmailSender } from './TestEmailSender';

afterEach(() => vi.unstubAllGlobals());
function response(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, json: async () => body };
}
it('posts with CSRF and displays acceptance without claiming delivery', async () => {
  const fetcher = vi.fn()
    .mockResolvedValueOnce(response(200, { success: true, csrfToken: 'email-token' }))
    .mockResolvedValueOnce(response(200, { success: true, message: 'Aceptado; entrega no confirmada.' }));
  vi.stubGlobal('fetch', fetcher);
  render(<TestEmailSender />);
  await userEvent.click(screen.getByRole('button', { name: 'Test email sender' }));
  expect(await screen.findByRole('status')).toHaveTextContent('entrega no confirmada');
  const [url, options] = fetcher.mock.calls[1];
  expect(url).toContain('/templates/test-email.php');
  expect(options.method).toBe('POST');
  expect(options.body.get('csrf_token')).toBe('email-token');
});
it('displays server errors and does not automatically retry', async () => {
  const fetcher = vi.fn()
    .mockResolvedValueOnce(response(200, { success: true, csrfToken: 'token' }))
    .mockResolvedValueOnce(response(422, { success: false, message: 'Configura TEST_EMAIL_TO.' }));
  vi.stubGlobal('fetch', fetcher);
  render(<TestEmailSender />);
  await userEvent.click(screen.getByRole('button', { name: 'Test email sender' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Configura TEST_EMAIL_TO');
  expect(fetcher).toHaveBeenCalledTimes(2);
});
it('requires login after session expiry without sending mail', async () => {
  const fetcher = vi.fn().mockResolvedValue(response(401, {}));
  vi.stubGlobal('fetch', fetcher);
  render(<TestEmailSender />);
  await userEvent.click(screen.getByRole('button', { name: 'Test email sender' }));
  expect(await screen.findByRole('link', { name: 'Iniciar sesión' })).toHaveAttribute('href', expect.stringContaining('login.php'));
  expect(fetcher).toHaveBeenCalledTimes(1);
});
it('prevents concurrent clicks and explains ambiguous network failure', async () => {
  let fail!: (reason: Error) => void;
  vi.stubGlobal('fetch', vi.fn().mockImplementation(() => new Promise((_, reject) => { fail = reject; })));
  render(<TestEmailSender />);
  await userEvent.click(screen.getByRole('button', { name: 'Test email sender' }));
  expect(screen.getByRole('button', { name: 'Enviando…' })).toBeDisabled();
  fail(new Error('offline'));
  await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('No pudimos confirmar'));
  expect(fetch).toHaveBeenCalledTimes(1);
});

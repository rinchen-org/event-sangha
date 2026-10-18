import { useRef, useState } from 'react';
import { legacyUrl, loginUrl, preview } from '../data';

export function TestEmailSender() {
  const inFlight = useRef(false);
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState<{ success: boolean; message: string; login?: boolean } | null>(null);

  async function send() {
    if (preview || inFlight.current) return;
    inFlight.current = true;
    setPending(true);
    setResult(null);
    try {
      // Independent of dashboard/database availability; always get a fresh session token.
      const session = await fetch(legacyUrl('session.php'), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      if (session.status === 401) {
        setResult({ success: false, message: 'Inicia sesión para probar el envío de correo.', login: true });
        return;
      }
      if (!session.ok) throw new Error('session');
      const identity = await session.json();
      if (!identity.success || typeof identity.csrfToken !== 'string') throw new Error('session');
      const response = await fetch(legacyUrl('test-email.php'), {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json' },
        body: new URLSearchParams({ csrf_token: identity.csrfToken }),
      });
      if (response.status === 401) {
        setResult({ success: false, message: 'La sesión caducó. Inicia sesión de nuevo.', login: true });
        return;
      }
      const body = await response.json();
      if (typeof body.message !== 'string' || typeof body.success !== 'boolean') throw new Error('response');
      setResult({ success: response.ok && body.success, message: body.message });
    } catch {
      setResult({ success: false, message: 'No pudimos confirmar el resultado del envío. Revisa el correo y la conexión antes de intentarlo otra vez para evitar duplicados.' });
    } finally {
      inFlight.current = false;
      setPending(false);
    }
  }

  return <section className="panel email-test-panel" aria-label="Prueba de correo">
    <div><h2>Prueba de correo</h2><p>Envía un mensaje a las direcciones configuradas en TEST_EMAIL_TO.</p></div>
    <button className="button secondary" type="button" disabled={preview || pending} onClick={send}>
      {pending ? 'Enviando…' : 'Test email sender'}
    </button>
    {preview && <p>El envío está desactivado en la vista previa.</p>}
    {result && <p role={result.success ? 'status' : 'alert'}>{result.message} {result.login && <a href={loginUrl()}>Iniciar sesión</a>}</p>}
  </section>;
}

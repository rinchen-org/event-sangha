import { useState } from 'react';
import type { QrPreview } from '../types';

/** Displays only an existing protected image. Reading/failing to load never regenerates it. */
export function ParticipantQr({ qr, fullname, preview = false }: { qr?: QrPreview; fullname: string; preview?: boolean }) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  const url = preview ? null : qr?.url;
  const failed = !!url && failedUrl === url;
  return <div className="qr-preview">
    {url && !failed ? <a className="qr-preview-link" href={url} target="_blank" rel="noopener noreferrer" aria-label={`Abrir QR de ${fullname} a tamaño completo (nueva pestaña)`}>
      <img key={url} src={url} alt={`Código QR de ${fullname}`} width={128} height={128} loading="lazy" decoding="async" onError={() => setFailedUrl(url)} />
      <span>Abrir QR</span>
    </a> : <small className="qr-preview-error" role={failed ? 'status' : undefined}>{preview ? 'QR no disponible en la vista previa.' : failed ? 'No se pudo cargar el QR. Comprueba tu sesión o regenera el código.' : qr?.error || 'QR no disponible. Puedes regenerarlo.'}</small>}
    {!preview && qr?.warnings.map(warning => <small className="qr-preview-warning" key={warning.code}>{warning.message}</small>)}
  </div>;
}

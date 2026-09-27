import type { AppData } from './types';
// Explicit development-only preview. Never substituted for a failed live request.
const day = (offset: number, hour = 14) => {
  const date = new Date(); date.setUTCDate(date.getUTCDate() + offset); date.setUTCHours(hour, 0, 0, 0);
  return date.toISOString().slice(0, 19).replace('T', ' ');
};
export const previewData: AppData = {
  events: [
    { id: 1, name: 'Un encuentro con la calma', description: 'Tres días de enseñanzas, meditación y práctica compartida. Un espacio para volver a lo esencial.', start_date: day(3), end_date: day(5, 22) },
    { id: 2, name: 'El arte de estar presentes', description: 'Una jornada para cultivar la atención y llevar la práctica a nuestra vida cotidiana.', start_date: day(12), end_date: day(12, 22) },
    { id: 3, name: 'Sabiduría y compasión', description: 'Enseñanzas para abrir el corazón y caminar juntos con una mirada más amable.', start_date: day(-8), end_date: day(-7) },
  ],
  sessions: [
    { id: 1, event_id: 1, event_name: 'Un encuentro con la calma', name: 'Bienvenida y apertura', start_date: day(3, 13), end_date: day(3, 14) },
    { id: 2, event_id: 1, event_name: 'Un encuentro con la calma', name: 'Enseñanzas y meditación', start_date: day(3, 15), end_date: day(3, 17) },
    { id: 3, event_id: 1, event_name: 'Un encuentro con la calma', name: 'Práctica en comunidad', start_date: day(4, 14), end_date: day(4, 16) },
  ],
  subscriptions: ['Ana Flores', 'Diego Molina', 'Lucía Ríos', 'Mateo Vargas', 'Sofía Torres', 'Pablo Luna', 'Elena Castro', 'Daniel Paz'].map((fullname, i) => ({ id: i + 1, person_id: i + 1, fullname, email: `participante${i + 1}@example.com`, phone: '000 000 000', active: i === 3 || i === 6 ? 0 : 1, datetime: day(-i) })),
  attendance: [0, 1, 2].map(i => ({ id: i + 1, person_id: i + 1, fullname: ['Ana Flores', 'Diego Molina', 'Lucía Ríos'][i], event_name: 'Sabiduría y compasión', session_name: 'Enseñanzas de la mañana', event_session_id: 4, log_time: day(-8, 14 + i) })),
};

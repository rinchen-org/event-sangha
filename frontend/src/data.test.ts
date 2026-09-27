import { describe, expect, it } from 'vitest';
import { csvContent, eventStatus, formatDate, inputDate, matchesSearch } from './data';

describe('Bolivia dates and existing UTC storage', () => {
  it('shows the previous local day for early UTC timestamps', () => {
    expect(inputDate('2026-09-27 02:30:00')).toBe('2026-09-26');
    expect(inputDate('2026-09-27 02:30:00', true)).toBe('2026-09-26T22:30');
    expect(formatDate('2026-09-27 02:30:00', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })).toBe('22:30');
  });
  it('distinguishes upcoming, current and completed events', () => {
    expect(eventStatus('2026-09-27 12:00:00', '2026-09-27 20:00:00', Date.parse('2026-09-27T10:00:00Z')).className).toBe('upcoming');
    expect(eventStatus('2026-09-27 12:00:00', '2026-09-27 20:00:00', Date.parse('2026-09-27T15:00:00Z')).className).toBe('active');
    expect(eventStatus('2026-09-27 12:00:00', '2026-09-27 20:00:00', Date.parse('2026-09-28T00:00:00Z')).className).toBe('neutral');
  });
});
it('finds Spanish names without requiring accents or case', () => {
  expect(matchesSearch(['Lucía Ríos', 'lucia@example.com'], 'LUCIA RIOS')).toBe(true);
  expect(matchesSearch(['Lucía Ríos'], 'Pedro')).toBe(false);
});
it('quotes CSV fields and neutralizes spreadsheet formulas', () => {
  const csv = csvContent(['Nombre', 'Teléfono'], [['Ana, "Ríos"', '+591123456'], ['=HYPERLINK("x")', '@data']]);
  expect(csv).toContain('"Ana, ""Ríos"""');
  expect(csv).toContain('"\'+591123456"');
  expect(csv).toContain('"\'=HYPERLINK(""x"")"');
  expect(csv).toContain('"\'@data"');
});

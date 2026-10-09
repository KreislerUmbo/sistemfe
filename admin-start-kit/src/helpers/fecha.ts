// Regla única de fechas (08-oct-2026): el backend guarda los instantes en UTC y los
// manda en ISO con zona ("...Z"); acá se muestran SIEMPRE en hora de Perú, sin importar
// la zona horaria configurada en la PC del usuario.
export const ZONA_PERU = 'America/Lima';

// Laravel devuelve fechas 'date' cast como timestamp ISO completo
// (ej. "2026-01-01T00:00:00.000000Z"). Cortar a los primeros 10
// caracteres y concatenar 'T00:00:00' antes de construir el Date
// evita que una medianoche UTC se corra un día para atrás en
// zonas horarias detrás de UTC (Perú, UTC-5) — mismo bug ya
// resuelto puntualmente en cotizador/editar.vue y reservas/detalle.vue,
// ahora centralizado acá.
export function formatFecha(f?: string | null): string {
  if (!f) return 'sin fecha';
  const d = new Date(f.slice(0, 10) + 'T00:00:00');
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}/${mm}/${d.getFullYear()}`;
}

const formatoPeru = new Intl.DateTimeFormat('en-GB', {
  timeZone: ZONA_PERU, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
});

// Para timestamps donde la hora SÍ importa (created_at/updated_at, marcas de
// tiempo de eventos) — a diferencia de formatFecha(), acá NO se trunca a los
// primeros 10 caracteres.
// - Con zona ("...T14:30:00.000000Z" o "...-05:00"): es un instante real y se
//   convierte a hora de Perú.
// - Sin zona ("2026-10-08 14:30:00", "2026-10-08 02:30 PM"): el backend ya lo
//   formateó en hora de Perú; se muestra tal cual, sin pasarlo por la zona de la PC.
export function formatFechaHora(f?: string | null): string {
  if (!f) return 'sin fecha';
  const sinZona = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?(?:\s*([AP]M))?$/i.exec(f.trim());
  if (sinZona) {
    const [, anio, mes, dia, h, mi, ampm] = sinZona;
    let hora = Number(h);
    if (ampm) hora = (hora % 12) + (ampm.toUpperCase() === 'PM' ? 12 : 0);
    return `${dia}/${mes}/${anio} ${String(hora).padStart(2, '0')}:${mi}`;
  }
  const d = new Date(f);
  if (isNaN(d.getTime())) return 'sin fecha';
  const p = Object.fromEntries(formatoPeru.formatToParts(d).map((x) => [x.type, x.value]));
  return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`;
}

/** Fecha de hoy en Perú como "YYYY-MM-DD". Nunca new Date().toISOString(): de 19:00 a 24:00 da mañana. */
export function hoyPeru(ahora: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: ZONA_PERU, year: 'numeric', month: '2-digit', day: '2-digit' }).format(ahora);
}

/** "YYYY-MM-DD" + n días, aritmética de calendario pura (sin zona horaria). */
export function sumarDiasISO(ymd: string, dias: number): string {
  const [a, m, d] = ymd.slice(0, 10).split('-').map(Number);
  return new Date(Date.UTC(a, m - 1, d + dias)).toISOString().slice(0, 10);
}

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

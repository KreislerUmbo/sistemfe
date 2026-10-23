// Cómo se nombra a un pasajero de la reserva en pantalla (detalle de la
// reserva y modales de facturar). Un pasajero sin nombre todavía se
// identifica por su tipo y su número.
import type { ReservaPasajero } from '@/types/agencia-viajes';

export function etiquetaTipoPax(tipo?: string | null): string {
  if (tipo === 'adulto') return 'Adulto';
  if (tipo === 'nino') return 'Niño';
  if (tipo === 'infante') return 'Infante';
  return '—';
}

export function nombrePasajero(p: ReservaPasajero): string {
  return p.nombre || `${etiquetaTipoPax(p.tipo_pax)} #${p.id} (sin datos todavía)`;
}

// Qué pasajeros se mandan al facturar: los pendientes; si ya no queda
// ninguno pero sí servicios sueltos, todos (el backend acepta pasajeros ya
// facturados solo para arrastrar esos servicios — F1, 23-oct-2026).
export function pasajeroIdsParaFacturar(todos: ReservaPasajero[], pendientes: ReservaPasajero[]): number[] {
  return (pendientes.length > 0 ? pendientes : todos).map((p) => p.id);
}

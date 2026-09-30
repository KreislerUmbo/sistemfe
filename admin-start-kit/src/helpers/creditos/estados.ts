// Módulo Créditos — estados con color + texto (00 §4: nunca solo color). Funciones puras,
// compartidas por el detalle, el listado y la cobranza del día.
import type { CreditoEstado, Cuota, FrecuenciaUnidad } from '@/types/creditos'

export type EstadoVisualCuota = 'pagada' | 'vencida' | 'hoy' | 'pendiente' | 'anulada'

export interface Presentacion {
  texto: string
  clase: string
  icono: string
}

/** Vencida = fecha_vencimiento < hoy (00 §0); la que vence hoy es "hoy", no vencida. */
export function estadoCuota(cuota: Pick<Cuota, 'estado' | 'fecha_vencimiento'>, hoy: string): EstadoVisualCuota {
  if (cuota.estado === 'pagada' || cuota.estado === 'anulada') return cuota.estado
  if (cuota.fecha_vencimiento < hoy) return 'vencida'
  if (cuota.fecha_vencimiento === hoy) return 'hoy'
  return 'pendiente'
}

export const PRESENTACION_CUOTA: Record<EstadoVisualCuota, Presentacion> = {
  pagada: { texto: 'Pagada', clase: 'text-success', icono: 'fas fa-check-circle' },
  vencida: { texto: 'Vencida', clase: 'text-danger', icono: 'fas fa-exclamation-circle' },
  hoy: { texto: 'Vence hoy', clase: 'text-primary', icono: 'fas fa-adjust' },
  pendiente: { texto: 'Pendiente', clase: 'text-muted', icono: 'far fa-circle' },
  anulada: { texto: 'Anulada', clase: 'text-muted text-decoration-line-through', icono: 'fas fa-ban' },
}

export const PRESENTACION_CREDITO: Record<CreditoEstado, Presentacion> = {
  borrador: { texto: 'Borrador', clase: 'bg-secondary-subtle text-secondary-emphasis', icono: 'far fa-edit' },
  activo: { texto: 'Activo', clase: 'bg-success-subtle text-success-emphasis', icono: 'fas fa-check' },
  castigado: { texto: 'Castigado', clase: 'bg-danger-subtle text-danger-emphasis', icono: 'fas fa-gavel' },
  finalizado: { texto: 'Finalizado', clase: 'bg-primary-subtle text-primary-emphasis', icono: 'fas fa-flag-checkered' },
  anulado: { texto: 'Anulado', clase: 'bg-dark-subtle text-dark-emphasis', icono: 'fas fa-ban' },
}

const FRECUENCIA: Record<FrecuenciaUnidad, [string, string]> = {
  dia: ['Diario', 'Cada {n} días'],
  semana: ['Semanal', 'Cada {n} semanas'],
  quincena: ['Quincenal', 'Cada {n} quincenas'],
  mes: ['Mensual', 'Cada {n} meses'],
  anio: ['Anual', 'Cada {n} años'],
}

export function textoFrecuencia(unidad: FrecuenciaUnidad, intervalo: number): string {
  if (unidad === 'dia' && intervalo === 15) return 'Quincenal'
  const [simple, varios] = FRECUENCIA[unidad]
  return intervalo === 1 ? simple : varios.replace('{n}', String(intervalo))
}

/** "Lun 12/10" para las filas de cuotas (mockup 2). Construye la fecha en UTC para no correr el día. */
export function fechaCorta(ymd: string): string {
  const [anio, mes, dia] = ymd.slice(0, 10).split('-').map(Number)
  const nombre = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][new Date(Date.UTC(anio, mes - 1, dia)).getUTCDay()]
  return `${nombre} ${String(dia).padStart(2, '0')}/${String(mes).padStart(2, '0')}`
}

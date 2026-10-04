// Módulo Créditos — panel y reportes (04d): rangos de fecha rápidos, textos y el mensaje de
// recordatorio de la agenda. Sin aritmética de dinero: los montos llegan sumados del backend.
import { formatoFecha, formatoSoles } from './formato'
import type { AgrupacionIngresos, FilaAgenda, FiltrosReporte, NombreReporte, RangoAtraso } from '@/types/creditos'

/** "2026-10-02" + n días → "YYYY-MM-DD" (en UTC: sin corrimientos de zona horaria). */
export function sumarDias(ymd: string, dias: number): string {
  const [anio, mes, dia] = ymd.split('-').map(Number)
  return new Date(Date.UTC(anio, mes - 1, dia + dias)).toISOString().slice(0, 10)
}

/** Lunes de la semana de una fecha. */
export function lunesDe(ymd: string): string {
  const [anio, mes, dia] = ymd.split('-').map(Number)
  const diaSemana = new Date(Date.UTC(anio, mes - 1, dia)).getUTCDay() || 7
  return sumarDias(ymd, 1 - diaSemana)
}

export interface RangoFechas { desde: string; hasta: string }

export type RangoAgenda = 'hoy' | 'manana' | 'pasado' | 'semana' | 'rango'
export const RANGOS_AGENDA: { id: RangoAgenda; texto: string }[] = [
  { id: 'manana', texto: 'Mañana' },
  { id: 'pasado', texto: 'Pasado mañana' },
  { id: 'semana', texto: 'Próximos 7 días' },
  { id: 'hoy', texto: 'Hoy' },
  { id: 'rango', texto: 'Rango' },
]

/** Fechas de un acceso rápido de la agenda; 'rango' no tiene fechas propias (null). */
export function fechasAgenda(rango: RangoAgenda, hoy: string): RangoFechas | null {
  switch (rango) {
    case 'hoy': return { desde: hoy, hasta: hoy }
    case 'manana': return { desde: sumarDias(hoy, 1), hasta: sumarDias(hoy, 1) }
    case 'pasado': return { desde: sumarDias(hoy, 2), hasta: sumarDias(hoy, 2) }
    case 'semana': return { desde: sumarDias(hoy, 1), hasta: sumarDias(hoy, 7) }
    default: return null
  }
}

export type RangoPeriodo = 'hoy' | 'semana' | 'mes' | 'rango'
export const RANGOS_PERIODO: { id: RangoPeriodo; texto: string }[] = [
  { id: 'hoy', texto: 'Hoy' },
  { id: 'semana', texto: 'Esta semana' },
  { id: 'mes', texto: 'Este mes' },
  { id: 'rango', texto: 'Rango' },
]

/** Fechas de un acceso rápido de los reportes por período (hasta hoy). */
export function fechasPeriodo(rango: RangoPeriodo, hoy: string): RangoFechas | null {
  switch (rango) {
    case 'hoy': return { desde: hoy, hasta: hoy }
    case 'semana': return { desde: lunesDe(hoy), hasta: hoy }
    case 'mes': return { desde: `${hoy.slice(0, 7)}-01`, hasta: hoy }
    default: return null
  }
}

export const TEXTOS_RANGO: Record<RangoAtraso, string> = {
  al_dia: 'Al día',
  '1-7': '1 a 7 días',
  '8-15': '8 a 15 días',
  '16-30': '16 a 30 días',
  '31-60': '31 a 60 días',
  '60+': 'Más de 60 días',
}

/** Encabezado de un grupo de la agenda: el día o el grupo de atrasados (dia null). */
export function tituloDiaAgenda(dia: string | null, hoy: string): string {
  if (dia === null) return 'Ya atrasados'
  if (dia === hoy) return `Hoy · ${formatoFecha(dia)}`
  if (dia === sumarDias(hoy, 1)) return `Mañana · ${formatoFecha(dia)}`
  if (dia === sumarDias(hoy, 2)) return `Pasado mañana · ${formatoFecha(dia)}`
  return formatoFecha(dia)
}

/** Etiqueta de una fila de Ingresos según la agrupación ("2026-09" → "09/2026"). */
export function etiquetaPeriodo(clave: string, agrupacion: AgrupacionIngresos): string {
  if (agrupacion === 'mes') {
    const [anio, mes] = clave.split('-')
    return `${mes}/${anio}`
  }
  if (agrupacion === 'semana') return `Semana del ${formatoFecha(clave)}`
  return formatoFecha(clave)
}

/** ¿La cuota ya venció? (con_atraso de la API es del crédito, no de esta cuota). */
export function cuotaVencida(fila: Pick<FilaAgenda, 'fecha_vencimiento'>, hoy: string): boolean {
  return fila.fecha_vencimiento < hoy
}

/**
 * Recordatorio de WhatsApp para un cliente de la agenda. La mora se menciona aparte, sin
 * sumarla al pendiente (el total lo calcula el backend al cobrar).
 */
export function mensajeRecordatorio(fila: Pick<FilaAgenda, 'numero_credito' | 'numero_cuota' | 'cuotas_total' | 'pendiente' | 'mora' | 'fecha_vencimiento'> & { cliente: { nombre: string } }, hoy: string, empresa = ''): string {
  const nombre = fila.cliente.nombre.split(' ')[0] || fila.cliente.nombre
  const cuota = `la cuota ${fila.numero_cuota} de ${fila.cuotas_total} de su crédito ${fila.numero_credito}`
  const monto = formatoSoles(fila.pendiente)
  const fecha = formatoFecha(fila.fecha_vencimiento)
  const cuerpo = cuotaVencida(fila, hoy)
    ? `le recordamos que ${cuota}, por ${monto}, venció el ${fecha}.`
    : `le recordamos que ${cuota}, por ${monto}, vence el ${fecha}.`
  const mora = Number.parseFloat(fila.mora) > 0 ? ` Tiene además una mora de ${formatoSoles(fila.mora)}.` : ''
  const firma = empresa ? ` ${empresa}.` : ''

  // Espacios normales: Intl separa "S/" del número con uno no separable.
  return `Hola ${nombre}, ${cuerpo}${mora} Gracias.${firma}`.replace(/\u00a0/g, ' ')
}

const TITULOS_ARCHIVO: Record<NombreReporte, string> = {
  agenda: 'agenda_de_cobranza',
  cartera: 'cartera_de_creditos',
  morosidad: 'morosidad',
  ingresos: 'ingresos_y_desembolsos',
  asesores: 'resultados_por_asesor',
  castigados: 'castigados_y_recuperos',
  control: 'control_y_auditoria',
}

/** Nombre de descarga del Excel: reporte + fechas del filtro (o la fecha de corte). */
export function nombreArchivoReporte(nombre: NombreReporte, filtros: FiltrosReporte, corte: string): string {
  const fechas = [...new Set([filtros.desde, filtros.hasta].filter((f): f is string => !!f))]
  const sufijo = fechas.length ? fechas.join('_al_') : corte
  return `${TITULOS_ARCHIVO[nombre]}_${sufijo}.xlsx`
}

/** Descarga un blob con un nombre (Excel de reportes). */
export function descargarBlob(blob: Blob, nombre: string): void {
  const enlace = document.createElement('a')
  enlace.href = URL.createObjectURL(blob)
  enlace.download = nombre
  enlace.click()
  setTimeout(() => URL.revokeObjectURL(enlace.href), 10_000)
}

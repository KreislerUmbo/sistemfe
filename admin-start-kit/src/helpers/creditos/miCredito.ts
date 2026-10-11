// Módulo Créditos (Fase 5b) — textos de "Mi crédito", la página del cliente. Puro (sin Vue) para
// probarlo con Vitest. Fechas "YYYY-MM-DD" de negocio: se leen como fecha, sin zona horaria.
import { formatoFecha } from './formato'

const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']

function diasEntre(desde: string, hasta: string): number {
  const a = Date.UTC(+desde.slice(0, 4), +desde.slice(5, 7) - 1, +desde.slice(8, 10))
  const b = Date.UTC(+hasta.slice(0, 4), +hasta.slice(5, 7) - 1, +hasta.slice(8, 10))
  return Math.round((b - a) / 86_400_000)
}

/** "sábado 31/01/2026" */
export function fechaConDia(ymd: string): string {
  const dia = new Date(Date.UTC(+ymd.slice(0, 4), +ymd.slice(5, 7) - 1, +ymd.slice(8, 10))).getUTCDay()
  return `${DIAS[dia]} ${formatoFecha(ymd)}`
}

/** "vence hoy" / "vence mañana" / "vence el sábado 31/01/2026" */
export function textoVence(fecha: string, hoy: string): string {
  const faltan = diasEntre(hoy, fecha)
  if (faltan === 0) return 'vence hoy'
  if (faltan === 1) return 'vence mañana'
  return `vence el ${fechaConDia(fecha)}`
}

/** "1 cuota vencida · 5 días de atraso"; sin cuotas vencidas, solo queda mora por pagar. */
export function textoVencido(v: { cuotas: number; dias_atraso: number }): string {
  if (v.cuotas === 0) return 'Tienes mora pendiente de pago'
  const cuotas = v.cuotas === 1 ? '1 cuota vencida' : `${v.cuotas} cuotas vencidas`
  const dias = v.dias_atraso === 1 ? '1 día de atraso' : `${v.dias_atraso} días de atraso`
  return v.dias_atraso > 0 ? `${cuotas} · ${dias}` : cuotas
}

export function primerNombre(nombre: string): string {
  const primero = nombre.trim().split(/\s+/)[0] ?? ''
  return primero ? primero.charAt(0).toUpperCase() + primero.slice(1).toLowerCase() : ''
}

/** Solo letras y números, en mayúscula, máximo 4: lo que se escribe en el campo del documento. */
export function limpiarDigitos(texto: string): string {
  return texto.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 4)
}

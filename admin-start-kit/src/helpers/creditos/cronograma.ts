// Módulo Créditos — textos del formulario y del cronograma previo (revisión UX 01-oct-2026).
// Funciones puras; no calculan dinero ni fechas: explican lo que devolvió el preview.
import type { CuotaPrevia, ReglaNoLaborable } from '@/types/creditos'
import type { FormaPago } from './formulario'

const MAXIMO_COMPLETO = 6
const PRIMERAS = 3

export type FilaCronograma = { tipo: 'cuota'; cuota: CuotaPrevia } | { tipo: 'resto'; cantidad: number }

/** Hasta 6 cuotas se ven todas; si son más: 1-3, "⋯ N cuotas más" y la última. Nunca salta filas sin decirlo. */
export function filasCronograma(cuotas: CuotaPrevia[], completo: boolean): FilaCronograma[] {
  if (completo || cuotas.length <= MAXIMO_COMPLETO) return cuotas.map((cuota) => ({ tipo: 'cuota', cuota }))
  return [
    ...cuotas.slice(0, PRIMERAS).map((cuota): FilaCronograma => ({ tipo: 'cuota', cuota })),
    { tipo: 'resto', cantidad: cuotas.length - PRIMERAS - 1 },
    { tipo: 'cuota', cuota: cuotas[cuotas.length - 1] },
  ]
}

export const cronogramaResumido = (cuotas: CuotaPrevia[]) => cuotas.length > MAXIMO_COMPLETO

const corta = (ymd: string) => `${ymd.slice(8, 10)}/${ymd.slice(5, 7)}`

/** "11/01 es domingo → se pasó al 12/01" (o "se adelantó al", según la regla). Null si no se movió. */
export function textoAjuste(c: Pick<CuotaPrevia, 'fecha_original' | 'fecha_vencimiento' | 'motivo_ajuste'>): string | null {
  if (!c.fecha_original) return null
  const destino = c.fecha_vencimiento < c.fecha_original ? 'se adelantó al' : 'se pasó al'
  const motivo = c.motivo_ajuste ?? ''
  if (motivo === 'no se pudo adelantar') return `${corta(c.fecha_original)} no tenía día hábil antes → ${destino} ${corta(c.fecha_vencimiento)}`
  const razon = motivo.startsWith('feriado: ') ? `es feriado (${motivo.slice('feriado: '.length)})` : `es ${motivo || 'día sin cobro'}`
  return `${corta(c.fecha_original)} ${razon} → ${destino} ${corta(c.fecha_vencimiento)}`
}

/**
 * Texto del checkbox de domingos según lo que de verdad hace: en pago diario el domingo no
 * genera cuota; en las demás formas el pago que cae domingo se mueve según la regla elegida.
 */
export function textoDomingos(formaPago: FormaPago, regla: ReglaNoLaborable): { etiqueta: string; ayuda: string | null } {
  if (formaPago === 'diario') return { etiqueta: 'No cobrar domingos', ayuda: 'Los domingos no tienen cuota; el cronograma se alarga.' }
  if (regla === 'anterior') return { etiqueta: 'Si un pago cae domingo, adelantarlo al sábado', ayuda: null }
  if (regla === 'mantener') {
    return { etiqueta: 'Domingo sin cobro', ayuda: 'Con la regla "Mantener la fecha" (Opciones avanzadas) el pago no se mueve.' }
  }
  return { etiqueta: 'Si un pago cae domingo, pasarlo al lunes', ayuda: null }
}

/** "30 pagos de S/ 40.00" o "29 pagos de S/ 40.00 + 1 de S/ 34.30" (compara montos como texto). */
export function textoPagos(cuotas: Pick<CuotaPrevia, 'monto_total'>[], formato: (monto: string) => string): string {
  const grupos: { monto: string; cantidad: number }[] = []
  for (const c of cuotas) {
    const ultimo = grupos[grupos.length - 1]
    if (ultimo && ultimo.monto === c.monto_total) ultimo.cantidad++
    else grupos.push({ monto: c.monto_total, cantidad: 1 })
  }
  const texto = (g: { monto: string; cantidad: number }, primero: boolean) =>
    `${g.cantidad} ${g.cantidad === 1 ? (primero ? 'pago' : '') : 'pagos'} de ${formato(g.monto)}`.replace(/\s+/g, ' ')

  if (grupos.length <= 2) return grupos.map((g, i) => texto(g, i === 0)).join(' + ')
  return `${cuotas.length} pagos · primero ${formato(grupos[0].monto)}`
}

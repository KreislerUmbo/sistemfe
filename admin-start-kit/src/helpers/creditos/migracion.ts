// Módulo Créditos — Registrar crédito existente (04-frontend pantalla 6, 00 1.12): validación
// local de los pagos históricos del modo detallado. El backend vuelve a validar todo.
import { normalizarMonto } from './cobro'

export interface FilaPagoHistorico {
  id: number
  fecha: string
  monto: string
}

export interface PagosValidados {
  /** null si alguna fila tiene error. Ordenados por fecha. */
  pagos: { fecha: string; monto: string }[] | null
  /** id de fila → mensaje. */
  errores: Record<number, string>
}

export function validarPagosHistoricos(filas: FilaPagoHistorico[], desembolso: string, hoy: string): PagosValidados {
  const errores: Record<number, string> = {}
  const pagos: { fecha: string; monto: string }[] = []
  for (const fila of filas) {
    const monto = normalizarMonto(fila.monto)
    if (!fila.fecha) errores[fila.id] = 'Indica la fecha del pago.'
    else if (fila.fecha < desembolso || fila.fecha > hoy) errores[fila.id] = 'La fecha debe estar entre la entrega del dinero y hoy.'
    else if (!monto) errores[fila.id] = 'Monto inválido.'
    else pagos.push({ fecha: fila.fecha, monto })
  }
  if (!filas.length) return { pagos: null, errores }
  // sort estable: dos pagos del mismo día conservan el orden en que se escribieron.
  return { pagos: Object.keys(errores).length ? null : [...pagos].sort((a, b) => a.fecha.localeCompare(b.fecha)), errores }
}

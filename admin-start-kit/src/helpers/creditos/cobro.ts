// Módulo Créditos — textos de la pantalla Cobrar (04-frontend pantalla 3, mockup 3). Solo
// arma etiquetas y elige entre montos que ya calculó el backend: no suma ni resta dinero.
import { fechaCorta } from './estados'
import { formatoFecha } from './formato'
import type { CotizacionPago, DeudaHoy, ResumenDetalle } from '@/types/creditos'

export interface FilaDeuda {
  clave: string
  texto: string
  monto: string
  mora: boolean
}

/** Desglose "Deuda de hoy": una fila por cuota y otra por su mora, si la tiene. */
export function filasDeuda(lineas: DeudaHoy[], hoy: string): FilaDeuda[] {
  return lineas.flatMap((l) => {
    const filas: FilaDeuda[] = []
    if (l.pendiente !== '0.00') {
      const cuando = l.vencida
        ? `vencida ${formatoFecha(l.fecha_vencimiento).slice(0, 5)}`
        : l.fecha_vencimiento === hoy ? 'vence hoy' : `vence ${formatoFecha(l.fecha_vencimiento).slice(0, 5)}`
      filas.push({ clave: `c${l.numero_cuota}`, texto: `Cuota ${l.numero_cuota} · ${cuando}`, monto: l.pendiente, mora: false })
    }
    if (l.mora !== '0.00') {
      // Una cuota ya pagada que sigue debiendo mora tiene 0 días de atraso: no se muestran.
      const dias = l.dias_atraso > 0 ? ` · ${l.dias_atraso} día${l.dias_atraso === 1 ? '' : 's'}` : ''
      filas.push({ clave: `m${l.numero_cuota}`, texto: `Mora cuota ${l.numero_cuota}${dias}${l.mora_tope_alcanzado ? ' (tope)' : ''}`, monto: l.mora, mora: true })
    }
    return filas
  })
}

export interface Atajo {
  id: 'cuota' | 'al_dia'
  texto: string
  monto: string
}

/** Montos rápidos: la próxima cuota y, si hay atraso, ponerse al día (= exigible). */
export function atajosCobro(resumen: Pick<ResumenDetalle, 'proxima' | 'exigible_hoy'>, hoy: string): Atajo[] {
  const atajos: Atajo[] = []
  const { proxima, exigible_hoy: exigible } = resumen
  if (proxima && proxima.pendiente !== '0.00') {
    atajos.push({ id: 'cuota', texto: proxima.fecha_vencimiento === hoy ? 'Cuota de hoy' : 'Próxima cuota', monto: proxima.pendiente })
  }
  if (exigible !== '0.00' && exigible !== proxima?.pendiente) {
    atajos.push({ id: 'al_dia', texto: 'Ponerse al día', monto: exigible })
  }
  return atajos
}

const CONCEPTO = { interes: 'interés', capital: 'capital', cargo: 'cargo', mora: 'mora' } as const

/** "Así se aplicará el pago": una fila por línea del reparto del motor. */
export function textoAplicacion(a: CotizacionPago['aplicacion'][number]): string {
  if (a.concepto === 'mora') return `Mora cuota ${a.numero_cuota}`
  if (a.concepto === 'cargo') return `Cargo cuota ${a.numero_cuota}`
  return `Cuota ${a.numero_cuota} · ${CONCEPTO[a.concepto]}`
}

export function textoProxima(p: CotizacionPago['proxima_despues'], formato: (m: string) => string): string {
  return p ? `${fechaCorta(p.fecha_vencimiento)} · ${formato(p.pendiente)}` : '—'
}

/**
 * Texto que escribe el cajero → monto para la API ("120", "120,5" → "120.50"), o null si
 * no es un monto válido y mayor que cero. Es manipulación de texto, no aritmética.
 */
export function normalizarMonto(texto: string): string | null {
  const limpio = texto.trim().replace(/\s/g, '').replace(',', '.')
  const coincide = /^(\d{1,9})(?:\.(\d{1,2}))?$/.exec(limpio)
  if (!coincide) return null
  const entero = coincide[1].replace(/^0+(?=\d)/, '')
  const decimales = (coincide[2] ?? '').padEnd(2, '0')
  if (/^0+$/.test(entero) && decimales === '00') return null
  return `${entero}.${decimales}`
}

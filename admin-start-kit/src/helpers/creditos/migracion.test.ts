import { describe, expect, it } from 'vitest'
import { validarPagosHistoricos } from './migracion'

const DESEMBOLSO = '2026-09-01'
const HOY = '2026-09-30'

describe('validarPagosHistoricos', () => {
  it('normaliza montos y ordena por fecha', () => {
    const r = validarPagosHistoricos([
      { id: 1, fecha: '2026-09-15', monto: '120' },
      { id: 2, fecha: '2026-09-08', monto: '120,5' },
    ], DESEMBOLSO, HOY)
    expect(r.errores).toEqual({})
    expect(r.pagos).toEqual([{ fecha: '2026-09-08', monto: '120.50' }, { fecha: '2026-09-15', monto: '120.00' }])
  })

  it('marca la fila con fecha fuera de rango o monto inválido', () => {
    const r = validarPagosHistoricos([
      { id: 1, fecha: '2026-08-31', monto: '10' },
      { id: 2, fecha: '2026-10-01', monto: '10' },
      { id: 3, fecha: '2026-09-10', monto: 'abc' },
      { id: 4, fecha: '', monto: '10' },
    ], DESEMBOLSO, HOY)
    expect(r.pagos).toBeNull()
    expect(Object.keys(r.errores)).toEqual(['1', '2', '3', '4'])
  })

  it('sin filas no hay pagos que enviar', () => {
    expect(validarPagosHistoricos([], DESEMBOLSO, HOY).pagos).toBeNull()
  })
})

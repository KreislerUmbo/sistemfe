import { describe, expect, it } from 'vitest'
import { filasCronograma, textoAjuste, textoDomingos, textoPagos } from './cronograma'
import type { CuotaPrevia } from '@/types/creditos'

const cuota = (n: number, extra: Partial<CuotaPrevia> = {}): CuotaPrevia => ({
  numero_cuota: n, fecha_vencimiento: '2026-01-12', monto_capital: '10.00', monto_interes: '2.00', monto_total: '12.00',
  fecha_forzada_a_siguiente: false, fecha_original: null, motivo_ajuste: null, feriado: null, ...extra,
})
const cuotas = (n: number) => Array.from({ length: n }, (_, i) => cuota(i + 1))

describe('filasCronograma', () => {
  it('hasta 6 cuotas se ven todas', () => {
    expect(filasCronograma(cuotas(6), false).map((f) => f.tipo)).toEqual(Array(6).fill('cuota'))
  })

  it('con más de 6: 1-3, una fila "N cuotas más" y la última', () => {
    const filas = filasCronograma(cuotas(30), false)
    expect(filas.map((f) => (f.tipo === 'cuota' ? f.cuota.numero_cuota : `+${f.cantidad}`))).toEqual([1, 2, 3, '+26', 30])
  })

  it('completo muestra todas', () => {
    expect(filasCronograma(cuotas(30), true)).toHaveLength(30)
  })
})

describe('textoAjuste', () => {
  it('domingo y feriado', () => {
    expect(textoAjuste({ fecha_original: '2026-01-11', fecha_vencimiento: '2026-01-12', motivo_ajuste: 'domingo' })).toBe('11/01 es domingo → se pasó al 12/01')
    expect(textoAjuste({ fecha_original: '2026-12-25', fecha_vencimiento: '2026-12-26', motivo_ajuste: 'feriado: Navidad' }))
      .toBe('25/12 es feriado (Navidad) → se pasó al 26/12')
  })

  it('con la regla "anterior" dice que se adelantó', () => {
    expect(textoAjuste({ fecha_original: '2026-01-11', fecha_vencimiento: '2026-01-10', motivo_ajuste: 'domingo' })).toBe('11/01 es domingo → se adelantó al 10/01')
  })

  it('sin ajuste no dice nada', () => {
    expect(textoAjuste({ fecha_original: null, fecha_vencimiento: '2026-01-12', motivo_ajuste: null })).toBeNull()
  })
})

describe('textoDomingos', () => {
  it('diario: el domingo no tiene cuota', () => {
    expect(textoDomingos('diario', 'siguiente').etiqueta).toBe('No cobrar domingos')
  })

  it('otras formas: según la regla', () => {
    expect(textoDomingos('semanal', 'siguiente').etiqueta).toBe('Si un pago cae domingo, pasarlo al lunes')
    expect(textoDomingos('mensual', 'anterior').etiqueta).toBe('Si un pago cae domingo, adelantarlo al sábado')
    expect(textoDomingos('mensual', 'mantener').ayuda).toMatch(/no se mueve/)
  })
})

describe('textoPagos', () => {
  const soles = (m: string) => `S/ ${m}`

  it('agrupa la última cuota distinta', () => {
    expect(textoPagos([...Array(29).fill({ monto_total: '40.00' }), { monto_total: '34.30' }], soles)).toBe('29 pagos de S/ 40.00 + 1 de S/ 34.30')
    expect(textoPagos([{ monto_total: '40.00' }], soles)).toBe('1 pago de S/ 40.00')
  })
})

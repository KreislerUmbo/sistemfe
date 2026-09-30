import { describe, expect, it } from 'vitest'
import { atajosCobro, filasDeuda, normalizarMonto, textoAplicacion } from './cobro'
import type { DeudaHoy } from '@/types/creditos'

const HOY = '2026-10-14'

const linea = (parcial: Partial<DeudaHoy>): DeudaHoy => ({
  numero_cuota: 11, fecha_vencimiento: '2026-10-13', vencida: true, pendiente: '40.00', mora: '0.00',
  dias_atraso: 0, mora_tope_alcanzado: false, ...parcial,
})

describe('filasDeuda', () => {
  it('separa la cuota vencida de su mora y marca el tope (mockup 3)', () => {
    const filas = filasDeuda([
      linea({ mora: '40.00', dias_atraso: 2, mora_tope_alcanzado: true }),
      linea({ numero_cuota: 12, fecha_vencimiento: HOY, vencida: false }),
    ], HOY)

    expect(filas.map((f) => [f.texto, f.monto, f.mora])).toEqual([
      ['Cuota 11 · vencida 13/10', '40.00', false],
      ['Mora cuota 11 · 2 días (tope)', '40.00', true],
      ['Cuota 12 · vence hoy', '40.00', false],
    ])
  })

  it('una cuota ya pagada que solo debe mora muestra solo la mora', () => {
    const filas = filasDeuda([linea({ pendiente: '0.00', mora: '5.00', dias_atraso: 1 })], HOY)
    expect(filas.map((f) => f.texto)).toEqual(['Mora cuota 11 · 1 día'])
  })

  it('la próxima cuota que vence otro día dice la fecha', () => {
    const filas = filasDeuda([linea({ numero_cuota: 3, fecha_vencimiento: '2026-10-21', vencida: false })], HOY)
    expect(filas[0].texto).toBe('Cuota 3 · vence 21/10')
  })
})

describe('atajosCobro', () => {
  const proxima = { numero_cuota: 12, fecha_vencimiento: HOY, pendiente: '40.00' }

  it('con atraso ofrece la cuota de hoy y ponerse al día', () => {
    expect(atajosCobro({ proxima, exigible_hoy: '120.00' }, HOY)).toEqual([
      { id: 'cuota', texto: 'Cuota de hoy', monto: '40.00' },
      { id: 'al_dia', texto: 'Ponerse al día', monto: '120.00' },
    ])
  })

  it('al día no repite el mismo monto dos veces', () => {
    const atajos = atajosCobro({ proxima: { ...proxima, fecha_vencimiento: '2026-10-21' }, exigible_hoy: '40.00' }, HOY)
    expect(atajos).toEqual([{ id: 'cuota', texto: 'Próxima cuota', monto: '40.00' }])
  })

  it('sin cuotas por vencer (solo queda mora) ofrece ponerse al día', () => {
    expect(atajosCobro({ proxima: null, exigible_hoy: '12.00' }, HOY)).toEqual([{ id: 'al_dia', texto: 'Ponerse al día', monto: '12.00' }])
  })
})

describe('textoAplicacion', () => {
  it('nombra cada concepto como el mockup', () => {
    expect(textoAplicacion({ numero_cuota: 11, concepto: 'interes', monto: '6.67' })).toBe('Cuota 11 · interés')
    expect(textoAplicacion({ numero_cuota: 11, concepto: 'mora', monto: '40.00' })).toBe('Mora cuota 11')
  })
})

describe('normalizarMonto', () => {
  it.each([
    ['120', '120.00'], ['120,5', '120.50'], [' 0.30 ', '0.30'], ['007', '7.00'], ['1 200', '1200.00'],
  ])('%s → %s', (entrada, esperado) => {
    expect(normalizarMonto(entrada)).toBe(esperado)
  })

  it.each(['', '0', '0.00', 'abc', '1.234', '-5', '1e3'])('rechaza %s', (entrada) => {
    expect(normalizarMonto(entrada)).toBeNull()
  })
})

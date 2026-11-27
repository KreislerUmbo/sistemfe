import { describe, expect, it } from 'vitest'
import { estaVencida, montosDeLinea, pasaDeDoceMeses, periodoDe, sumaDeMontos, valorVentaLinea } from './compras'

describe('compras', () => {
  it('suma los montos al céntimo, sin arrastrar decimales', () => {
    expect(sumaDeMontos({ base_gravada: '100', igv: '18', exonerado: '0.10', inafecto: '0.20', otros: '' })).toBe('118.30')
    expect(sumaDeMontos({})).toBe('0.00')
  })

  it('el periodo es el mes de la fecha de emisión', () => {
    expect(periodoDe('2026-03-31')).toBe('2026-03')
  })

  it('avisa solo cuando el periodo pasa de 12 meses después de la emisión', () => {
    expect(pasaDeDoceMeses('2025-03-10', '2026-03')).toBe(false)
    expect(pasaDeDoceMeses('2025-03-10', '2026-04')).toBe(true)
    expect(pasaDeDoceMeses('', '2026-04')).toBe(false)
  })

  it('el valor de venta de una línea redondea como el backend', () => {
    expect(valorVentaLinea('10', '6')).toBe('60.00')
    expect(valorVentaLinea(1, '1.005')).toBe('1.01') // con coma flotante saldría 1.00
    expect(valorVentaLinea('3', '0.333333')).toBe('1.00')
    expect(valorVentaLinea('', '6')).toBe('0.00')
  })

  it('propone el IGV de la línea en la proporción del comprobante, sin pisar el escrito', () => {
    const linea = { cantidad: 10, valor_unitario: 6, igv: null }
    expect(montosDeLinea(linea, { base_gravada: '100', igv: '18' })).toEqual({ valor_venta: '60.00', igv: '10.80', total: '70.80' })
    expect(montosDeLinea(linea, { base_gravada: '', igv: '' })).toEqual({ valor_venta: '60.00', igv: '0.00', total: '60.00' })
    expect(montosDeLinea({ ...linea, igv: '6' }, { base_gravada: '100', igv: '18' }).total).toBe('66.00')
  })

  it('vence al día siguiente de la fecha', () => {
    expect(estaVencida('2026-11-25', '2026-11-26')).toBe(true)
    expect(estaVencida('2026-11-26', '2026-11-26')).toBe(false)
    expect(estaVencida(null, '2026-11-26')).toBe(false)
  })
})

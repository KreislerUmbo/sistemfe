import { describe, expect, it } from 'vitest'
import {
  afectacionDeCabecera, avisoDeCuadre, estaVencida, lineasParaEnviar, montosDeLinea, pasaDeDoceMeses, periodoDe, sumaDeMontos,
  valorVentaLinea,
} from './compras'

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

  it('propone la afectación de la cabecera solo si tiene un tipo de monto', () => {
    expect(afectacionDeCabecera({ base_gravada: '100', igv: '18' })).toBe('10')
    expect(afectacionDeCabecera({ base_gravada: '', igv: '', exonerado: '50' })).toBe('20')
    expect(afectacionDeCabecera({ base_gravada: '', igv: '', inafecto: '50' })).toBe('30')
    expect(afectacionDeCabecera({ base_gravada: '100', igv: '18', exonerado: '50' })).toBe('')
    expect(afectacionDeCabecera({ base_gravada: '', igv: '' })).toBe('')
  })

  it('una línea no gravada no lleva IGV, aunque se haya escrito antes', () => {
    const mixta = { base_gravada: '100', igv: '18', exonerado: '60' }
    const linea = { cantidad: 10, valor_unitario: 6, igv: '5', tipo_afectacion_igv: '20' }
    expect(montosDeLinea(linea, mixta)).toEqual({ valor_venta: '60.00', igv: '0.00', total: '60.00' })
    expect(montosDeLinea({ ...linea, tipo_afectacion_igv: '10' }, mixta).igv).toBe('5.00')
  })

  const linea = (valor: string, afectacion = '') => ({
    product_id: null, titulo: '', sku: null, descripcion: 'Transporte', unidad_medida: 'ZZ', codigo_proveedor: '',
    tipo_afectacion_igv: afectacion, factor: '1', cantidad: 1, valor_unitario: valor, igv: null,
  })

  it('al enviar, la línea sin afectación elegida lleva la de la cabecera', () => {
    const [enviada] = lineasParaEnviar([linea('100')], { base_gravada: '100', igv: '18' })
    expect(enviada).toMatchObject({ product_id: null, tipo_afectacion_igv: '10', valor_venta: '100.00', igv: '18.00', total: '118.00' })
  })

  it('avisa el cuadre por total y por afectación', () => {
    const cabecera = { base_gravada: '100', igv: '18', exonerado: '50' }
    expect(avisoDeCuadre([linea('100', '10'), linea('50', '20')], cabecera)).toBe('')
    expect(avisoDeCuadre([linea('100', '10')], cabecera)).toBe('Las líneas suman 100.00 y la base del comprobante es 150.00.')
    expect(avisoDeCuadre([linea('90', '10'), linea('60', '20')], cabecera)).toContain('gravado 90.00 en las líneas y 100.00')
  })

  it('vence al día siguiente de la fecha', () => {
    expect(estaVencida('2026-11-25', '2026-11-26')).toBe(true)
    expect(estaVencida('2026-11-26', '2026-11-26')).toBe(false)
    expect(estaVencida(null, '2026-11-26')).toBe(false)
  })
})

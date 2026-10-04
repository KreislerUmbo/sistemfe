import { describe, expect, it } from 'vitest'
import { etiquetaTipoMovimiento } from './tipoMovimiento'

describe('etiquetaTipoMovimiento', () => {
  it('traduce los tipos del módulo Créditos', () => {
    expect(etiquetaTipoMovimiento('credito_desembolso')).toBe('Desembolso de crédito')
    expect(etiquetaTipoMovimiento('credito_pago')).toBe('Cobro de crédito')
    expect(etiquetaTipoMovimiento('credito_devolucion_excedente')).toBe('Vuelto de cobro de crédito')
    expect(etiquetaTipoMovimiento('credito_devolucion_saldo_favor')).toBe('Devolución de saldo a favor (crédito)')
  })

  it('un tipo desconocido se muestra tal cual', () => {
    expect(etiquetaTipoMovimiento('otro_tipo')).toBe('otro_tipo')
  })
})

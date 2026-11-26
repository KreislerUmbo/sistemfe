import { describe, expect, it } from 'vitest'
import { estaVencida, pasaDeDoceMeses, periodoDe, sumaDeMontos } from './compras'

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

  it('vence al día siguiente de la fecha', () => {
    expect(estaVencida('2026-11-25', '2026-11-26')).toBe(true)
    expect(estaVencida('2026-11-26', '2026-11-26')).toBe(false)
    expect(estaVencida(null, '2026-11-26')).toBe(false)
  })
})

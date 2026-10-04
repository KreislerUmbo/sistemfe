import { describe, expect, it } from 'vitest'
import { estadoCuota, fechaCorta, textoFrecuencia } from './estados'

describe('estados de créditos', () => {
  it('la cuota que vence hoy no está vencida', () => {
    expect(estadoCuota({ estado: 'pendiente', fecha_vencimiento: '2026-10-15' }, '2026-10-15')).toBe('hoy')
    expect(estadoCuota({ estado: 'pendiente', fecha_vencimiento: '2026-10-14' }, '2026-10-15')).toBe('vencida')
    expect(estadoCuota({ estado: 'pendiente', fecha_vencimiento: '2026-10-16' }, '2026-10-15')).toBe('pendiente')
    expect(estadoCuota({ estado: 'pagada', fecha_vencimiento: '2026-10-01' }, '2026-10-15')).toBe('pagada')
  })

  it('texto de frecuencia', () => {
    expect(textoFrecuencia('dia', 1)).toBe('Diario')
    expect(textoFrecuencia('dia', 15)).toBe('Quincenal')
    expect(textoFrecuencia('mes', 2)).toBe('Cada 2 meses')
  })

  it('fecha corta con el día de la semana correcto', () => {
    expect(fechaCorta('2026-10-12')).toBe('Lun 12/10')
    expect(fechaCorta('2026-10-17')).toBe('Sáb 17/10')
  })
})

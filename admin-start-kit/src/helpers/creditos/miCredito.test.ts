import { describe, expect, it } from 'vitest'
import { fechaConDia, limpiarDigitos, primerNombre, textoVence, textoVencido } from './miCredito'

describe('textoVence', () => {
  it('hoy, mañana o la fecha con el día', () => {
    expect(textoVence('2026-10-11', '2026-10-11')).toBe('vence hoy')
    expect(textoVence('2026-10-12', '2026-10-11')).toBe('vence mañana')
    expect(textoVence('2026-10-15', '2026-10-11')).toBe('vence el jueves 15/10/2026')
  })

  it('cruza fin de mes sin depender de la zona horaria', () => {
    expect(fechaConDia('2026-01-31')).toBe('sábado 31/01/2026')
    expect(textoVence('2026-02-01', '2026-01-31')).toBe('vence mañana')
  })
})

describe('textoVencido', () => {
  it('cuotas y días, en singular y plural', () => {
    expect(textoVencido({ cuotas: 1, dias_atraso: 5 })).toBe('1 cuota vencida · 5 días de atraso')
    expect(textoVencido({ cuotas: 2, dias_atraso: 1 })).toBe('2 cuotas vencidas · 1 día de atraso')
  })

  it('sin cuotas vencidas solo debe mora', () => {
    expect(textoVencido({ cuotas: 0, dias_atraso: 0 })).toBe('Tienes mora pendiente de pago')
  })
})

describe('primerNombre y limpiarDigitos', () => {
  it('saluda con el primer nombre', () => {
    expect(primerNombre('JUAN CARLOS PÉREZ')).toBe('Juan')
  })

  it('deja solo 4 letras o números en mayúscula', () => {
    expect(limpiarDigitos(' 89-1a2 ')).toBe('891A')
  })
})

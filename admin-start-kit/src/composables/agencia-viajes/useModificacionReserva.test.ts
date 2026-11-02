import { describe, expect, it, vi } from 'vitest'

// El servicio real arma httpClient (usa window); estos tests solo prueban los helpers puros.
vi.mock('@/services/admin/reservaModificacionService', () => ({ reservaModificacionService: {} }))

import { motivoDePenalidad, textoComposicion, textoMonto } from './useModificacionReserva'

describe('textoComposicion', () => {
  it('muestra la composición con plurales', () => {
    expect(textoComposicion({ adulto: 2, nino: 1 })).toBe('2 adultos y 1 niño')
    expect(textoComposicion({ adulto: 3 })).toBe('3 adultos')
    expect(textoComposicion({ adulto: 1, nino: 2, infante: 1 })).toBe('1 adulto, 2 niños y 1 infante')
  })

  it('maneja vacío', () => {
    expect(textoComposicion(null)).toBe('sin pasajeros')
    expect(textoComposicion({})).toBe('sin pasajeros')
  })
})

describe('textoMonto', () => {
  it('formatea centavos enteros sin coma flotante', () => {
    expect(textoMonto(123450, 'PEN')).toBe('PEN 1,234.50')
    expect(textoMonto(-5, 'USD')).toBe('-USD 0.05')
    expect(textoMonto(0, 'PEN')).toBe('PEN 0.00')
  })
})

describe('motivoDePenalidad', () => {
  it('recorta el prefijo guardado por el backend', () => {
    expect(motivoDePenalidad('Penalidad por cancelación — Juan Pérez: cambio de planes')).toBe('cambio de planes')
    expect(motivoDePenalidad(null)).toBe('')
  })
})

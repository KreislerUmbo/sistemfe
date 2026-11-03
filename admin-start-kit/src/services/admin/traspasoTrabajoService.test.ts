import { describe, expect, it, vi } from 'vitest'

vi.mock('@/helpers/http-client', () => ({ default: {} }))

import { textoTrabajo, tieneTrabajo } from './traspasoTrabajoService'

describe('textoTrabajo (T-061)', () => {
  it('nombra cotizaciones y acciones con singular y plural', () => {
    expect(textoTrabajo({ cotizaciones: 2, acciones: 1 })).toBe('2 cotizaciones abiertas y 1 acción pendiente')
    expect(textoTrabajo({ cotizaciones: 1, acciones: 0 })).toBe('1 cotización abierta')
    expect(textoTrabajo({ cotizaciones: 0, acciones: 3 })).toBe('3 acciones pendientes')
  })

  it('sin nada abierto no hay trabajo', () => {
    expect(tieneTrabajo({ cotizaciones: 0, acciones: 0 })).toBe(false)
    expect(tieneTrabajo({ cotizaciones: 0, acciones: 1 })).toBe(true)
  })
})

import { describe, expect, it } from 'vitest'
import { formatFechaHora, hoyPeru, sumarDiasISO } from './fecha'

// Regla única de fechas (08-oct-2026): todo se muestra en hora de Perú, sin depender de la
// zona horaria de la PC. Los casos van de 19:00 a 24:00 de Perú, donde UTC ya es el día siguiente.
describe('formatFechaHora', () => {
  it('un instante UTC ("Z") se muestra en hora de Perú', () => {
    expect(formatFechaHora('2026-10-09T00:39:27.000000Z')).toBe('08/10/2026 19:39')
    expect(formatFechaHora('2026-10-09T04:59:00Z')).toBe('08/10/2026 23:59')
  })

  it('un instante con offset de Perú se muestra igual', () => {
    expect(formatFechaHora('2026-10-08T23:30:00-05:00')).toBe('08/10/2026 23:30')
  })

  it('un texto sin zona ya viene en hora de Perú y se muestra tal cual', () => {
    expect(formatFechaHora('2026-10-08 16:58:00')).toBe('08/10/2026 16:58')
    expect(formatFechaHora('2026-08-28 12:55 PM')).toBe('28/08/2026 12:55')
    expect(formatFechaHora('2026-08-28 12:10 AM')).toBe('28/08/2026 00:10')
    expect(formatFechaHora('2026-08-28 07:05 PM')).toBe('28/08/2026 19:05')
  })

  it('vacío o inválido', () => {
    expect(formatFechaHora(null)).toBe('sin fecha')
    expect(formatFechaHora('no es fecha')).toBe('sin fecha')
  })
})

describe('hoyPeru', () => {
  it('de noche en Perú sigue siendo el mismo día', () => {
    expect(hoyPeru(new Date('2026-10-09T00:00:00Z'))).toBe('2026-10-08')
    expect(hoyPeru(new Date('2026-10-09T04:59:59Z'))).toBe('2026-10-08')
    expect(hoyPeru(new Date('2026-10-09T05:00:00Z'))).toBe('2026-10-09')
    expect(hoyPeru(new Date('2026-11-01T04:30:00Z'))).toBe('2026-10-31')
  })
})

describe('sumarDiasISO', () => {
  it('suma días de calendario cruzando mes y año', () => {
    expect(sumarDiasISO('2026-10-31', 1)).toBe('2026-11-01')
    expect(sumarDiasISO('2026-12-31', 7)).toBe('2027-01-07')
    expect(sumarDiasISO('2026-09-01T00:00:00.000000Z', 1)).toBe('2026-09-02')
  })
})

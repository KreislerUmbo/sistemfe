import { describe, expect, it } from 'vitest'
import { parametrosCoincidencias } from './coincidencias'

describe('parametrosCoincidencias (T-059)', () => {
  it('manda solo los dígitos del teléfono, los últimos 9', () => {
    expect(parametrosCoincidencias('+51 (999) 888-777', '')).toEqual({ telefono: '999888777' })
    expect(parametrosCoincidencias('999 888 777', '')).toEqual({ telefono: '999888777' })
  })

  it('no busca con menos de 6 dígitos ni con menos de 3 letras', () => {
    expect(parametrosCoincidencias('99 88', 'Ju')).toBeNull()
    expect(parametrosCoincidencias('', '   ')).toBeNull()
  })

  it('normaliza los espacios del nombre y combina los dos', () => {
    expect(parametrosCoincidencias('999888', '  Juan   Pérez ')).toEqual({ telefono: '999888', nombre: 'Juan Pérez' })
  })
})

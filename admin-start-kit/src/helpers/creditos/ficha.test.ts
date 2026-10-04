import { describe, expect, it } from 'vitest'
import { textoFaltan } from './ficha'

describe('textoFaltan', () => {
  it('traduce los códigos del motor y tolera lo inesperado', () => {
    expect(textoFaltan(['dni_reverso', 'ubicacion'])).toBe('DNI (reverso), Ubicación en el mapa')
    expect(textoFaltan(['nuevo_requisito'])).toBe('nuevo_requisito')
    expect(textoFaltan(undefined)).toBe('')
  })
})

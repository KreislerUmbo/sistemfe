import { describe, expect, it } from 'vitest'
import { medidasReducidas } from './comprimirImagen'

describe('medidasReducidas', () => {
  it('reduce el lado mayor a 1600 manteniendo la proporción', () => {
    expect(medidasReducidas(4000, 3000)).toEqual({ ancho: 1600, alto: 1200 })
    expect(medidasReducidas(3000, 4000)).toEqual({ ancho: 1200, alto: 1600 })
  })

  it('nunca agranda una imagen chica', () => {
    expect(medidasReducidas(800, 600)).toEqual({ ancho: 800, alto: 600 })
  })
})

// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { sanearHtml } from './sanearHtml'

describe('sanearHtml', () => {
  it('quita atributos de evento y scripts', () => {
    const limpio = sanearHtml('<p>Hola</p><img src=x onerror="alert(1)"><script>alert(2)</script>')
    expect(limpio).toContain('<p>Hola</p>')
    expect(limpio).not.toContain('onerror')
    expect(limpio).not.toContain('<script')
  })

  it('quita enlaces javascript:', () => {
    expect(sanearHtml('<a href="javascript:alert(1)">x</a>')).not.toContain('javascript:')
  })

  it('conserva el formato del editor', () => {
    const html = '<p><strong>Incluye</strong></p><ul><li>Almuerzo</li></ul><a href="https://ejemplo.pe">web</a>'
    expect(sanearHtml(html)).toBe(html)
  })

  it('vacío o nulo devuelve texto vacío', () => {
    expect(sanearHtml(null)).toBe('')
    expect(sanearHtml(undefined)).toBe('')
    expect(sanearHtml('')).toBe('')
  })
})

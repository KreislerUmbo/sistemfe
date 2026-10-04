import { describe, expect, it } from 'vitest'
import { fechaTexto, formatoMoneda, textoVariacion } from './dashboard'

describe('formatoMoneda', () => {
  it('soles y dólares con su símbolo, sin espacios no separables', () => {
    expect(formatoMoneda('1234.5', 'PEN')).toBe('S/ 1,234.50')
    expect(formatoMoneda('226.49', 'USD')).toBe('US$ 226.49')
    expect(formatoMoneda('1234.5', 'PEN')).not.toContain(' ')
  })

  it('vacío o inválido → 0.00', () => {
    expect(formatoMoneda(null, 'PEN')).toBe('S/ 0.00')
    expect(formatoMoneda('abc', 'USD')).toBe('US$ 0.00')
  })
})

describe('textoVariacion', () => {
  it('signo y color según el resultado', () => {
    expect(textoVariacion('85.0')).toEqual({ texto: '+85.0 %', clase: 'text-success' })
    expect(textoVariacion('-3.2')).toEqual({ texto: '-3.2 %', clase: 'text-danger' })
    expect(textoVariacion('0.0')).toEqual({ texto: '0.0 %', clase: 'text-muted' })
    expect(textoVariacion(null)).toBeNull()
  })
})

it('fechaTexto sin corrimiento de zona horaria', () => {
  expect(fechaTexto('2026-10-02')).toBe('viernes 2 de octubre')
})

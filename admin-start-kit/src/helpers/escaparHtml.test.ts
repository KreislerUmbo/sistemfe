import { describe, expect, it } from 'vitest'
import { escaparHtml } from './escaparHtml'

describe('escaparHtml', () => {
  it('muestra literal un nombre con HTML (CA1)', () => {
    const r = escaparHtml('<img src=x onerror=alert(1)>')
    expect(r).toBe('&lt;img src=x onerror=alert(1)&gt;')
    expect(r).not.toContain('<')
  })

  it('escapa los cinco caracteres especiales', () => {
    expect(escaparHtml(`&<>"'`)).toBe('&amp;&lt;&gt;&quot;&#39;')
  })

  it('no toca el texto normal y acepta números y vacíos', () => {
    expect(escaparHtml('Guía Pérez — Laguna Azul')).toBe('Guía Pérez — Laguna Azul')
    expect(escaparHtml(42)).toBe('42')
    expect(escaparHtml(null)).toBe('')
    expect(escaparHtml(undefined)).toBe('')
  })
})

import { describe, expect, it } from 'vitest'
import { htmlAvisosProveedores } from './avisosProveedores'

describe('htmlAvisosProveedores', () => {
  it('lista los avisos de cada salida', () => {
    const html = htmlAvisosProveedores([
      { tour: 'Laguna Azul', fecha: '15/11/2026', avisos: ['El Mirador (Almuerzo): ahora son 2, no 5.'] },
      { tour: 'Laguna Azul', fecha: '20/11/2026', avisos: ['a', 'b'] },
    ])
    expect(html).toContain('<strong>Laguna Azul · 15/11/2026</strong>')
    expect(html).toContain('<li>El Mirador (Almuerzo): ahora son 2, no 5.</li>')
    expect(html.match(/<li>/g)).toHaveLength(3)
  })

  it('escapa los textos', () => {
    const html = htmlAvisosProveedores([{ tour: '<img src=x onerror=alert(1)>', fecha: '1', avisos: ['"&\''] }])
    expect(html).not.toContain('<img')
    expect(html).toContain('&lt;img')
    expect(html).toContain('<li>&quot;&amp;&#39;</li>')
  })
})

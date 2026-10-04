import { describe, expect, it } from 'vitest'
import { construirCatalogoCompleto } from './permisos'

const grupos = (catalogo: { name: string }[]) => catalogo.map((g) => g.name)

describe('construirCatalogoCompleto', () => {
  it('un tenant sin permisos de créditos no ve ese grupo', () => {
    const catalogo = construirCatalogoCompleto(['list_role', 'list_user'])
    expect(grupos(catalogo)).not.toContain('Créditos (préstamos)')
    expect(catalogo.flatMap((g) => g.permisos.map((p) => p.permiso))).toEqual(expect.arrayContaining(['list_role', 'list_user']))
  })

  it('un tenant de créditos ve el grupo con solo los permisos que existen', () => {
    const creditos = construirCatalogoCompleto(['creditos.ver', 'creditos.cobrar']).find((g) => g.name === 'Créditos (préstamos)')
    expect(creditos?.permisos.map((p) => p.permiso)).toEqual(['creditos.ver', 'creditos.cobrar'])
  })

  it('lo que no está en el catálogo curado va a "Otros permisos"', () => {
    expect(construirCatalogoCompleto(['permiso.nuevo']).at(-1)).toEqual({
      name: 'Otros permisos', permisos: [{ name: 'Permiso Nuevo', permiso: 'permiso.nuevo' }],
    })
  })

  it('sin la lista real todavía muestra el catálogo completo', () => {
    expect(grupos(construirCatalogoCompleto([]))).toContain('Créditos (préstamos)')
  })
})

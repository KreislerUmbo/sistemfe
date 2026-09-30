import { describe, expect, it } from 'vitest'
import { alternarOrden, aQuery, desdeQuery, FILTROS_INICIALES, paramsListado } from './listado'

describe('paramsListado', () => {
  it('traduce la vista a estado o con_atraso', () => {
    expect(paramsListado({ ...FILTROS_INICIALES, vista: 'castigados' })).toMatchObject({ estado: 'castigado' })
    const atrasados = paramsListado({ ...FILTROS_INICIALES, vista: 'atrasados', buscar: '  juan ' })
    expect(atrasados).toMatchObject({ con_atraso: 1, buscar: 'juan' })
    expect(atrasados).not.toHaveProperty('estado')
  })

  it('"Todos" sin búsqueda no manda filtros vacíos', () => {
    expect(paramsListado(FILTROS_INICIALES)).toEqual({ orden: 'reciente', direccion: 'desc' })
  })
})

describe('URL', () => {
  it('ida y vuelta conserva filtros y página', () => {
    const filtros = { buscar: 'CR-12', vista: 'activos' as const, orden: 'cliente' as const, direccion: 'desc' as const }
    const q = aQuery(filtros, 3)
    expect(q).toEqual({ buscar: 'CR-12', vista: 'activos', orden: 'cliente', dir: 'desc', pagina: '3' })
    expect(desdeQuery(q)).toEqual({ filtros, pagina: 3 })
  })

  it('lo inicial no ensucia la URL', () => {
    expect(aQuery(FILTROS_INICIALES, 1)).toEqual({})
  })

  it('valores inventados vuelven a lo inicial', () => {
    expect(desdeQuery({ vista: 'x', orden: 'saldo', dir: 'arriba', pagina: '-2' })).toEqual({ filtros: FILTROS_INICIALES, pagina: 1 })
  })
})

describe('alternarOrden', () => {
  it('misma columna invierte; otra columna arranca en su dirección natural', () => {
    const porCliente = alternarOrden(FILTROS_INICIALES, 'cliente')
    expect(porCliente).toMatchObject({ orden: 'cliente', direccion: 'asc' })
    expect(alternarOrden(porCliente, 'cliente')).toMatchObject({ orden: 'cliente', direccion: 'desc' })
    expect(alternarOrden(porCliente, 'monto')).toMatchObject({ orden: 'monto', direccion: 'desc' })
  })
})

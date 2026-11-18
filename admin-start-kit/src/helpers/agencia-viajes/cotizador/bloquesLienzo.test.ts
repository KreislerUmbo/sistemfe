import { describe, expect, it } from 'vitest'
import { agruparPorTour, filasDelBloque } from './bloquesLienzo'
import type { AlternativaItem } from '@/types/agencia-viajes'

let siguienteId = 1
const item = (extra: Record<string, unknown> = {}) => ({ id: siguienteId++, origen_tipo: 'proveedor', tour_origen_id: null, grupo_opcion_id: null, ...extra }) as unknown as AlternativaItem

describe('agruparPorTour', () => {
  it('agrupa la línea de tour por carga: dos cargas del mismo tour son dos bloques', () => {
    const a1 = item({ origen_tipo: 'tour', carga_tour: 'c1', tour_origen_id: 7, tour_linea: { tour_nombre: 'Laguna Azul', combo: { nombre: 'Selva' } }, carga_combo: 'k1' })
    const a2 = item({ origen_tipo: 'tour', carga_tour: 'c1', tour_origen_id: 7 })
    const b = item({ origen_tipo: 'tour', carga_tour: 'c2', tour_origen_id: 7, tour_origen: { nombre: 'Laguna Azul (catálogo)' } })
    const bloques = agruparPorTour([a1, b, a2])
    expect(bloques.map((x) => x.clave)).toEqual(['carga-c1', 'carga-c2'])
    expect(bloques[0]).toMatchObject({ tourOrigenId: 7, tourNombre: 'Laguna Azul', carga: 'c1', cargaCombo: 'k1', comboNombre: 'Selva' })
    expect(bloques[0].items).toEqual([a1, a2])
    expect(bloques[1].tourNombre).toBe('Laguna Azul (catálogo)')
  })

  it('el bloque clásico (tour_origen_id sin carga) no se mezcla con la línea nueva', () => {
    const clasico = item({ tour_origen_id: 7, tour_origen: { nombre: 'Laguna Azul' } })
    const linea = item({ origen_tipo: 'tour', carga_tour: 'c1', tour_origen_id: 7 })
    expect(agruparPorTour([clasico, linea]).map((x) => x.clave)).toEqual(['tour-7', 'carga-c1'])
  })

  it('los sueltos van al final, en un solo bloque', () => {
    const s1 = item()
    const t = item({ tour_origen_id: 3 })
    const s2 = item()
    const bloques = agruparPorTour([s1, t, s2])
    expect(bloques.map((x) => x.clave)).toEqual(['tour-3', 'sueltos'])
    expect(bloques[1]).toMatchObject({ tourOrigenId: null, carga: null, items: [s1, s2] })
  })

  it('sin ítems no hay bloques', () => {
    expect(agruparPorTour([])).toEqual([])
  })
})

describe('filasDelBloque', () => {
  it('los ítems consecutivos del mismo grupo son una sola fila', () => {
    const a = item({ grupo_opcion_id: 'g' })
    const b = item({ grupo_opcion_id: 'g' })
    const s = item()
    expect(filasDelBloque([a, b, s])).toEqual([
      { esGrupo: true, grupoId: 'g', items: [a, b] },
      { esGrupo: false, item: s },
    ])
  })

  it('un grupo partido por otro ítem sale como dos filas (caso no contemplado, se fija como está)', () => {
    const a = item({ grupo_opcion_id: 'g' })
    const s = item()
    const b = item({ grupo_opcion_id: 'g' })
    expect(filasDelBloque([a, s, b]).map((f) => f.esGrupo)).toEqual([true, false, true])
  })
})

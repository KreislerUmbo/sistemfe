import { describe, expect, it, vi, beforeEach } from 'vitest'
import { effectScope, nextTick } from 'vue'

const obtener = vi.fn()
vi.mock('@/services/admin/cotizacionService', () => ({ cotizacionService: { obtener: (...a: unknown[]) => obtener(...a) } }))

import { useCotizacionEditor, type TipoRefresco } from './useCotizacionEditor'

const item = (id: number, alternativa_id: number, extra: Record<string, unknown> = {}) =>
  ({ id, alternativa_id, descripcion: `Ítem ${id}`, ...extra }) as any

const respuesta = (alternativas: any[], modificacion: any = null) => ({
  cotizacion: { id: 7, alternativas },
  modificacion,
})

function crear() {
  const scope = effectScope()
  const nucleo = scope.run(() => useCotizacionEditor(7))!
  return { scope, nucleo }
}

beforeEach(() => obtener.mockReset())

describe('cargarCotizacion', () => {
  it('pide la cotización por id y guarda la modificación', async () => {
    obtener.mockResolvedValue(respuesta([{ id: 1, estado: 'borrador', items: [] }], { id: 3, estado: 'abierta', reserva_id: 9, reserva_codigo: null }))
    const { nucleo } = crear()
    await nucleo.cargarCotizacion()
    expect(obtener).toHaveBeenCalledWith(7)
    expect(nucleo.cotizacion.value?.id).toBe(7)
    expect(nucleo.modificacionInfo.value?.reserva_id).toBe(9)
  })

  it('en la primera carga abre la aceptada, si no la reemplazada, si no la primera', async () => {
    const casos: Array<[any[], number]> = [
      [[{ id: 1, estado: 'borrador' }, { id: 2, estado: 'reemplazada' }, { id: 3, estado: 'aceptada' }], 3],
      [[{ id: 1, estado: 'borrador' }, { id: 2, estado: 'reemplazada' }], 2],
      [[{ id: 1, estado: 'borrador' }, { id: 2, estado: 'enviada' }], 1],
    ]
    for (const [alts, esperada] of casos) {
      obtener.mockResolvedValue(respuesta(alts))
      const { nucleo } = crear()
      await nucleo.cargarCotizacion()
      expect(nucleo.alternativaActivaId.value).toBe(esperada)
    }
  })

  it('no cambia la alternativa activa en las recargas siguientes', async () => {
    obtener.mockResolvedValue(respuesta([{ id: 1, estado: 'borrador' }, { id: 2, estado: 'borrador' }]))
    const { nucleo } = crear()
    await nucleo.cargarCotizacion()
    nucleo.alternativaActivaId.value = 2
    await nucleo.cargarCotizacion()
    expect(nucleo.alternativaActivaId.value).toBe(2)
  })

  it('avisa a los suscriptores en el orden de registro, con tipo «completo»', async () => {
    obtener.mockResolvedValue(respuesta([{ id: 1, estado: 'borrador' }]))
    const { nucleo } = crear()
    const orden: string[] = []
    nucleo.alRefrescar((t) => orden.push(`a:${t}`))
    nucleo.alRefrescar((t) => orden.push(`b:${t}`))
    await nucleo.cargarCotizacion()
    expect(orden).toEqual(['a:completo', 'b:completo'])
  })

  it('el suscriptor ya ve la cotización nueva y la alternativa activa resuelta', async () => {
    obtener.mockResolvedValue(respuesta([{ id: 5, estado: 'borrador', items: [item(10, 5)] }]))
    const { nucleo } = crear()
    let visto: unknown = null
    nucleo.alRefrescar(() => { visto = nucleo.itemsSinAjuste.value.map((i) => i.id) })
    await nucleo.cargarCotizacion()
    expect(visto).toEqual([10])
  })

  it('el watch de la cotización sube la versión', async () => {
    obtener.mockResolvedValue(respuesta([{ id: 1, estado: 'borrador' }]))
    const { nucleo } = crear()
    await nucleo.cargarCotizacion()
    await nextTick()
    expect(nucleo.versionCotizacion.value).toBe(1)
  })
})

describe('actualización parcial', () => {
  async function cargado() {
    obtener.mockResolvedValue(respuesta([
      { id: 1, estado: 'borrador', total: 100, items: [item(10, 1, { precio: 50, proveedor: { id: 4 } }), item(11, 1)] },
    ]))
    const ctx = crear()
    await ctx.nucleo.cargarCotizacion()
    const avisos: TipoRefresco[] = []
    ctx.nucleo.alRefrescar((t) => avisos.push(t))
    return { ...ctx, avisos }
  }

  it('aplicarItemActualizado reemplaza el ítem existente, mezcla columnas de la alternativa y avisa «parcial»', async () => {
    const { nucleo, avisos } = await cargado()
    const v = nucleo.versionCotizacion.value
    const alt = nucleo.cotizacion.value!.alternativas![0]
    const original = alt.items![0]
    expect(nucleo.aplicarItemActualizado(item(10, 1, { precio: 80 }), { id: 1, total: 130, items: [] } as any)).toBe(true)
    expect(alt.items![0]).toBe(original) // misma instancia: conserva relaciones
    expect((alt.items![0] as any).precio).toBe(80)
    expect((alt.items![0] as any).proveedor).toEqual({ id: 4 })
    expect((alt as any).total).toBe(130)
    expect(alt.items).toHaveLength(2) // los `items` de `alternativa` se ignoran
    expect(avisos).toEqual(['parcial'])
    expect(nucleo.versionCotizacion.value).toBe(v + 1)
  })

  it('aplicarItemActualizado agrega un ítem nuevo', async () => {
    const { nucleo } = await cargado()
    expect(nucleo.aplicarItemActualizado(item(12, 1))).toBe(true)
    expect(nucleo.cotizacion.value!.alternativas![0].items!.map((i) => i.id)).toEqual([10, 11, 12])
  })

  it('aplicarItemActualizado devuelve false sin ítem o si su alternativa ya no está, sin avisar', async () => {
    const { nucleo, avisos } = await cargado()
    const v = nucleo.versionCotizacion.value
    expect(nucleo.aplicarItemActualizado(null)).toBe(false)
    expect(nucleo.aplicarItemActualizado(item(10, 99))).toBe(false)
    expect(avisos).toEqual([])
    expect(nucleo.versionCotizacion.value).toBe(v)
  })

  it('aplicarAlternativaActualizada sin `items` solo mezcla columnas', async () => {
    const { nucleo, avisos } = await cargado()
    const alt = nucleo.cotizacion.value!.alternativas![0]
    expect(nucleo.aplicarAlternativaActualizada({ id: 1, nombre: 'Nueva' } as any)).toBe(true)
    expect((alt as any).nombre).toBe('Nueva')
    expect(alt.items!.map((i) => i.id)).toEqual([10, 11])
    expect(avisos).toEqual(['parcial'])
  })

  it('aplicarAlternativaActualizada con `items` reemplaza la lista conservando las relaciones de los que siguen', async () => {
    const { nucleo } = await cargado()
    const alt = nucleo.cotizacion.value!.alternativas![0]
    const previo = alt.items![0]
    expect(nucleo.aplicarAlternativaActualizada({ id: 1, items: [item(10, 1, { precio: 60 }), item(13, 1)] } as any)).toBe(true)
    expect(alt.items!.map((i) => i.id)).toEqual([10, 13])
    expect(alt.items![0]).toBe(previo)
    expect((alt.items![0] as any).proveedor).toEqual({ id: 4 })
    expect((alt.items![0] as any).precio).toBe(60)
  })

  it('aplicarAlternativaActualizada devuelve false si la alternativa ya no está', async () => {
    const { nucleo, avisos } = await cargado()
    expect(nucleo.aplicarAlternativaActualizada({ id: 99 } as any)).toBe(false)
    expect(nucleo.aplicarAlternativaActualizada(null)).toBe(false)
    expect(avisos).toEqual([])
  })
})

describe('derivados', () => {
  it('separa los ajustes y marca solo lectura la reservada salvo con modificación abierta', async () => {
    obtener.mockResolvedValue(respuesta([
      { id: 1, estado: 'aceptada', items: [item(10, 1), item(11, 1, { ajuste_de_item_id: 10 })] },
    ]))
    const { nucleo } = crear()
    await nucleo.cargarCotizacion()
    expect(nucleo.itemsSinAjuste.value.map((i) => i.id)).toEqual([10])
    expect(nucleo.itemsAjuste.value.map((i) => i.id)).toEqual([11])
    expect(nucleo.alternativaSoloLectura.value).toBe(true)
    nucleo.modificacionInfo.value = { id: 1, estado: 'abierta', reserva_id: 2, reserva_codigo: null }
    expect(nucleo.alternativaSoloLectura.value).toBe(false)
  })
})

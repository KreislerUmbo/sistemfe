import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { computed, effectScope, ref, type EffectScope } from 'vue'

const actualizarItem = vi.fn()
const actualizarAlternativa = vi.fn()
const fire = vi.fn()
vi.mock('@/services/admin/alternativaItemService', () => ({ alternativaItemService: { actualizar: (...a: unknown[]) => actualizarItem(...a) } }))
vi.mock('@/services/admin/alternativaService', () => ({ alternativaService: { actualizar: (...a: unknown[]) => actualizarAlternativa(...a) } }))
vi.mock('sweetalert2/dist/sweetalert2.js', () => ({ default: { fire: (...a: unknown[]) => fire(...a) } }))

import { useEdicionPrecios } from './useEdicionPrecios'

const item = (id: number, extra: Record<string, unknown> = {}) => ({
  id, alternativa_id: 1, modo_precio: 'por_persona', cantidad: 1, grupo_opcion_id: null, opcion_elegida: false,
  ajuste_de_item_id: null, moneda_costo: 'USD', precio_venta_snapshot: 100, precio_convertido: 100, descuento_pct: 0, ...extra,
}) as any

// Una promesa que el test resuelve a mano: simula un guardado en vuelo.
function diferida<T>() {
  let resolver!: (v: T) => void
  const promesa = new Promise<T>((r) => { resolver = r })
  return { promesa, resolver }
}

const scopes: EffectScope[] = []

/**
 * Núcleo de mentira con el mismo contrato que useCotizacionEditor: aplicarItemActualizado mezcla
 * el ítem y avisa (como alRefrescar en la página, que llama a inicializarEdicionItems()).
 */
function crear(items: any[], opciones: { foco?: number[]; alternativa?: Record<string, unknown> } = {}) {
  const alternativa = ref<any>({ id: 1, moneda_cotizacion: 'USD', tipo_cambio_aplicado: 3.8, descuento_global_pct: 0, items, ...opciones.alternativa })
  const alternativaActiva = computed(() => alternativa.value)
  const itemsSinAjuste = computed(() => alternativa.value.items.filter((i: any) => !i.ajuste_de_item_id))
  const cargarCotizacion = vi.fn(async () => { p.inicializarEdicionItems() })
  const aplicarItemActualizado = vi.fn((nuevo: any, alt?: any) => {
    if (!nuevo) return false
    Object.assign(alternativa.value.items.find((i: any) => i.id === nuevo.id), nuevo)
    if (alt) Object.assign(alternativa.value, alt)
    p.inicializarEdicionItems()
    return true
  })
  const aplicarAlternativaActualizada = vi.fn((nueva: any) => {
    if (!nueva) return false
    Object.assign(alternativa.value, nueva)
    p.inicializarEdicionItems()
    return true
  })
  const foco = { ids: opciones.foco ?? [] }
  const scope = effectScope()
  scopes.push(scope)
  const p = scope.run(() => useEdicionPrecios(
    { alternativaActiva, itemsSinAjuste, cargarCotizacion, aplicarItemActualizado, aplicarAlternativaActualizada },
    { itemConFoco: () => foco.ids },
  ))!
  p.inicializarEdicionItems()
  return { p, alternativa, foco, cargarCotizacion, aplicarItemActualizado }
}

beforeEach(() => {
  vi.useFakeTimers()
  actualizarItem.mockReset()
  actualizarAlternativa.mockReset()
  fire.mockReset()
})
afterEach(() => {
  vi.useRealTimers()
  scopes.splice(0).forEach((s) => s.stop())
})

describe('inicializarEdicionItems', () => {
  it('toma lo guardado y deriva el monto de descuento del precio de lista convertido', () => {
    const { p } = crear([item(1, { precio_venta_snapshot: 100, precio_convertido: 90, descuento_pct: 10 }), item(2, { moneda_costo: 'PEN', precio_venta_snapshot: 380, precio_convertido: 95 })])
    expect(p.edicionItems.value[1]).toEqual({ descuento_pct: 10, precio_convertido: 90, monto_descuento: 10 })
    // 380 PEN / 3.8 = 100 USD de lista; se vende a 95 → 5 de descuento.
    expect(p.edicionItems.value[2].monto_descuento).toBe(5)
  })

  it('reconstruye el descuento global en monto desde el % guardado (mismo criterio de grupos)', () => {
    const { p } = crear([
      item(1, { precio_venta_snapshot: 200 }),
      item(2, { grupo_opcion_id: 'g', precio_venta_snapshot: 50 }),
      item(3, { grupo_opcion_id: 'g', precio_venta_snapshot: 80 }),
    ], { alternativa: { descuento_global_pct: 10 } })
    // 200 + mínimo del grupo abierto (50) = 250 → 10 % = 25.
    expect(p.descuentoGlobalLocal.value).toBe(10)
    expect(p.descuentoGlobalMontoLocal.value).toBe(25)
  })

  it('no pisa el ítem que tiene el foco', () => {
    const { p, alternativa, foco } = crear([item(1), item(2)])
    p.edicionItems.value[1].precio_convertido = 77
    foco.ids = [1]
    alternativa.value.items[0].precio_convertido = 100
    alternativa.value.items[1].precio_convertido = 60
    p.inicializarEdicionItems()
    expect(p.edicionItems.value[1].precio_convertido).toBe(77)
    expect(p.edicionItems.value[2].precio_convertido).toBe(60)
  })
})

describe('edición por ítem', () => {
  it('espera 400 ms y manda una sola vez lo último que se escribió', async () => {
    actualizarItem.mockResolvedValue({ alternativa_item: item(1, { precio_convertido: 80 }), alternativa: null, alerta_piso: false })
    const { p } = crear([item(1)])
    p.edicionItems.value[1].precio_convertido = 90
    p.onEditarPrecio(item(1))
    await vi.advanceTimersByTimeAsync(300)
    p.edicionItems.value[1].precio_convertido = 80
    p.onEditarPrecio(item(1))
    await vi.advanceTimersByTimeAsync(399)
    expect(actualizarItem).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(actualizarItem).toHaveBeenCalledTimes(1)
    expect(actualizarItem).toHaveBeenCalledWith(1, { precio_convertido: 80 })
  })

  it('en modo %, la vista previa usa la fórmula del backend y manda descuento_pct', async () => {
    actualizarItem.mockResolvedValue({ alternativa_item: item(1, { precio_convertido: 87.5, descuento_pct: 12.5 }), alternativa: null })
    const { p } = crear([item(1)])
    p.edicionItems.value[1].descuento_pct = 12.5
    p.onEditarDescuentoPct(item(1))
    expect(p.edicionItems.value[1]).toMatchObject({ precio_convertido: 87.5, monto_descuento: 12.5 })
    expect(p.totalLocal.value).toBe(87.5)
    await vi.advanceTimersByTimeAsync(400)
    expect(actualizarItem).toHaveBeenCalledWith(1, { descuento_pct: 12.5 })
  })

  it('en modo monto, deriva precio y % y manda el precio', async () => {
    actualizarItem.mockResolvedValue({ alternativa_item: item(1, { precio_convertido: 85 }), alternativa: null })
    const { p } = crear([item(1)])
    p.edicionItems.value[1].monto_descuento = 15
    p.onEditarMontoDescuentoItem(item(1))
    expect(p.edicionItems.value[1]).toMatchObject({ precio_convertido: 85, descuento_pct: 15 })
    await vi.advanceTimersByTimeAsync(400)
    expect(actualizarItem).toHaveBeenCalledWith(1, { precio_convertido: 85 })
  })

  it('al responder, el ítem toma lo guardado (redondeo del backend) y el aviso de piso', async () => {
    actualizarItem.mockResolvedValue({ alternativa_item: item(1, { precio_convertido: 100.56 }), alternativa: null, alerta_piso: true })
    const { p } = crear([item(1)])
    p.edicionItems.value[1].precio_convertido = 100.555
    p.onEditarPrecio(item(1))
    await vi.advanceTimersByTimeAsync(400)
    expect(p.edicionItems.value[1].precio_convertido).toBe(100.56)
    expect(p.alertasPiso.value[1]).toBe(true)
    expect(p.guardandoEdicion.value).toEqual([])
  })

  it('si la respuesta no se puede aplicar, recarga la cotización', async () => {
    actualizarItem.mockResolvedValue({ alternativa_item: null, alternativa: null })
    const { p, cargarCotizacion } = crear([item(1)])
    p.onEditarPrecio(item(1))
    await vi.advanceTimersByTimeAsync(400)
    expect(cargarCotizacion).toHaveBeenCalledTimes(1)
  })

  it('si el guardado falla, avisa y libera el indicador', async () => {
    actualizarItem.mockRejectedValue({ response: { data: { message: 'Bajo el piso' } } })
    const { p } = crear([item(1)])
    p.onEditarPrecio(item(1))
    await vi.advanceTimersByTimeAsync(400)
    expect(fire).toHaveBeenCalledWith('Error', 'Bajo el piso', 'error')
    expect(p.guardandoEdicion.value).toEqual([])
  })
})

// H13: la respuesta de un guardado refresca la alternativa (inicializarEdicionItems). Ese refresco
// no puede pisar lo que el vendedor está escribiendo en otro ítem ni en el mismo.
describe('caso H13: no pisar un ítem que se está editando', () => {
  it('la respuesta del ítem 2 no pisa lo escrito en el ítem 1 (aún sin enviar)', async () => {
    const guardado2 = diferida<any>()
    actualizarItem.mockImplementation((id: number) => (id === 2 ? guardado2.promesa : Promise.resolve({ alternativa_item: item(1, { precio_convertido: 70 }), alternativa: null })))
    const { p } = crear([item(1), item(2)])

    p.edicionItems.value[2].precio_convertido = 60
    p.onEditarPrecio(item(2))
    await vi.advanceTimersByTimeAsync(400) // el ítem 2 queda en vuelo
    expect(p.guardandoEdicion.value).toEqual([2])

    p.edicionItems.value[1].precio_convertido = 70 // el vendedor escribe en el ítem 1
    p.onEditarPrecio(item(1))

    guardado2.resolver({ alternativa_item: item(2, { precio_convertido: 60 }), alternativa: { total: 160 } })
    await vi.advanceTimersByTimeAsync(0)
    expect(p.edicionItems.value[1].precio_convertido).toBe(70)
    expect(p.edicionItems.value[2].precio_convertido).toBe(60)

    await vi.advanceTimersByTimeAsync(400) // ahora sí se envía el ítem 1
    expect(actualizarItem).toHaveBeenLastCalledWith(1, { precio_convertido: 70 })
  })

  it('si se vuelve a escribir en el mismo ítem mientras se guarda, manda lo escrito', async () => {
    const primero = diferida<any>()
    actualizarItem.mockReturnValueOnce(primero.promesa).mockResolvedValue({ alternativa_item: item(1, { precio_convertido: 75 }), alternativa: null })
    const { p } = crear([item(1)])

    p.edicionItems.value[1].precio_convertido = 90
    p.onEditarPrecio(item(1))
    await vi.advanceTimersByTimeAsync(400)

    p.edicionItems.value[1].precio_convertido = 75
    p.onEditarPrecio(item(1))
    primero.resolver({ alternativa_item: item(1, { precio_convertido: 90 }), alternativa: null })
    await vi.advanceTimersByTimeAsync(0)
    expect(p.edicionItems.value[1].precio_convertido).toBe(75)

    await vi.advanceTimersByTimeAsync(400)
    expect(actualizarItem).toHaveBeenLastCalledWith(1, { precio_convertido: 75 })
    expect(p.edicionItems.value[1].precio_convertido).toBe(75)
  })

  it('una recarga completa tampoco pisa un ítem con guardado en vuelo', async () => {
    const guardado = diferida<any>()
    actualizarItem.mockReturnValue(guardado.promesa)
    const { p, alternativa } = crear([item(1)])
    p.edicionItems.value[1].precio_convertido = 50
    p.onEditarPrecio(item(1))
    await vi.advanceTimersByTimeAsync(400)
    alternativa.value.items[0].precio_convertido = 100 // la recarga trae el valor viejo
    p.inicializarEdicionItems()
    expect(p.edicionItems.value[1].precio_convertido).toBe(50)
    guardado.resolver({ alternativa_item: item(1, { precio_convertido: 50 }), alternativa: null })
    await vi.advanceTimersByTimeAsync(0)
  })
})

describe('descuento global', () => {
  it('manda el %, aplica la alternativa y deja el aviso de piso nuevo', async () => {
    const respuesta = diferida<any>()
    actualizarAlternativa.mockReturnValue(respuesta.promesa)
    const { p } = crear([item(1)])
    p.descuentoGlobalLocal.value = 10
    const aplicando = p.onEditarDescuentoGlobal()
    expect(p.aplicandoDescuentoGlobal.value).toBe(true)
    respuesta.resolver({ alternativa: { id: 1, descuento_global_pct: 10 }, lineas_fuera_de_piso: [{ alternativa_item_id: 1, precio_minimo_permitido: 95 }] })
    await aplicando
    expect(actualizarAlternativa).toHaveBeenCalledWith(1, { descuento_global_pct: 10 })
    expect(p.lineasFueraDePiso.value).toEqual([{ alternativa_item_id: 1, precio_minimo_permitido: 95 }])
    expect(p.aplicandoDescuentoGlobal.value).toBe(false)
  })

  it('en monto manda descuento_global_monto; sin alternativa en la respuesta, recarga', async () => {
    actualizarAlternativa.mockResolvedValue({ alternativa: null })
    const { p, cargarCotizacion } = crear([item(1)])
    p.descuentoGlobalMontoLocal.value = 20
    await p.onEditarDescuentoGlobalMonto()
    expect(actualizarAlternativa).toHaveBeenCalledWith(1, { descuento_global_monto: 20 })
    expect(cargarCotizacion).toHaveBeenCalledTimes(1)
    expect(p.lineasFueraDePiso.value).toEqual([])
  })
})

describe('totales de vista previa', () => {
  it('el precio que se está escribiendo pesa antes que el guardado; tarifa fija × cantidad', () => {
    const { p } = crear([item(1), item(2, { modo_precio: 'tarifa_fija', cantidad: 3, precio_convertido: 10 })])
    p.edicionItems.value[1].precio_convertido = 40
    expect(p.totalLocal.value).toBe(70)
    expect(p.subtotalGrupo([item(1)])).toBe(40)
  })
})

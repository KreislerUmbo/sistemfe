import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, effectScope, nextTick, ref, type EffectScope } from 'vue'

const { itemSvc, provSvc, hotelSvc, bibSvc, fire } = vi.hoisted(() => ({
  itemSvc: { agregarProveedor: vi.fn(), cargarDesdePlantilla: vi.fn() },
  provSvc: { listarTarifas: vi.fn() },
  hotelSvc: { crear: vi.fn() },
  bibSvc: { buscar: vi.fn() },
  fire: vi.fn(),
}))
vi.mock('@/services/admin/alternativaItemService', () => ({ alternativaItemService: itemSvc }))
vi.mock('@/services/admin/proveedorService', () => ({ proveedorService: provSvc }))
vi.mock('@/services/admin/opcionHotelService', () => ({ opcionHotelService: hotelSvc }))
vi.mock('@/services/admin/opcionMayoristaService', () => ({ opcionMayoristaService: {} }))
vi.mock('@/services/admin/bibliotecaCotizadorService', () => ({ bibliotecaCotizadorService: bibSvc }))
vi.mock('sweetalert2/dist/sweetalert2.js', () => ({ default: { fire: (...a: unknown[]) => fire(...a), mixin: () => ({ fire: vi.fn() }) } }))

import { pasoDrawerDe, useDrawerBiblioteca } from './useDrawerBiblioteca'
import { useHotelesLocales } from './useHotelesLocales'
import { useBibliotecaCotizador } from './useBibliotecaCotizador'

const item = (id: number, extra: Record<string, unknown> = {}) => ({
  id, alternativa_id: 1, origen_tipo: 'proveedor', cantidad: 1, grupo_opcion_id: null, opcion_elegida: false,
  ajuste_de_item_id: null, dia_referencial: 1, tour_origen_id: null, total_convertido: 0, ...extra,
}) as any

const hotel = (id: number, servicio: number, grupo: string, extra: Record<string, unknown> = {}) =>
  item(id, { grupo_opcion_id: grupo, proveedor_tarifa: { tipo_habitacion: 'doble', proveedor_servicio_id: servicio }, ...extra })

let scope: EffectScope
afterEach(() => { scope?.stop(); vi.useRealTimers() })
beforeEach(() => { vi.clearAllMocks(); bibSvc.buscar.mockResolvedValue({ resultados: [] }) })

/** Los tres bloques armados como en la página: drawer → hoteles → biblioteca. */
function armar(opciones: { items?: any[]; destinos?: any[]; cotizacion?: any; config?: any } = {}) {
  const alternativa = ref<any>({ id: 1, items: opciones.items ?? [], destinos: opciones.destinos ?? [] })
  const alternativaActiva = computed(() => alternativa.value)
  const itemsSinAjuste = computed<any[]>(() => alternativa.value.items.filter((i: any) => !i.ajuste_de_item_id))
  const cotizacion = ref<any>(opciones.cotizacion ?? { fecha_viaje_desde: null, fecha_viaje_hasta: null })
  const configAgencia = ref<any>(opciones.config ?? null)
  const alternativaDestinos = computed<any[]>(() => alternativa.value.destinos)
  const destinoActivoId = ref<number | null>(alternativa.value.destinos[0]?.id ?? null)
  const dia = ref(1)
  const diaActivoParaAgregar = computed(() => dia.value)
  const diasCreados = ref<number[]>([1])
  const diasDesdeCombo = ref<Set<number>>(new Set())
  const cargarCotizacion = vi.fn(async () => {})
  const aplicarItemActualizado = vi.fn(() => true)

  scope = effectScope()
  return scope.run(() => {
    const drawer = useDrawerBiblioteca({ aplicarItemActualizado, cargarCotizacion })
    const hoteles = useHotelesLocales({
      cotizacion, alternativaActiva, itemsSinAjuste, configAgencia, alternativaDestinos, destinoActivoId,
      diaActivoParaAgregar, alVolverAGrid: drawer.alVolverAGrid, onServicioSueltoAgregado: drawer.onServicioSueltoAgregado,
    })
    const biblioteca = useBibliotecaCotizador({
      alternativaActiva, itemsSinAjuste, configAgencia, cargarCotizacion, alternativaDestinos, destinoActivoId,
      diaActivoParaAgregar, diasCreados, diasDesdeCombo, drawerBibliotecaAbierto: drawer.drawerBibliotecaAbierto,
      cerrarDrawerBiblioteca: drawer.cerrarDrawerBiblioteca, clicBibliotecaItem: hoteles.clicBibliotecaItem,
    })
    return {
      drawer, hoteles, biblioteca, alternativa, cotizacion, configAgencia, destinoActivoId, dia, diasCreados,
      diasDesdeCombo, cargarCotizacion, aplicarItemActualizado,
    }
  })!
}

describe('useDrawerBiblioteca', () => {
  it('volver al grid corre los reinicios en el orden en que se registraron; cerrar también vuelve', () => {
    const { drawer } = armar()
    const orden: string[] = []
    drawer.alVolverAGrid(() => orden.push('a'))
    drawer.alVolverAGrid(() => orden.push('b'))
    drawer.abrirDrawerBiblioteca()
    expect(drawer.drawerBibliotecaAbierto.value).toBe(true)
    drawer.cerrarDrawerBiblioteca()
    expect(drawer.drawerBibliotecaAbierto.value).toBe(false)
    expect(orden).toEqual(['a', 'b'])
  })

  it('un servicio suelto agregado vuelve al grid sin cerrar el drawer, y recarga solo si no puede aplicarlo', async () => {
    const { drawer, hoteles, cargarCotizacion, aplicarItemActualizado } = armar()
    hoteles.modoPrecioPendiente.value = { id: 1, nombre: 'x' }
    drawer.abrirDrawerBiblioteca()

    await drawer.onServicioSueltoAgregado(item(5), { id: 1 })
    expect(hoteles.modoPrecioPendiente.value).toBeNull()
    expect(drawer.drawerBibliotecaAbierto.value).toBe(true)
    expect(cargarCotizacion).not.toHaveBeenCalled()

    aplicarItemActualizado.mockReturnValueOnce(false)
    await drawer.onServicioSueltoAgregado(item(5), { id: 1 })
    await drawer.onServicioSueltoAgregado(item(5)) // sin alternativa: recarga
    expect(cargarCotizacion).toHaveBeenCalledTimes(2)
  })

  it('el paso del drawer sigue la prioridad matriz → modo de precio → manual → grid', () => {
    const matrizHotelActiva = ref<unknown>(null)
    const modoPrecioPendiente = ref<unknown>(null)
    const mostrarFormManual = ref(false)
    const { pasoDrawer, tituloDrawer } = pasoDrawerDe({ matrizHotelActiva, modoPrecioPendiente, mostrarFormManual })
    expect([pasoDrawer.value, tituloDrawer.value]).toEqual(['grid', 'Agregar servicio'])
    mostrarFormManual.value = true
    expect(pasoDrawer.value).toBe('manual')
    modoPrecioPendiente.value = {}
    expect([pasoDrawer.value, tituloDrawer.value]).toEqual(['modoPrecio', 'Cómo se cobra'])
    matrizHotelActiva.value = {}
    expect(pasoDrawer.value).toBe('matrizHotel')
  })
})

describe('useHotelesLocales', () => {
  it('un grupo con alguna opción elegida ya no está abierto; los hoteles se cuentan por hotel, no por habitación', () => {
    const items = [
      hotel(1, 100, 'resuelto', { opcion_elegida: true }), hotel(2, 101, 'resuelto'),
      hotel(3, 200, 'abierto'), hotel(4, 200, 'abierto'),
      item(5, { grupo_opcion_id: 'abierto', opcion_hotel_tarifa: { opcion_hotel_id: 9 } }),
      hotel(6, 300, 'otro-dia', { dia_referencial: 2 }),
    ]
    const { hoteles, dia } = armar({ items })
    expect(hoteles.grupoHotelAbiertoDiaActivo.value).toEqual({ id: 'abierto', hoteles: 2, cantidad: 1 })
    dia.value = 3
    expect(hoteles.grupoHotelAbiertoDiaActivo.value).toBeNull()
  })

  it('clic en un hotel abre su matriz con el grupo abierto; en otro servicio pregunta el modo de precio', async () => {
    const { hoteles, drawer } = armar({ items: [hotel(3, 200, 'g1', { cantidad: 3 })] })
    provSvc.listarTarifas.mockResolvedValue({ proveedor_tarifas: [
      { id: 7, tipo_habitacion: 'simple', precio_venta_adulto: '80.00', precio_venta_cama_adicional: null },
    ] })
    await hoteles.clicBibliotecaItem({ id: 1, tipo_habitacion: 'doble', proveedor_servicio_id: 55, moneda: 'USD',
      proveedor_servicio: { proveedor: { id: 4, nombre_comercial: 'Hotel Sol' } } } as any)
    expect(provSvc.listarTarifas).toHaveBeenCalledWith(55, { solo_vigentes: true })
    expect(hoteles.matrizHotelActiva.value).toMatchObject({
      nombreProveedor: 'Hotel Sol', proveedorId: 4, grupoAbierto: { id: 'g1', cantidad: 3 },
      tarifas: [{ id: 7, tipo_habitacion: 'simple', precio: 80, precioVentaCamaAdicional: null }],
    })

    await hoteles.clicBibliotecaItem({ id: 2, tipo_habitacion: null, proveedor_servicio: {} } as any)
    expect(hoteles.modoPrecioPendiente.value).toEqual({ id: 2, nombre: 'este ítem' })

    drawer.volverAGridDrawer()
    expect(hoteles.matrizHotelActiva.value).toBeNull()
    expect(hoteles.modoPrecioPendiente.value).toBeNull()
  })

  it('comparar hoteles: todas las tarifas van con un mismo grupo y las noches elegidas; si hay grupo abierto, se suma a él', async () => {
    const { hoteles, aplicarItemActualizado, cargarCotizacion } = armar()
    itemSvc.agregarProveedor.mockImplementation(async (_alt: number, datos: any) => ({ alternativa_item: item(datos.proveedor_tarifa_id ?? datos.opcion_hotel_tarifa_id) }))

    await hoteles.agregarGrupoProveedorHotel([11, 12], 3)
    const [a, b] = itemSvc.agregarProveedor.mock.calls.map((c) => c[1])
    expect(a).toMatchObject({ proveedor_tarifa_id: 11, cantidad: 3, modo_precio: 'tarifa_fija' })
    expect(b.grupo_opcion_id).toBe(a.grupo_opcion_id)
    expect(a.grupo_opcion_id).toBeTruthy()
    // Solo el último ítem se avisa, sin alternativa: recarga completa.
    expect(aplicarItemActualizado).not.toHaveBeenCalled()
    expect(cargarCotizacion).toHaveBeenCalledTimes(1)

    itemSvc.agregarProveedor.mockClear()
    hoteles.matrizHotelActiva.value = { tarifas: [], moneda: 'PEN', nombreProveedor: 'X', adhoc: true, grupoAbierto: { id: 'g-abierto', hoteles: 1, cantidad: 2 } }
    await hoteles.agregarGrupoHotelAdhocLocal([21], 2)
    expect(itemSvc.agregarProveedor.mock.calls[0][1]).toMatchObject({ opcion_hotel_tarifa_id: 21, grupo_opcion_id: 'g-abierto' })
    expect(itemSvc.agregarProveedor.mock.calls[0][1]).not.toHaveProperty('proveedor_tarifa_id')
  })

  it('noches por defecto: las del destino activo, si no las de la cotización, si no 1', () => {
    const { hoteles, cotizacion, alternativa } = armar({
      destinos: [{ id: 10, fecha_inicio: '2026-12-01', fecha_fin: '2026-12-04' }],
      cotizacion: { fecha_viaje_desde: '2026-12-01', fecha_viaje_hasta: '2026-12-02' },
    })
    expect(hoteles.nochesHotelDefault.value).toEqual({ noches: 3, texto: '3 noches (01/12 – 04/12)' })
    alternativa.value.destinos = [{ id: 10, fecha_inicio: null, fecha_fin: null }]
    expect(hoteles.nochesHotelDefault.value).toEqual({ noches: 1, texto: '1 noche (01/12 – 02/12)' })
    cotizacion.value = { fecha_viaje_desde: '2026-12-05', fecha_viaje_hasta: '2026-12-01' }
    expect(hoteles.nochesHotelDefault.value).toEqual({ noches: 1 })
  })

  it('al abrir el form de hotel no registrado sin tocar, la fila toma el tratamiento tributario de la agencia', async () => {
    const { hoteles, configAgencia } = armar()
    expect(hoteles.formHotelLocal.value.tarifas[0].tip_afe_igv).toBe('10')
    configAgencia.value = { tip_afe_igv_default: '20', destino_tributario_default: 'extranjero' }
    hoteles.mostrarFormHotelLocal.value = true
    await nextTick()
    expect(hoteles.formHotelLocal.value.tarifas[0]).toMatchObject({ tip_afe_igv: '20', destino_tributario: 'extranjero' })

    hoteles.formHotelLocal.value.tarifas[0].precio_venta = 50
    hoteles.mostrarFormHotelLocal.value = false
    await nextTick()
    configAgencia.value = { tip_afe_igv_default: '10', destino_tributario_default: 'nacional' }
    hoteles.mostrarFormHotelLocal.value = true
    await nextTick()
    expect(hoteles.formHotelLocal.value.tarifas[0].precio_venta).toBe(50) // tocado: no se rearma
  })
})

describe('useBibliotecaCotizador', () => {
  it('los cuatro tipos de proveedor de más uso tienen chip fijo; el resto va a «Más», cada uno con su id', () => {
    const { biblioteca } = armar()
    biblioteca.proveedorTipos.value = [
      { id: 3, slug: 'transporte', nombre: 'Transporte' }, { id: 8, slug: 'guia', nombre: 'Guía' },
      { id: 1, slug: 'alojamiento-hoteles', nombre: 'Hoteles' },
    ] as any
    expect(biblioteca.chipsFijos.value.map((c) => [c.tipo, c.proveedorTipoId, c.nombre])).toEqual([
      ['todos', null, 'Todos'], ['tour', null, 'Tours'], ['paquete', null, 'Paquetes'],
      ['proveedor', 1, 'Alojamiento'], ['proveedor', 3, 'Transporte'],
    ])
    expect(biblioteca.chipsProveedores.value).toEqual([{ tipo: 'proveedor', proveedorTipoId: 8, nombre: 'Guía', icono: 'fa-ellipsis-h' }])

    const guia = biblioteca.chipsProveedores.value[0]
    biblioteca.seleccionarChip(guia)
    expect(biblioteca.chipActivo(guia)).toBe(true)
    expect(biblioteca.chipMasActivo.value).toEqual(guia)
    expect(bibSvc.buscar).toHaveBeenCalledWith(expect.objectContaining({ tipo: 'proveedor', proveedor_tipo_id: 8 }))
  })

  it('solo cuenta la última petición: una respuesta vieja que llega tarde se descarta', async () => {
    const { biblioteca } = armar()
    let resolverVieja!: (v: any) => void
    bibSvc.buscar
      .mockImplementationOnce(() => new Promise((r) => { resolverVieja = r }))
      .mockResolvedValueOnce({ resultados: [{ tipo_resultado: 'tour', id: 2 }] })

    const vieja = biblioteca.cargarBiblioteca()
    await biblioteca.cargarBiblioteca()
    expect(biblioteca.cargandoBiblioteca.value).toBe(false)
    resolverVieja({ resultados: [{ tipo_resultado: 'tour', id: 1 }] })
    await vieja
    expect(biblioteca.filasBibliotecaPagina.value.map((f) => f.data.id)).toEqual([2])
    expect(biblioteca.cargandoBiblioteca.value).toBe(false)
  })

  it('al abrir el drawer el filtro vuelve al destino activo y carga una sola vez, sin esperar los 300 ms', async () => {
    vi.useFakeTimers()
    const { biblioteca, drawer } = armar({ destinos: [{ id: 10, destino_atractivo_id: 44, destino_texto: 'Cusco' }] })
    biblioteca.filtroDestinoId.value = 99
    biblioteca.filtroDestinoTexto.value = 'Otro'
    await nextTick()
    vi.advanceTimersByTime(300)
    bibSvc.buscar.mockClear()

    drawer.abrirDrawerBiblioteca()
    await nextTick()
    await nextTick()
    expect(biblioteca.filtroDestinoTexto.value).toBe('Cusco')
    expect(bibSvc.buscar).toHaveBeenCalledTimes(1)
    expect(bibSvc.buscar).toHaveBeenCalledWith(expect.objectContaining({ destino_atractivo_id: 44 }))
    vi.advanceTimersByTime(500)
    expect(bibSvc.buscar).toHaveBeenCalledTimes(1)
  })

  it('un hotel con varios tipos de habitación sale una vez (el más barato); la grilla pagina de a 12', async () => {
    const { biblioteca } = armar()
    const tarifa = (id: number, extra: Record<string, unknown>) => ({ tipo_resultado: 'proveedor_tarifa', id, proveedor_servicio_id: id, ...extra })
    bibSvc.buscar.mockResolvedValue({ resultados: [
      tarifa(1, { tipo_habitacion: 'doble', proveedor_servicio_id: 50, precio_venta_adulto: '90' }),
      tarifa(2, { tipo_habitacion: 'simple', proveedor_servicio_id: 50, precio_venta_adulto: '70' }),
      ...Array.from({ length: 12 }, (_, i) => tarifa(100 + i, { tipo_habitacion: null })),
      { tipo_resultado: 'paquete', id: 900 },
    ] })
    await biblioteca.cargarBiblioteca()
    const primera = biblioteca.filasBibliotecaPagina.value
    expect(primera).toHaveLength(12)
    expect(primera[0]).toMatchObject({ kind: 'proveedor', data: { id: 2, _rangoHabitaciones: true } })
    expect(biblioteca.bibliotecaTotalPaginas.value).toBe(2)
    biblioteca.bibliotecaPaginaActual.value = 2
    expect(biblioteca.filasBibliotecaPagina.value.map((f) => [f.kind, f.data.id])).toEqual([['proveedor', 111], ['plantilla', 900]])
  })

  it('cargar un combo de varios días cierra el drawer, crea las pestañas de día y arma la vista previa', async () => {
    const { biblioteca, drawer, hoteles, alternativa, diasCreados, diasDesdeCombo, cargarCotizacion } = armar()
    hoteles.modoPrecioPendiente.value = { id: 1, nombre: 'x' }
    drawer.abrirDrawerBiblioteca()
    itemSvc.cargarDesdePlantilla.mockResolvedValue({
      guias_pendientes: [], avisos: [], dia_final_combo: 3,
      items_agregados: [{ tour_origen_id: 7 }, { tour_origen_id: 8 }],
    })
    cargarCotizacion.mockImplementation(async () => {
      alternativa.value.items = [
        item(1, { tour_origen_id: 7, dia_referencial: 1, total_convertido: '100', tour_origen: { nombre: 'City tour' } }),
        item(2, { tour_origen_id: 7, dia_referencial: 1, total_convertido: '50' }),
        item(3, { tour_origen_id: 8, dia_referencial: 3, total_convertido: '200', tour_origen: { nombre: 'Machu Picchu' } }),
      ]
    })

    await biblioteca.onClicFilaBiblioteca({ kind: 'plantilla', data: { tipo_resultado: 'paquete', id: 5 } as any })
    expect(itemSvc.cargarDesdePlantilla).toHaveBeenCalledWith(1, { paquete_plantilla_id: 5, dia_referencial: 1, alternativa_destino_id: null })
    expect(drawer.drawerBibliotecaAbierto.value).toBe(false)
    expect(hoteles.modoPrecioPendiente.value).toBeNull()
    expect(diasCreados.value).toEqual([1, 2, 3])
    expect([...diasDesdeCombo.value]).toEqual([1, 2, 3])
    expect(biblioteca.vistaPreviaCombo.value).toEqual([
      { tourOrigenId: 7, dia: 1, nombre: 'City tour', total: 150 },
      { tourOrigenId: 8, dia: 3, nombre: 'Machu Picchu', total: 200 },
    ])
    expect(biblioteca.cargandoFilaBibliotecaId.value).toBeNull()
  })

  it('en modo de tours nuevo, un tour solo de itinerario no se carga', async () => {
    const { biblioteca } = armar({ config: { modo_tours: 'nuevo' } })
    await biblioteca.onClicFilaBiblioteca({ kind: 'plantilla', data: { tipo_resultado: 'tour', id: 5, uso: 'solo_itinerario' } as any })
    expect(itemSvc.cargarDesdePlantilla).not.toHaveBeenCalled()
  })
})

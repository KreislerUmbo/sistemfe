import { describe, expect, it, vi, beforeEach } from 'vitest'
import { computed, ref } from 'vue'

const { svc, fire } = vi.hoisted(() => ({
  svc: {
    precioCarga: vi.fn(), moverCarga: vi.fn(), moverBloque: vi.fn(), elegirGrupo: vi.fn(),
    temporadasTours: vi.fn(), reasignarDia: vi.fn(),
  },
  fire: vi.fn(),
}))
vi.mock('@/services/admin/alternativaItemService', () => ({ alternativaItemService: svc }))
vi.mock('@/services/admin/alternativaDestinoService', () => ({ alternativaDestinoService: {} }))
vi.mock('sweetalert2/dist/sweetalert2.js', () => ({ default: { fire: (...a: unknown[]) => fire(...a), mixin: () => ({ fire: vi.fn() }) } }))

import { useDestinosAlternativa } from './useDestinosAlternativa'
import { useDiasLienzo } from './useDiasLienzo'
import { useLineaTour } from './useLineaTour'
import { useBloquesLienzo } from './useBloquesLienzo'

const item = (id: number, extra: Record<string, unknown> = {}) => ({
  id, alternativa_id: 1, modo_precio: 'por_persona', cantidad: 1, grupo_opcion_id: null, opcion_elegida: false,
  ajuste_de_item_id: null, alternativa_destino_id: null, dia_referencial: 1, tour_origen_id: null,
  moneda_costo: 'USD', precio_venta_snapshot: 100, precio_convertido: 100, ...extra,
}) as any

/** El lienzo armado como en la página: destinos → días → línea de tour / bloques. */
function crear(items: any[], destinos: any[] = [{ id: 10, destino_texto: 'Cusco', fecha_fin: null }]) {
  const alternativa = ref<any>({ id: 1, estado: 'borrador', items, destinos })
  const alternativaActiva = computed(() => alternativa.value)
  const itemsSinAjuste = computed(() => alternativa.value.items.filter((i: any) => !i.ajuste_de_item_id))
  const cargarCotizacion = vi.fn(async () => {})
  const d = useDestinosAlternativa({ alternativaActiva, itemsSinAjuste, cargarCotizacion })
  const dias = useDiasLienzo({ itemsDelDestinoActivo: d.itemsDelDestinoActivo })
  return { alternativa, cargarCotizacion, itemsSinAjuste, alternativaActiva, d, dias }
}

beforeEach(() => { vi.clearAllMocks() })

describe('useDestinosAlternativa', () => {
  it('resolverDestinoActivo cae al primero si el activo ya no está', () => {
    const { d } = crear([], [{ id: 10, destino_texto: 'A' }, { id: 11, destino_texto: 'B' }])
    expect(d.destinoActivoId.value).toBeNull()
    d.resolverDestinoActivo()
    expect(d.destinoActivoId.value).toBe(10)
    d.destinoActivoId.value = 11
    d.resolverDestinoActivo()
    expect(d.destinoActivoId.value).toBe(11)
    d.destinoActivoId.value = 99
    d.resolverDestinoActivo()
    expect(d.destinoActivoId.value).toBe(10)
  })

  it('con un solo destino no filtra; con varios, un ítem sin destino cuenta como del primero', () => {
    const items = [item(1, { alternativa_destino_id: 11 }), item(2), item(3, { alternativa_destino_id: 10 })]
    const uno = crear(items)
    expect(uno.d.itemsDelDestinoActivo.value.map((i: any) => i.id)).toEqual([1, 2, 3])

    const { d } = crear(items, [{ id: 10, destino_texto: 'A' }, { id: 11, destino_texto: 'B' }])
    d.destinoActivoId.value = 10
    expect(d.itemsDelDestinoActivo.value.map((i: any) => i.id)).toEqual([2, 3])
    d.destinoActivoId.value = 11
    expect(d.itemsDelDestinoActivo.value.map((i: any) => i.id)).toEqual([1])
  })

  it('abrirFormDestino sugiere el día siguiente al fin del último destino', () => {
    const { d } = crear([], [{ id: 10, destino_texto: 'A', fecha_fin: '2026-12-31' }])
    d.abrirFormDestino()
    expect(d.nuevoDestinoFechaInicio.value).toBe('2027-01-01')
    expect(d.destinoEnEdicionId.value).toBeNull()
    expect(d.mostrarFormDestino.value).toBe(true)
  })
})

describe('useDiasLienzo', () => {
  it('inicializarDias crea los días hasta el mayor y respeta «Sin día» (0)', () => {
    const { alternativa, dias } = crear([item(1, { dia_referencial: 3 }), item(2, { dia_referencial: null })])
    dias.inicializarDias()
    expect(dias.diasCreados.value).toEqual([1, 2, 3])
    dias.diaActivo.value = 0
    alternativa.value.items = [item(1, { dia_referencial: 1 })]
    dias.inicializarDias()
    expect(dias.diaActivo.value).toBe(0)
    expect(dias.diaActivoParaAgregar.value).toBe(1)
  })

  it('si el día activo ya no existe vuelve al primero', () => {
    const { alternativa, dias } = crear([item(1, { dia_referencial: 3 })])
    dias.inicializarDias()
    dias.diaActivo.value = 3
    alternativa.value.items = [item(1, { dia_referencial: 1 })]
    dias.inicializarDias()
    expect(dias.diasCreados.value).toEqual([1])
    expect(dias.diaActivo.value).toBe(1)
  })

  it('agregarDia agrega el siguiente y lo deja activo', () => {
    const { dias } = crear([item(1)])
    dias.inicializarDias()
    dias.agregarDia()
    expect(dias.diasCreados.value).toEqual([1, 2])
    expect(dias.diaActivo.value).toBe(2)
  })

  it('sin pestañas muestra todo; con pestañas, solo el día activo', () => {
    const { alternativa, dias } = crear([item(1)])
    dias.inicializarDias()
    expect(dias.mostrarTabsDia.value).toBe(false)
    alternativa.value.items = [item(1), item(2, { dia_referencial: 2 }), item(3, { dia_referencial: null })]
    dias.inicializarDias()
    expect(dias.mostrarTabsDia.value).toBe(true)
    expect(dias.itemsVisiblesLienzo.value.map((i: any) => i.id)).toEqual([1])
    dias.diaActivo.value = 0
    expect(dias.itemsVisiblesLienzo.value.map((i: any) => i.id)).toEqual([3])
  })
})

describe('useLineaTour', () => {
  const crearLinea = (pasajeros: any[], items: any[]) => {
    const base = crear(items)
    const cotizacion = ref<any>({ pasajeros })
    const linea = useLineaTour({
      cotizacion, alternativaActiva: base.alternativaActiva, alternativaSoloLectura: computed(() => false),
      cargarCotizacion: base.cargarCotizacion, subtotalGrupo: () => 300, diaActivo: base.dias.diaActivo,
    })
    return { ...base, linea }
  }

  it('desglosePrecioTour cuenta pasajeros por tipo y residencia', () => {
    const precios = { adulto: 150, nino: 130, adulto_ext: 200, nino_ext: 180 }
    const { linea } = crearLinea(
      [{ tipo_pax: 'adulto', residencia: 'peruano' }, { tipo_pax: 'adulto', residencia: 'peruano' }, { tipo_pax: 'nino', residencia: 'extranjero' }, { tipo_pax: 'infante' }],
      [],
    )
    const bloque = { items: [item(1, { moneda_costo: 'PEN', tour_linea: { precios } })] } as any
    expect(linea.desglosePrecioTour(bloque)).toBe('2 adultos × PEN 150.00 + 1 niño extranjero × PEN 180.00 + 1 infante sin costo')
    expect(linea.desglosePrecioTour({ items: [item(2)] } as any)).toBeNull()
  })

  it('onPrecioCarga no guarda si el precio es igual al subtotal y descarta el borrador', async () => {
    const { linea } = crearLinea([], [])
    linea.borradorPrecioCarga.value['c1'] = '299'
    await linea.onPrecioCarga({ carga: 'c1', items: [] } as any, { target: { value: '300' } } as any)
    expect(svc.precioCarga).not.toHaveBeenCalled()
    expect(linea.borradorPrecioCarga.value['c1']).toBeUndefined()
  })

  it('onMoverCarga no hace nada si el día elegido es el activo', async () => {
    const { linea } = crearLinea([], [])
    await linea.onMoverCarga({ carga: 'c1', items: [] } as any, { target: { value: '1' } } as any)
    expect(svc.moverCarga).not.toHaveBeenCalled()
    await linea.onMoverCarga({ carga: 'c1', cargaCombo: 'k1', items: [] } as any, { target: { value: '2' } } as any)
    expect(svc.moverCarga).toHaveBeenCalledWith(1, { carga: 'k1', dia_referencial: 2 })
  })

  it('cargarTemporadasTours solo pregunta si hay tours en una alternativa editable', async () => {
    const { linea, alternativa } = crearLinea([], [item(1)])
    linea.temporadasDesactualizadas.value = [{} as any]
    await linea.cargarTemporadasTours()
    expect(svc.temporadasTours).not.toHaveBeenCalled()
    expect(linea.temporadasDesactualizadas.value).toEqual([])

    alternativa.value.items = [item(1, { origen_tipo: 'tour' })]
    svc.temporadasTours.mockResolvedValue({ temporadas_desactualizadas: [{ id: 5 }] })
    await linea.cargarTemporadasTours()
    expect(linea.temporadasDesactualizadas.value).toEqual([{ id: 5 }])
  })
})

describe('useBloquesLienzo', () => {
  it('subtotal por destino con los ítems sin destino en el primero', () => {
    const items = [item(1, { alternativa_destino_id: 11 }), item(2), item(3, { alternativa_destino_id: 10 })]
    const { alternativaActiva, itemsSinAjuste, cargarCotizacion, d, dias } = crear(items, [{ id: 10, destino_texto: 'A' }, { id: 11, destino_texto: 'B' }])
    const b = useBloquesLienzo({
      alternativaActiva, itemsSinAjuste, cargarCotizacion, aplicarAlternativaActualizada: () => true,
      totalEfectivoLocal: (xs) => xs.length * 100, alternativaDestinos: d.alternativaDestinos,
      itemsVisiblesLienzo: dias.itemsVisiblesLienzo, diaActivo: dias.diaActivo,
    })
    expect(b.gruposPrecioPorDestino.value.map((g) => [g.destinoId, g.subtotal])).toEqual([[10, 200], [11, 100]])
  })

  it('elegirGrupoDesdeLienzo recarga si no pudo aplicar la alternativa devuelta', async () => {
    const { alternativaActiva, itemsSinAjuste, cargarCotizacion, d, dias } = crear([])
    const aplicar = vi.fn(() => false)
    const b = useBloquesLienzo({
      alternativaActiva, itemsSinAjuste, cargarCotizacion, aplicarAlternativaActualizada: aplicar,
      totalEfectivoLocal: () => 0, alternativaDestinos: d.alternativaDestinos,
      itemsVisiblesLienzo: dias.itemsVisiblesLienzo, diaActivo: dias.diaActivo,
    })
    svc.elegirGrupo.mockResolvedValue({ alternativa: { id: 1 } })
    await b.elegirGrupoDesdeLienzo(item(7))
    expect(aplicar).toHaveBeenCalledWith({ id: 1 })
    expect(cargarCotizacion).toHaveBeenCalledOnce()
    expect(b.eligiendoGrupoItemId.value).toBeNull()
  })
})

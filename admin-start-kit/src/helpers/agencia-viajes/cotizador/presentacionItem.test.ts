import { describe, expect, it } from 'vitest'
import {
  badgeTratamientoTributario, contarPasajerosDeItem, desglosePasajerosTexto, descripcionDestinoServicio, etiquetaItem,
  iconoItem, nombreMayoristaDeGrupo, tarifaAereaVencida, textoPlano, toursDeGrupo,
} from './presentacionItem'
import type { AlternativaItem, CotizacionPasajero } from '@/types/agencia-viajes'

const item = (extra: Record<string, unknown> = {}) => ({ id: 1, origen_tipo: 'proveedor', modo_precio: 'tarifa_fija', moneda_costo: 'PEN', ...extra }) as unknown as AlternativaItem
const pax = (id: number, tipo_pax: CotizacionPasajero['tipo_pax']) => ({ id, tipo_pax }) as CotizacionPasajero
const familia = [pax(1, 'adulto'), pax(2, 'adulto'), pax(3, 'nino'), pax(4, 'infante')]

describe('tarifaAereaVencida', () => {
  const ahora = Date.parse('2026-11-18T12:00:00Z')
  it('un instante pasado está vencido; uno futuro no', () => {
    expect(tarifaAereaVencida('2026-11-18T11:59:00Z', ahora)).toBe(true)
    expect(tarifaAereaVencida('2026-11-18T12:01:00Z', ahora)).toBe(false)
  })
})

describe('textoPlano', () => {
  it('cambia etiquetas por espacios y colapsa', () => {
    expect(textoPlano('<ul><li>Hotel</li><li>Desayuno</li></ul>')).toBe('Hotel Desayuno')
  })
})

describe('descripcionDestinoServicio', () => {
  it('destino · servicio, o solo el servicio sin destino', () => {
    expect(descripcionDestinoServicio({ destino_atractivo: { nombre: 'Lamas' }, servicio: { nombre: 'Traslado' } } as any)).toBe('Lamas · Traslado')
    expect(descripcionDestinoServicio({ destino_atractivo: null, servicio: { nombre: 'Traslado' } } as any)).toBe('Traslado')
    expect(descripcionDestinoServicio(undefined)).toBe('')
  })
})

describe('iconoItem', () => {
  it.each([
    [{ origen_tipo: 'tour' }, 'fa-route'],
    [{ origen_tipo: 'pasaje_aereo' }, 'fa-plane'],
    [{ origen_tipo: 'mayorista' }, 'fa-plane-departure'],
    [{ origen_tipo: 'manual' }, 'fa-pen'],
    [{ origen_tipo: 'guia' }, 'fa-user-tie'],
    [{ proveedor_tarifa: { tipo_habitacion: 'doble' } }, 'fa-bed'],
    [{ opcion_hotel_tarifa: {} }, 'fa-bed'],
    [{}, 'fa-concierge-bell'],
  ])('%o → %s', (extra, icono) => {
    expect(iconoItem(item(extra))).toBe(icono)
  })
})

describe('nombreMayoristaDeGrupo y toursDeGrupo', () => {
  it('toman el mayorista del primer ítem', () => {
    const tours = [{ id: 9 }]
    const items = [item({ opcion_mayorista: { proveedor: { razon_social: 'Costamar SAC' }, tours } })]
    expect(nombreMayoristaDeGrupo(items)).toBe('Costamar SAC')
    expect(toursDeGrupo(items)).toBe(tours)
    expect(nombreMayoristaDeGrupo([])).toBeNull()
    expect(toursDeGrupo([])).toEqual([])
  })
})

describe('etiquetaItem', () => {
  it('manual: su descripción o el genérico', () => {
    expect(etiquetaItem(item({ origen_tipo: 'manual', descripcion_manual: 'Cena show' }))).toBe('Cena show')
    expect(etiquetaItem(item({ origen_tipo: 'manual' }))).toBe('Servicio sin catálogo')
  })
  it('tour: sufijo de IGV solo si la carga tiene más de un grupo', () => {
    expect(etiquetaItem(item({ origen_tipo: 'tour', tour_linea: { tour_nombre: 'Laguna Azul', n_grupos: 1 }, tip_afe_igv: '20' }))).toBe('Laguna Azul')
    expect(etiquetaItem(item({ origen_tipo: 'tour', tour_origen: { nombre: 'Laguna Azul' }, tour_linea: { n_grupos: 2 }, tip_afe_igv: '10' }))).toBe('Laguna Azul (gravado)')
  })
  it('pasaje aéreo: la aerolínea', () => {
    expect(etiquetaItem(item({ origen_tipo: 'pasaje_aereo', cotizacion_pasaje_aereo: { aerolinea: 'LATAM' } }))).toBe('LATAM')
  })
  it('mayorista: opcional por su nombre (antes que la habitación), paquete por el proveedor, hotel de la matriz', () => {
    expect(etiquetaItem(item({ origen_tipo: 'mayorista', opcion_mayorista_opcional: { nombre: 'Isla Taboga' }, opcion_hotel_tarifa: { tipo_habitacion: 'doble' } }))).toBe('Isla Taboga')
    expect(etiquetaItem(item({ origen_tipo: 'mayorista', opcion_mayorista: { proveedor: { nombre_comercial: 'Costamar' } } }))).toBe('Costamar')
    expect(etiquetaItem(item({ origen_tipo: 'mayorista' }))).toBe('Paquete mayorista')
    expect(etiquetaItem(item({ origen_tipo: 'mayorista', opcion_hotel_tarifa: { tipo_habitacion: 'doble', opcion_hotel: { nombre_hotel: 'Riu' } } }))).toBe('Riu · doble')
  })
  it('guía: nombre y destino de la tarifa', () => {
    expect(etiquetaItem(item({ origen_tipo: 'guia', guia_tarifa: { guia: { nombre: 'Ana' }, destino: { nombre: 'Alto Mayo' } } }))).toBe('Guía: Ana — Alto Mayo')
    expect(etiquetaItem(item({ origen_tipo: 'guia' }))).toBe('Guía: Guía')
  })
  it('hotel de proveedor: «Proveedor · habitación»; otro servicio: destino · servicio', () => {
    expect(etiquetaItem(item({ proveedor_tarifa: { tipo_habitacion: 'simple', proveedor_servicio: { proveedor: { razon_social: 'Cumbaza SAC' } } } }))).toBe('Cumbaza SAC · simple')
    expect(etiquetaItem(item({ proveedor_tarifa: { proveedor_servicio: { destino_servicio: { destino_atractivo: { nombre: 'Tio Yacu' }, servicio: { nombre: 'Entrada' } } } } }))).toBe('Tio Yacu · Entrada')
    expect(etiquetaItem(item())).toBe('Servicio')
  })
})

describe('badgeTratamientoTributario', () => {
  it('null sin tip_afe_igv', () => {
    expect(badgeTratamientoTributario(item())).toBeNull()
  })
  it('tipo y destino distinto de nacional', () => {
    expect(badgeTratamientoTributario(item({ tip_afe_igv: '20', destino_tributario: 'amazonia' }))).toEqual({ texto: 'Exonerado · Amazonía', clase: 'bg-success-subtle text-success' })
    expect(badgeTratamientoTributario(item({ tip_afe_igv: '30', destino_tributario: 'extranjero' }))!.texto).toBe('Inafecto · Extranjero')
    expect(badgeTratamientoTributario(item({ tip_afe_igv: '10', destino_tributario: 'nacional' }))).toEqual({ texto: 'Gravado', clase: 'bg-secondary-subtle text-secondary' })
  })
  it('un código desconocido se muestra tal cual', () => {
    expect(badgeTratamientoTributario(item({ tip_afe_igv: '40' }))).toEqual({ texto: '40', clase: 'bg-light text-dark' })
  })
})

describe('contarPasajerosDeItem', () => {
  it('sin pax_incluidos cuenta a todos; con pax_incluidos, solo a esos', () => {
    expect(contarPasajerosDeItem(item(), familia)).toEqual({ adulto: 2, nino: 1, infante: 1 })
    expect(contarPasajerosDeItem(item({ pax_incluidos: [1, 3] }), familia)).toEqual({ adulto: 1, nino: 1, infante: 0 })
  })
})

describe('desglosePasajerosTexto', () => {
  it('manual con pax parcial: a cuántos aplica', () => {
    expect(desglosePasajerosTexto(item({ origen_tipo: 'manual', pax_incluidos: [1, 2] }), familia)).toBe('Aplica a: 2 de 4 pasajeros')
  })
  it('tarifa fija: nada', () => {
    expect(desglosePasajerosTexto(item(), familia)).toBeNull()
  })
  it('por persona con tarifa de proveedor: plural y sin decimales; omite tipos sin precio', () => {
    const it1 = item({ modo_precio: 'por_persona', proveedor_tarifa: { precio_venta_adulto: '15.00', precio_venta_nino: '12.4', precio_venta_infante: null } })
    expect(desglosePasajerosTexto(it1, familia)).toBe('2 adultos × PEN 15 + 1 niño × PEN 12')
  })
  it('pasaje aéreo: tarifas base del pasaje', () => {
    const it1 = item({ origen_tipo: 'pasaje_aereo', modo_precio: 'por_persona', pax_incluidos: [1], cotizacion_pasaje_aereo: { tarifa_base_adulto: 300, tarifa_base_nino: 0, tarifa_base_infante: null } })
    expect(desglosePasajerosTexto(it1, familia)).toBe('1 adulto × PEN 300')
  })
  it('por persona sin precios de referencia: nada', () => {
    expect(desglosePasajerosTexto(item({ modo_precio: 'por_persona' }), familia)).toBeNull()
  })
})

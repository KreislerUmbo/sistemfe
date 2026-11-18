import { describe, expect, it } from 'vitest'
import { convertirMonedaLocal, filasResumen, sumaPorGrupos, totalDeLinea, totalEfectivo } from './totales'
import type { AlternativaItem } from '@/types/agencia-viajes'

// Ítem mínimo para los cálculos; `precio` hace de precio_convertido.
type Item = Pick<AlternativaItem, 'id' | 'modo_precio' | 'cantidad' | 'grupo_opcion_id' | 'opcion_elegida' | 'ajuste_de_item_id'> & { precio: number }
let siguienteId = 1
const item = (precio: number, extra: Partial<Item> = {}): Item => ({
  id: siguienteId++, modo_precio: 'por_persona', cantidad: 1, grupo_opcion_id: null, opcion_elegida: false, ajuste_de_item_id: null, precio, ...extra,
})
const totalDe = (i: Item) => totalDeLinea(i.precio, i)

describe('convertirMonedaLocal', () => {
  it('misma moneda: solo redondea a 2 decimales', () => {
    expect(convertirMonedaLocal(10.005, 'PEN', 'PEN', 3.7)).toBe(10.01)
  })
  it('USD → PEN multiplica por el tipo de cambio', () => {
    expect(convertirMonedaLocal(100, 'USD', 'PEN', 3.756)).toBe(375.6)
  })
  it('PEN → USD divide por el tipo de cambio', () => {
    expect(convertirMonedaLocal(100, 'PEN', 'USD', 3)).toBe(33.33)
  })
})

describe('totalDeLinea', () => {
  it('tarifa fija × cantidad', () => {
    expect(totalDeLinea(150, { modo_precio: 'tarifa_fija', cantidad: 3 })).toBe(450)
  })
  it('por persona ya viene resuelto', () => {
    expect(totalDeLinea(180, { modo_precio: 'por_persona', cantidad: 3 })).toBe(180)
  })
})

// Mismos casos que AlternativaItem::calcularTotalEfectivo() del backend.
describe('totalEfectivo', () => {
  it('sin grupos suma todo', () => {
    expect(totalEfectivo([item(100), item(50)], totalDe)).toBe(150)
  })
  it('grupo abierto (sin elegida) cuenta el mínimo', () => {
    const items = [item(120, { grupo_opcion_id: 'g1' }), item(160, { grupo_opcion_id: 'g1' }), item(30)]
    expect(totalEfectivo(items, totalDe)).toBe(150)
  })
  it('grupo resuelto cuenta solo la elegida, aunque no sea la más barata', () => {
    const items = [item(120, { grupo_opcion_id: 'g1' }), item(160, { grupo_opcion_id: 'g1', opcion_elegida: true })]
    expect(totalEfectivo(items, totalDe)).toBe(160)
  })
  it('el ajuste no suma: ya está dentro del precio del servicio', () => {
    const servicio = item(200)
    expect(totalEfectivo([servicio, item(25, { ajuste_de_item_id: servicio.id })], totalDe)).toBe(200)
  })
  it('tarifa fija se multiplica por cantidad, también dentro de un grupo', () => {
    const items = [
      item(150, { modo_precio: 'tarifa_fija', cantidad: 3 }),
      item(100, { modo_precio: 'tarifa_fija', cantidad: 2, grupo_opcion_id: 'g1' }),
      item(90, { modo_precio: 'tarifa_fija', cantidad: 3, grupo_opcion_id: 'g1' }),
    ]
    expect(totalEfectivo(items, totalDe)).toBe(450 + 200)
  })
  it('usa el precio que se le pasa (el que se está editando), no otro', () => {
    const a = item(100)
    expect(totalEfectivo([a], () => 77)).toBe(77)
  })
})

describe('sumaPorGrupos', () => {
  it('misma regla de grupos pero sin excluir ajustes (recibe la lista ya filtrada)', () => {
    const servicio = item(200)
    expect(sumaPorGrupos([servicio, item(25, { ajuste_de_item_id: servicio.id })], totalDe)).toBe(225)
  })
})

describe('filasResumen', () => {
  it('una línea por grupo: la elegida', () => {
    const elegida = item(160, { grupo_opcion_id: 'g1', opcion_elegida: true })
    const suelto = item(30)
    expect(filasResumen([item(120, { grupo_opcion_id: 'g1' }), elegida, suelto], totalDe)).toEqual([suelto, elegida])
  })
  it('grupo abierto: la de menor total; con empate, la primera', () => {
    const a = item(120, { grupo_opcion_id: 'g1' })
    const b = item(120, { grupo_opcion_id: 'g1' })
    const c = item(90, { grupo_opcion_id: 'g2' })
    const d = item(80, { grupo_opcion_id: 'g2' })
    expect(filasResumen([a, b, c, d], totalDe)).toEqual([a, d])
  })
})

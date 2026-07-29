// src/services/admin/opcionMayoristaService.ts — Sesión 11b (cotizador)
import httpClient from '@/helpers/http-client'

export const opcionMayoristaService = {
  async listar(alternativaId: number) {
    const response = await httpClient.get(`/alternativas/${alternativaId}/opciones-mayorista`)
    return response.data
  },
  async crear(alternativaId: number, data: {
    proveedor_id: number
    salida_mayorista_id?: number | null
    moneda: 'PEN' | 'USD'
    incluye?: string
    notas?: string
    vuelo_aerolinea?: string
    vuelo_detalle?: string
  }) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/opciones-mayorista`, data)
    return response.data
  },
  async elegir(id: number) {
    const response = await httpClient.post(`/opciones-mayorista/${id}/elegir`)
    return response.data
  },
  async listarHoteles(opcionMayoristaId: number) {
    const response = await httpClient.get(`/opciones-mayorista/${opcionMayoristaId}/hoteles`)
    return response.data
  },
  async crearHotel(opcionMayoristaId: number, data: {
    nombre_hotel: string
    categoria_estrellas?: number
    proveedor_id?: number | null
    tarifas?: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number }>
  }) {
    const response = await httpClient.post(`/opciones-mayorista/${opcionMayoristaId}/hoteles`, data)
    return response.data
  },
  async listarOpcionales(opcionMayoristaId: number) {
    const response = await httpClient.get(`/opciones-mayorista/${opcionMayoristaId}/opcionales`)
    return response.data
  },
  async crearOpcional(opcionMayoristaId: number, data: {
    nombre: string
    precio_por_persona: number
    moneda: 'PEN' | 'USD'
    incluye?: string
    no_incluye?: string
  }) {
    const response = await httpClient.post(`/opciones-mayorista/${opcionMayoristaId}/opcionales`, data)
    return response.data
  }
}

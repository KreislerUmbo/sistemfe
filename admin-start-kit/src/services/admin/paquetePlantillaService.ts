// src/services/admin/paquetePlantillaService.ts — Sesión 11b2 (agencia de viajes)
import httpClient from '@/helpers/http-client'
import type {
  PaquetePlantilla,
  PaquetePlantillaItem,
  TourItinerarioItem,
  OpcionHotel,
  PaquetePlantillaShowResponse,
} from '@/types/agencia-viajes'

export const paquetePlantillaService = {
  async listar(params: { page?: number; search?: string; categoria?: string; tipo?: string; activo?: boolean } = {}) {
    const response = await httpClient.get('/paquetes-plantilla', { params })
    return response.data
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/paquetes-plantilla/${id}`)
    return response.data as PaquetePlantillaShowResponse
  },
  async crear(data: FormData | Record<string, any>) {
    const response = await httpClient.post('/paquetes-plantilla', data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async actualizar(id: number, data: FormData | Record<string, any>) {
    const response = await httpClient.post(`/paquetes-plantilla/${id}`, data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/paquetes-plantilla/${id}`)
    return response.data
  },

  // ── Items incluidos (proveedor_tarifa o guia_tarifa) ────────────────
  async listarItems(paqueteId: number) {
    const response = await httpClient.get(`/paquetes-plantilla/${paqueteId}/items`)
    return response.data as { paquete_plantilla_items: PaquetePlantillaItem[] }
  },
  async agregarItem(paqueteId: number, data: { proveedor_tarifa_id?: number; guia_tarifa_id?: number; paquete_plantilla_hijo_id?: number; orden?: number }) {
    const response = await httpClient.post(`/paquetes-plantilla/${paqueteId}/items`, data)
    return response.data
  },
  async quitarItem(itemId: number) {
    const response = await httpClient.delete(`/paquete-plantilla-items/${itemId}`)
    return response.data
  },

  // ── Itinerario día-por-día ───────────────────────────────────────────
  async listarItinerario(paqueteId: number) {
    const response = await httpClient.get(`/paquetes-plantilla/${paqueteId}/itinerario`)
    return response.data as { tour_itinerario_items: TourItinerarioItem[] }
  },
  async agregarPasoItinerario(paqueteId: number, data: Partial<TourItinerarioItem>) {
    const response = await httpClient.post(`/paquetes-plantilla/${paqueteId}/itinerario`, data)
    return response.data
  },
  async quitarPasoItinerario(itemId: number) {
    const response = await httpClient.delete(`/tour-itinerario-items/${itemId}`)
    return response.data
  },

  // ── Matriz de hotel (opciones_hotel/tarifas) ─────────────────────────
  async listarHoteles(paqueteId: number) {
    const response = await httpClient.get(`/paquetes-plantilla/${paqueteId}/hoteles`)
    return response.data as { opciones_hotel: OpcionHotel[] }
  },
  async agregarHotel(paqueteId: number, data: {
    nombre_hotel: string
    categoria_estrellas?: number | null
    proveedor_id?: number | null
    tarifas: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number }>
  }) {
    const response = await httpClient.post(`/paquetes-plantilla/${paqueteId}/hoteles`, data)
    return response.data
  },
  async quitarHotel(hotelId: number) {
    const response = await httpClient.delete(`/paquete-plantilla-hoteles/${hotelId}`)
    return response.data
  },
}

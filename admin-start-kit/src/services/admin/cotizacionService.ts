// src/services/admin/cotizacionService.ts — Sesión 11b (cotizador)
import httpClient from '@/helpers/http-client'
import type { Cotizacion, CotizacionPasajero } from '@/types/agencia-viajes'

export const cotizacionService = {
  async listar(params: { page?: number; search?: string; estado?: string } = {}) {
    const response = await httpClient.get('/cotizaciones', { params })
    return response.data
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/cotizaciones/${id}`)
    return response.data as { cotizacion: Cotizacion }
  },
  async crear(data: {
    cliente_id: number
    destino: string
    fecha_viaje_desde?: string | null
    fecha_viaje_hasta?: string | null
    pasajeros: Array<{ edad: number }>
  }) {
    const response = await httpClient.post('/cotizaciones', data)
    return response.data
  },
  // id presente → edita ese pasajero puntual; sin id → lo crea. El
  // backend hace diff por id (no borra-y-recrea todo), y devuelve cuántos
  // ítems 'por_persona' recalculó solo vs. cuáles quedaron pendientes de
  // revisar a mano — ver CotizacionController::actualizarPasajeros().
  async actualizarPasajeros(id: number, pasajeros: Array<Pick<CotizacionPasajero, 'edad'> & Partial<Pick<CotizacionPasajero, 'id'>>>) {
    const response = await httpClient.put(`/cotizaciones/${id}/pasajeros`, { pasajeros })
    return response.data as {
      code: number
      message: string
      cotizacion: Cotizacion
      items_recalculados: number
      items_para_revisar: Array<{ alternativa_item_id: number; alternativa_id: number; alternativa_nombre: string; motivo: string }>
    }
  },
  // Corregir cliente/destino/fechas después de creada — antes solo existía
  // store()/actualizarPasajeros(), sin forma de arreglar un dato mal
  // tipeado al crear.
  async actualizar(id: number, data: {
    cliente_id: number
    destino: string
    fecha_viaje_desde?: string | null
    fecha_viaje_hasta?: string | null
  }) {
    const response = await httpClient.put(`/cotizaciones/${id}`, data)
    return response.data as { code: number; message: string; cotizacion: Cotizacion }
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/cotizaciones/${id}`)
    return response.data as { code: number; message: string }
  }
}

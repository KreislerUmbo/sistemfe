// src/services/admin/reporteOperativoService.ts — Sesión 11d (pantalla del reporte
// operativo). El GET base ya existía desde la Sesión 11e (backend real); acá se agregan
// PDF (URL firmada, mismo criterio que cash/history.vue) y Excel (pedido nuevo del
// usuario, no estaba en el spec original).
import httpClient from '@/helpers/http-client'
import type { ReporteOperativoFiltrosDisponibles, ReporteOperativoResponse } from '@/types/agencia-viajes'

export type ReporteOperativoFiltros = {
  fecha_desde?: string
  fecha_hasta?: string
  pendiente_asignar?: boolean
  // Mejoras post-11d: destino/tour/hotel/servicio, todos opcionales, combinables entre sí.
  destino_atractivo_id?: number
  servicio_id?: number
  tour_id?: number
  hotel_proveedor_id?: number
}

export const reporteOperativoService = {
  async obtener(filtros: ReporteOperativoFiltros = {}) {
    const response = await httpClient.get('/reporte-operativo', { params: filtros })
    return response.data as ReporteOperativoResponse
  },

  // Catálogo de opciones para los 4 selects, acotado al rango de fecha vigente — se
  // recarga solo cuando cambia el rango, no en cada tick de filtro.
  async filtrosDisponibles(filtros: Pick<ReporteOperativoFiltros, 'fecha_desde' | 'fecha_hasta'> = {}) {
    const response = await httpClient.get('/reporte-operativo/filtros', { params: filtros })
    return response.data as ReporteOperativoFiltrosDisponibles
  },

  async pdfUrl(filtros: ReporteOperativoFiltros = {}) {
    const response = await httpClient.get('/reporte-operativo/pdf-url', { params: filtros })
    return response.data as { url: string }
  },

  async exportarExcel(filtros: ReporteOperativoFiltros = {}) {
    const response = await httpClient.get('/reporte-operativo/export', {
      params: filtros,
      responseType: 'blob',
    })
    return response.data as Blob
  },

  async checkin(reservaItemId: number, reservaPasajeroId: number, checkin_realizado: boolean) {
    const response = await httpClient.post(
      `/reserva-items/${reservaItemId}/pasajeros/${reservaPasajeroId}/checkin`,
      { checkin_realizado }
    )
    return response.data
  },
}

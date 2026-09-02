// src/services/admin/reservaService.ts — Sesión 11c (reserva y pasajeros)
import httpClient from '@/helpers/http-client'
import type { ReservaDetalleResponse, Reservas, MotivoCancelacion, Reserva } from '@/types/agencia-viajes'

export const reservaService = {
  async listar(params: { page?: number; search?: string; estado?: 'activa' | 'cancelada' } = {}) {
    const response = await httpClient.get('/reservas', { params })
    return response.data as Reservas
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/reservas/${id}`)
    return response.data as ReservaDetalleResponse
  },
  // Aceptar una alternativa (AlternativaController::update() en 11b ya NO
  // dispara esto — este es el endpoint nuevo de 11c). pasajeroCatalogoIds
  // es opcional y alineado por orden con cotizacion_pasajeros.
  async aceptarAlternativa(alternativaId: number, pasajeroCatalogoIds?: Array<number | null>) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/aceptar`, {
      pasajero_catalogo_ids: pasajeroCatalogoIds ?? []
    })
    return response.data as ReservaDetalleResponse & { code: number; message: string }
  },
  async cancelar(id: number, motivo_cancelacion: MotivoCancelacion) {
    const response = await httpClient.put(`/reservas/${id}/cancelar`, { motivo_cancelacion })
    return response.data
  },
  // Opción C acordada: nunca automático — el staff dispara la
  // sincronización a demanda para reflejar servicios agregados a la
  // cotización DESPUÉS de que la reserva ya está activa.
  async sincronizarItems(id: number) {
    const response = await httpClient.post(`/reservas/${id}/sincronizar-items`)
    return response.data as ReservaDetalleResponse & { code: number; message: string }
  },
  // Fase 2 del fix Cotización↔Reserva (2026-08-19) — mueve
  // fecha_viaje_desde/hasta de la reserva y recalcula solo los
  // reserva_items con fecha_origen='auto'; los 'manual' quedan intactos y
  // vuelven en items_no_tocados.
  async reprogramar(id: number, payload: { fecha_viaje_desde: string; fecha_viaje_hasta?: string | null; motivo: string }) {
    const response = await httpClient.post(`/reservas/${id}/reprogramar`, payload)
    return response.data as ReservaDetalleResponse & {
      code: number
      message: string
      // motivo: 'manual' (fecha editada a mano antes de reprogramar) |
      // 'sin_dia_referencial' (no hay insumo para recalcularla en
      // automático) — guardia de visibilidad agregado 2026-08-20, antes
      // este segundo caso quedaba invisible en la respuesta.
      items_no_tocados: Array<{ reserva_item_id: number; nombre: string; fecha: string | null; motivo: 'manual' | 'sin_dia_referencial' }>
    }
  },
  // Sesión 12h — reasignación en vivo de OpcionMayorista en ReservaItem
  // (auditoria-arquitectonica-agencia-viajes.md §9.2). nueva_opcion_hotel_tarifa_id
  // es opcional — solo se usa para calcular costo_anterior/costo_nuevo de
  // la respuesta (presentación, nunca se persiste); sin ella costo_nuevo
  // vuelve null. NUNCA toca precio_venta_snapshot del cliente.
  async reasignarMayorista(id: number, payload: {
    reserva_item_ids: number[]
    nueva_opcion_mayorista_id: number
    nueva_opcion_hotel_tarifa_id?: number | null
    motivo: string
  }) {
    const response = await httpClient.post(`/reservas/${id}/reasignar-mayorista`, payload)
    return response.data as ReservaDetalleResponse & {
      code: number
      message: string
      costo_anterior: number
      costo_nuevo: number | null
    }
  },
  // Facturación externa por tenant + por reserva (PEGAR-EN-CLAUDE-CODE-
  // facturacion-externa-tenant.md, 2026-08-20) — solo editable mientras la
  // reserva no tenga ninguna venta asociada (422 si ya la tiene, ver
  // facturacion_externa_editable en ReservaDetalleResponse).
  async actualizarFacturacionExterna(
    id: number,
    payload: { facturacion_externa: boolean; referencia_externa?: string | null; fecha_facturacion_externa?: string | null }
  ) {
    const response = await httpClient.put(`/reservas/${id}/facturacion-externa`, payload)
    return response.data as { code: number; message: string; reserva: Reserva }
  }
}

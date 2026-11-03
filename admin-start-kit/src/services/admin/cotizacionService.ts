// src/services/admin/cotizacionService.ts — Sesión 11b (cotizador)
import httpClient from '@/helpers/http-client'
import type { Cotizacion, CotizacionPasajero, TemporadaDesactualizada } from '@/types/agencia-viajes'

export const cotizacionService = {
  async listar(params: {
    page?: number
    per_page?: number
    search?: string
    estado?: string
    estado_resumen?: string
    origen_contacto_id?: number
    fecha_desde?: string
    fecha_hasta?: string
    // T-053: «Por seguir hoy».
    por_seguir?: 1
  } = {}) {
    const response = await httpClient.get('/cotizaciones', { params })
    return response.data as { total: number; paginate: number; cotizaciones: Cotizacion[] }
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/cotizaciones/${id}`)
    // modificacion: solo si es una cotización de modificación de reserva (T-007).
    return response.data as { cotizacion: Cotizacion; modificacion?: { id: number; estado: 'abierta' | 'aplicada' | 'descartada'; reserva_id: number; reserva_codigo: string | null } | null }
  },
  async crear(data: {
    cliente_id: number
    destino: string
    fecha_viaje_desde?: string | null
    fecha_viaje_hasta?: string | null
    pasajeros: Array<{ edad: number }>
    // T-033 — la primera alternativa nace con la cotización.
    alternativa_nombre?: string | null
    moneda_cotizacion?: 'PEN' | 'USD'
    tipo_cambio_valor?: number | null
    tipo_cambio_confirmado?: boolean
    // T-059 — por dónde llegó la consulta (opcional).
    origen_contacto_id?: number | null
  }) {
    const response = await httpClient.post('/cotizaciones', data)
    return response.data
  },
  // id presente → edita ese pasajero puntual; sin id → lo crea. El
  // backend hace diff por id (no borra-y-recrea todo), y devuelve cuántos
  // ítems 'por_persona' recalculó solo vs. cuáles quedaron pendientes de
  // revisar a mano — ver CotizacionController::actualizarPasajeros().
  async actualizarPasajeros(id: number, pasajeros: Array<Pick<CotizacionPasajero, 'edad'> & Partial<Pick<CotizacionPasajero, 'id' | 'residencia'>>>) {
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
    // temporadas_desactualizadas (fila 13, fase 1b, §3.12): tours que con la
    // fecha nueva cayeron en otra temporada; solo aviso, no se recalculan solos.
    return response.data as { code: number; message: string; cotizacion: Cotizacion; temporadas_desactualizadas?: TemporadaDesactualizada[] }
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/cotizaciones/${id}`)
    return response.data as { code: number; message: string }
  },
  // T-052 (CRM C3): descarta las alternativas abiertas; «Reabrir» las devuelve a su estado.
  async cerrarComoPerdida(id: number, data: { motivo_id: number; detalle?: string | null }) {
    const response = await httpClient.post(`/cotizaciones/${id}/perdida`, data)
    return response.data as { code: number; message: string }
  },
  async reabrir(id: number) {
    const response = await httpClient.post(`/cotizaciones/${id}/reabrir`)
    return response.data as { code: number; message: string }
  }
}

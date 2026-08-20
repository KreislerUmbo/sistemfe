// src/services/admin/reservaFacturacionService.ts — Fase A del plan
// "Proceso de reserva: facturación + 3 fixes" (2026-08-19). Genera un
// Sale/SaleDetail/SaleDetailItem/ReservaVenta real a partir de una reserva
// aceptada. La venta nace pendiente de cobro — cobrar y enviar a SUNAT
// siguen el flujo normal ya existente (Cuentas por Cobrar / editar venta),
// esta pantalla solo crea el comprobante.
//
// Facturación múltiple por grupo de pasajeros (2026-08-20): ya no hay un
// solo Sale por reserva — el vendedor elige QUÉ pasajeros factura en cada
// pasada (pasajero_ids), con su propio client_id (obligatorio) y un texto
// personalizado opcional. Los ítems se resuelven automáticamente a partir
// de los pasajeros elegidos (reserva_item_pasajero), salvo los "sin
// asignar" (sin ningún pasajero vinculado — el caso más común hoy), que
// se agregan a mano vía reserva_item_ids_manual.
import httpClient from '@/helpers/http-client'

export type FacturarReservaPayload = {
  pasajero_ids: number[]
  reserva_item_ids_manual?: number[]
  tipo_comprobante_codigo: '01' | '03'
  client_id: number
  texto_personalizado?: string | null
}

export type FacturarReservaResponse = {
  code: number
  message: string
  sale_id: number
  serie: string
  lineas: number
  pasajeros_facturados: number
}

export type ItemPendientePorPasajeroFaltante = {
  reserva_item_id: number
  nombre: string
  pasajeros_faltantes: number[]
}

export type ItemSinAsignarDisponible = {
  reserva_item_id: number
  nombre: string
  total: number
}

// Guardia tributario (2026-08-20): si el subconjunto a facturar mezcla
// ítems con distinto destino_tributario (ej. exonerado Amazonía + gravado
// nacional), ni el preview ni el POST real dejan facturar en un solo
// comprobante — riesgo de emitir un comprobante SUNAT con la exoneración
// mal calculada. Se evalúa por Sale (subconjunto elegido), no por reserva
// completa — dos pasajeros distintos pueden terminar en Sales con
// distinto destino_tributario cada uno.
export type PrepararFacturaResponse = {
  code: number
  bloqueado_tributario: boolean
  motivo?: string
  destinos_tributarios_detectados?: string[]
  grupos_propuestos?: Array<{
    categoria: string
    cantidad_items: number
    subtotal: number
    igv: number
    total: number
  }>
  subtotal?: number
  igv?: number
  total?: number
  pasajeros_incluidos: number[]
  pasajeros_pendientes: number[]
  items_pendientes_por_pasajero_faltante: ItemPendientePorPasajeroFaltante[]
  items_sin_asignar_disponibles: ItemSinAsignarDisponible[]
  cliente_sugerido?: { id: number; full_name: string; n_document: string }
}

export const reservaFacturacionService = {
  async facturar(reservaId: number, payload: FacturarReservaPayload) {
    const response = await httpClient.post(`/reservas/${reservaId}/facturar`, payload)
    return response.data as FacturarReservaResponse
  },
  async prepararFactura(reservaId: number, pasajeroIds: number[], reservaItemIdsManual: number[] = []) {
    const response = await httpClient.get(`/reservas/${reservaId}/preparar-factura`, {
      params: { pasajero_ids: pasajeroIds, reserva_item_ids_manual: reservaItemIdsManual }
    })
    return response.data as PrepararFacturaResponse
  }
}

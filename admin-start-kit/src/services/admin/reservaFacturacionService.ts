// src/services/admin/reservaFacturacionService.ts — Fase A del plan
// "Proceso de reserva: facturación + 3 fixes" (2026-08-19). Genera un
// Sale/SaleDetail/SaleDetailItem/ReservaVenta real a partir de una reserva
// aceptada. La venta nace pendiente de cobro — cobrar y enviar a SUNAT
// siguen el flujo normal ya existente (Cuentas por Cobrar / editar venta),
// esta pantalla solo crea el comprobante.
import httpClient from '@/helpers/http-client'

export type FacturarReservaPayload = {
  reserva_item_ids: number[]
  tipo_comprobante_codigo: '01' | '03'
  client_id?: number | null
}

export type FacturarReservaResponse = {
  code: number
  message: string
  sale_id: number
  serie: string
  lineas: number
}

// Guardia tributario (2026-08-20): si la reserva mezcla ítems con distinto
// destino_tributario (ej. exonerado Amazonía + gravado nacional), ni el
// preview ni el POST real dejan facturar en un solo comprobante — riesgo
// de emitir un comprobante SUNAT con la exoneración mal calculada.
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
}

export const reservaFacturacionService = {
  async facturar(reservaId: number, payload: FacturarReservaPayload) {
    const response = await httpClient.post(`/reservas/${reservaId}/facturar`, payload)
    return response.data as FacturarReservaResponse
  },
  async prepararFactura(reservaId: number, reservaItemIds: number[]) {
    const response = await httpClient.get(`/reservas/${reservaId}/preparar-factura`, {
      params: { reserva_item_ids: reservaItemIds }
    })
    return response.data as PrepararFacturaResponse
  }
}

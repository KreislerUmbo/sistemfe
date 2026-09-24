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
  // 2026-09-24 — solo tiene efecto si el usuario tiene can_switch_branch
  // (mismo criterio que sale/register.vue); sin ese permiso el backend lo
  // ignora y usa la sucursal propia del usuario.
  branch_id?: number | null
  // Facturar en una moneda distinta a la de la cotización (2026-09-24 —
  // caso real: cotización en USD, cliente pide el comprobante en soles).
  // Sin esto: se factura en la moneda de la cotización, como siempre.
  // Con esto: tipo_cambio_conversion y motivo_cambio_moneda son
  // OBLIGATORIOS para el backend (422 si faltan) — ver
  // reservaFacturacionService.prepararFactura() para el tipo de cambio
  // sugerido antes de confirmar.
  moneda_facturacion?: 'PEN' | 'USD' | null
  tipo_cambio_conversion?: number | null
  motivo_cambio_moneda?: string | null
  // Tier 0 — conexión Adelantos↔Reservas: vacío/omitido = auto-aplica el
  // 100% de los anticipos disponibles de la reserva (para ESTE cliente);
  // poblado = el vendedor eligió a mano cuáles y cuánto.
  advance_applications?: Array<{ advance_id: number; amount: number }>
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

// Tier 0 — conexión Adelantos↔Reservas: TODOS los anticipos de la reserva
// con saldo disponible, sin filtrar por cliente (prepararFactura() no
// recibe client_id todavía) — store() sí valida que el advance_id elegido
// pertenezca al mismo cliente de la factura, rechazando con 422 si no.
export type AnticipoDisponiblePreview = {
  id: number
  advance_id: number
  disponible: number
  moneda: 'PEN' | 'USD'
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
  // 2026-09-23 — mapeo por servicio de qué destino_tributario tiene cada
  // uno (antes solo llegaban los valores distintos detectados, sin decir
  // qué servicio es cuál).
  // 2026-09-24 — ahora viaja SIEMPRE (antes solo cuando bloqueado_tributario
  // era true), para que el vendedor vea el tratamiento de lo que seleccionó
  // sin tener que chocar con un bloqueo primero.
  items_por_destino_tributario?: Array<{ reserva_item_id: number; nombre: string; destino_tributario: string; tip_afe_igv: string }>
  // 2026-09-24 — Caso 3 Amazonía: servicios homogéneos en Amazonía que
  // TODAVÍA no fueron confirmados uno por uno (ver reservaService.
  // overrideTratamientoTributario()). Solo viene poblado en ese motivo
  // específico de bloqueo, nunca junto con mezcla o extranjero.
  items_sin_confirmar_ids?: number[]
  // Facturar en otra moneda (2026-09-24) — moneda_cotizacion_original es
  // siempre la de la reserva; moneda_facturacion es la que se está
  // previsualizando (default = la misma, si no se pidió otra en el
  // query). tipo_cambio_sugerido viene de TipoCambioSunatResolver (venta
  // del día, o el último hábil anterior) — null si no hay dato SUNAT
  // disponible. tipo_cambio_aplicado es el que realmente se usó para
  // calcular subtotal/igv/total/grupos_propuestos de este preview (el
  // pasado por query si vino, si no el sugerido) — null si no hay ninguno
  // disponible (en ese caso los montos del preview siguen en la moneda
  // original, sin convertir).
  moneda_cotizacion_original?: string
  moneda_facturacion?: string
  tipo_cambio_sugerido?: number | null
  tipo_cambio_aplicado?: number | null
  // Serie que se va a usar (2026-09-24, pedido del usuario: "debe aparecer
  // de manera visible la serie") — null si todavía no se eligió tipo de
  // comprobante, o si no hay ninguna serie activa configurada para esa
  // sucursal/tipo/moneda (el vendedor lo descubre acá, antes de intentar
  // confirmar, en vez de con un 422 al hacer clic en "Emitir comprobante").
  serie_resuelta?: string | null
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
  anticipos_disponibles?: AnticipoDisponiblePreview[]
}

export const reservaFacturacionService = {
  async facturar(reservaId: number, payload: FacturarReservaPayload) {
    const response = await httpClient.post(`/reservas/${reservaId}/facturar`, payload)
    return response.data as FacturarReservaResponse
  },
  async prepararFactura(
    reservaId: number,
    pasajeroIds: number[],
    reservaItemIdsManual: number[] = [],
    opciones: {
      monedaFacturacion?: 'PEN' | 'USD' | null
      tipoCambioConversion?: number | null
      branchId?: number | null
      tipoComprobanteCodigo?: '01' | '03' | null
    } = {}
  ) {
    const response = await httpClient.get(`/reservas/${reservaId}/preparar-factura`, {
      params: {
        pasajero_ids: pasajeroIds,
        reserva_item_ids_manual: reservaItemIdsManual,
        moneda_facturacion: opciones.monedaFacturacion || undefined,
        tipo_cambio_conversion: opciones.tipoCambioConversion || undefined,
        branch_id: opciones.branchId || undefined,
        tipo_comprobante_codigo: opciones.tipoComprobanteCodigo || undefined
      }
    })
    return response.data as PrepararFacturaResponse
  }
}

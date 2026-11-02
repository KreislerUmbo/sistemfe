// src/services/admin/reservaModificacionService.ts — T-007 "Modificar reserva"
// Rutas de ReservaModificacionController / ReservaModificacionPenalidadController (permiso
// reservas.modificar). Los tipos de la diferencia son los de DiferenciaModificacion::toArray().
import httpClient from '@/helpers/http-client'

export type EstadoLineaDiferencia =
  | 'igual' | 'agregado' | 'quitado' | 'cambio_precio' | 'para_revisar' | 'requiere_nota_credito' | 'ajuste'

export type ComposicionPasajeros = Record<string, number>

export type LineaDiferencia = {
  clave: string
  estado: EstadoLineaDiferencia
  descripcion: string
  alternativa_item_id: number | null
  reserva_item_id: number | null
  vendido_centavos: number
  nuevo_centavos: number
  diferencia_centavos: number
  facturado: boolean
  sale_ids: number[]
  sin_pasajeros: boolean
  pasajeros_vendidos: number | null
  pasajeros_nuevos: number | null
  composicion_vendida: ComposicionPasajeros | null
  composicion_nueva: ComposicionPasajeros | null
  revisado: boolean
  tip_afe_igv: string | null
  destino_tributario: string | null
}

export type PasajeroDiferencia = {
  clave: string
  estado: 'igual' | 'agregado' | 'quitado' | 'requiere_nota_credito'
  reserva_pasajero_id: number | null
  cotizacion_pasajero_id: number | null
  nombre: string | null
  tipo_pax: string | null
  facturado: boolean
  sale_ids: number[]
  falta_penalidad: boolean
  penalidad_alternativa_item_id: number | null
  penalidad_sobrante_item_id: number | null
}

export type TipoBloqueo =
  | 'servicio_sin_pasajeros' | 'para_revisar' | 'requiere_nota_credito' | 'penalidad_sobrante' | 'falta_penalidad'

export type BloqueoDiferencia = {
  tipo: TipoBloqueo
  mensaje: string
  alternativa_item_id: number | null
  sale_id: number | null
  reserva_pasajero_id: number | null
}

// Del total solo se muestran vendido, nuevo y diferencia (T-006): ya_facturado y
// anticipos_aplicados son aproximados y no se le enseñan al vendedor.
export type DiferenciaModificacion = {
  moneda: string
  lineas: LineaDiferencia[]
  pasajeros: PasajeroDiferencia[]
  totales: { vendido: number; nuevo: number; diferencia: number }
  bloqueos: BloqueoDiferencia[]
  hay_cambios: boolean
  se_puede_aplicar: boolean
  huella: string
}

export type PenalidadPayload = {
  monto: number
  motivo: string
  tip_afe_igv: string
  destino_tributario: string
}

export type PasajeroEdadEstimada = { reserva_pasajero_id: number; nombre: string | null; tipo_pax: string; edad_estimada: number }

export const reservaModificacionService = {
  async crear(reservaId: number) {
    const r = await httpClient.post(`/reservas/${reservaId}/modificacion`)
    return r.data as {
      code: number
      message: string
      cotizacion_id: number
      pasajeros_edad_estimada: PasajeroEdadEstimada[]
    }
  },
  async diferencia(reservaId: number) {
    const r = await httpClient.get(`/reservas/${reservaId}/modificacion/diferencia`)
    return r.data as DiferenciaModificacion
  },
  async descartar(reservaId: number) {
    const r = await httpClient.delete(`/reservas/${reservaId}/modificacion`)
    return r.data as { code: number; message: string }
  },
  async marcarRevisado(reservaId: number, itemId: number) {
    const r = await httpClient.post(`/reservas/${reservaId}/modificacion/items/${itemId}/revisado`)
    return r.data as DiferenciaModificacion
  },
  async aplicar(reservaId: number, huella: string) {
    const r = await httpClient.post(`/reservas/${reservaId}/modificacion/aplicar`, { huella })
    return r.data as { code: number; message: string; alerta_cupo_excedido: boolean }
  },
  async crearPenalidad(reservaId: number, reservaPasajeroId: number, payload: PenalidadPayload) {
    const r = await httpClient.post(`/reservas/${reservaId}/modificacion/penalidades`, { reserva_pasajero_id: reservaPasajeroId, ...payload })
    return r.data as DiferenciaModificacion
  },
  async actualizarPenalidad(reservaId: number, itemId: number, payload: PenalidadPayload) {
    const r = await httpClient.put(`/reservas/${reservaId}/modificacion/penalidades/${itemId}`, payload)
    return r.data as DiferenciaModificacion
  },
  async borrarPenalidad(reservaId: number, itemId: number) {
    const r = await httpClient.delete(`/reservas/${reservaId}/modificacion/penalidades/${itemId}`)
    return r.data as DiferenciaModificacion
  },
  // PDF para el cliente: la abierta, o una aplicada (modificacion_id). Devuelve el Blob.
  async pdf(reservaId: number, modificacionId?: number) {
    const r = await httpClient.get(`/reservas/${reservaId}/modificacion/pdf`, {
      params: modificacionId ? { modificacion_id: modificacionId } : undefined,
      responseType: 'blob',
    })
    return r.data as Blob
  },
}

// src/services/admin/fichaClienteAgenciaService.ts — CRM C5 (T-060): la ficha del cliente en
// Agencia. Preferencias del viajero, resumen, «Atendido por última vez por» y línea de tiempo.
// Solo giro Agencia (el backend responde 404 en otro giro).
import httpClient from '@/helpers/http-client'

export type MonedaPerfil = 'PEN' | 'USD'

export type PerfilViajero = {
  tipos_viaje: number[]
  tipos_viaje_nombres: string[]
  presupuesto_por_persona: number | null
  moneda: MonedaPerfil | null
  alimentacion: string | null
  movilidad: string | null
  hotel_preferido: string | null
  notas: string | null
  /** Sin ningún dato: el cotizador no muestra el recuadro. */
  vacio: boolean
  actualizado_en: string | null
}

export type DatosPerfilViajero = Omit<PerfilViajero, 'tipos_viaje_nombres' | 'vacio' | 'actualizado_en'>

export type TipoViaje = { id: number; nombre: string; activo: boolean; orden: number }

export type MontoMoneda = { moneda: string; monto: number }

export type ResumenClienteAgencia = {
  cotizaciones: number
  ganadas: number
  perdidas: number
  viajes_realizados: number
  ultimo_viaje: { reserva_id: number; codigo: string; desde: string; hasta: string | null } | null
  total_comprado: MontoMoneda[]
}

export type AtendidoPor = { usuario_id: number; nombre: string; fecha: string }

export type TipoEvento = 'cotizacion' | 'reserva' | 'comprobante' | 'pago' | 'actividad' | 'accion'

export type EnlaceEvento = { cotizacion_id?: number; reserva_id?: number | null } | null

/** Un evento de la línea de tiempo. Los campos dependen del tipo; fecha = instante UTC (ISO). */
export type EventoLineaTiempo = {
  id: string
  tipo: TipoEvento
  fecha: string
  titulo: string
  detalle?: string | null
  estado?: string
  resultado?: { tipo: 'ganada' | 'perdida'; motivo: string; detalle: string | null } | null
  origen?: string | null
  usuario?: string | null
  venta_directa?: boolean
  viaje?: { desde: string | null; hasta: string | null }
  clase?: string | null
  monto?: number
  moneda?: string
  enviado?: boolean
  /** T-104: comprobante emitido a este cliente en una reserva que cotizó otro (sub-factura). */
  reserva_de?: string | null
  traspaso?: boolean
  nro_operacion?: string | null
  canal?: string | null
  nota?: string | null
  pendiente?: boolean
  dia?: string
  enlace: EnlaceEvento
}

export const TIPOS_EVENTO: { valor: TipoEvento; etiqueta: string }[] = [
  { valor: 'cotizacion', etiqueta: 'Cotizaciones' },
  { valor: 'reserva', etiqueta: 'Reservas' },
  { valor: 'comprobante', etiqueta: 'Comprobantes' },
  { valor: 'pago', etiqueta: 'Pagos' },
  { valor: 'actividad', etiqueta: 'Contactos' },
  { valor: 'accion', etiqueta: 'Acciones' },
]

export const fichaClienteAgenciaService = {
  async perfil(clienteId: number) {
    const response = await httpClient.get(`/clients/${clienteId}/perfil-viajero`)
    return response.data.perfil as PerfilViajero | null
  },
  async guardarPerfil(clienteId: number, datos: DatosPerfilViajero) {
    const response = await httpClient.put(`/clients/${clienteId}/perfil-viajero`, datos)
    return response.data as { message: string; perfil: PerfilViajero }
  },
  async resumen(clienteId: number) {
    const response = await httpClient.get(`/clients/${clienteId}/resumen-agencia`)
    return response.data as { resumen: ResumenClienteAgencia; atendido_por: AtendidoPor | null }
  },
  async lineaTiempo(clienteId: number, params: { tipo?: TipoEvento | null; pagina?: number } = {}) {
    const response = await httpClient.get(`/clients/${clienteId}/linea-tiempo`, {
      params: { tipo: params.tipo || undefined, pagina: params.pagina ?? 1 },
    })
    return response.data as { eventos: EventoLineaTiempo[]; hay_mas: boolean }
  },
}

// Catálogo de tipos de viaje (Configuración de Agencia). Sin borrar: se desactiva.
export const crmTipoViajeService = {
  async listar(params: { solo_activos?: boolean } = {}) {
    const response = await httpClient.get('/crm-tipos-viaje', { params: { solo_activos: params.solo_activos ? 1 : undefined } })
    return response.data.tipos as TipoViaje[]
  },
  async crear(data: { nombre: string }) {
    const response = await httpClient.post('/crm-tipos-viaje', data)
    return response.data.tipo as TipoViaje
  },
  async actualizar(id: number, data: Partial<Pick<TipoViaje, 'nombre' | 'activo' | 'orden'>>) {
    const response = await httpClient.put(`/crm-tipos-viaje/${id}`, data)
    return response.data.tipo as TipoViaje
  },
}

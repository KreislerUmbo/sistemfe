// Módulo Créditos — cliente de la API de routes/creditos.php (Fase 3). Una función por
// endpoint. Las escrituras llevan clave_idempotencia (useClaveIdempotencia): el backend
// devuelve la misma respuesta si la clave se repite con el mismo contenido.
import httpClient from '@/helpers/http-client'
import type {
  CondicionesCredito,
  ConfiguracionCredito,
  Credito,
  CotizacionPago,
  CreditoCreado,
  DestinoExcedente,
  EstadoCaja,
  DetalleCredito,
  EstadoCuenta,
  Liquidacion,
  MetodoPago,
  Pago,
  PreviewRenovacion,
  PreviewReprogramacion,
  SolicitudCobro,
  SolicitudReprogramacion,
  PreviewCredito,
  ReglaLimite,
  ResumenClienteCredito,
} from '@/types/creditos'

type ConClave<T> = T & { clave_idempotencia: string }

export const creditoService = {
  async preview(condiciones: CondicionesCredito, signal?: AbortSignal) {
    const { data } = await httpClient.post('/creditos/preview', condiciones, { signal })
    return data as PreviewCredito
  },

  async crear(condiciones: ConClave<CondicionesCredito>) {
    const { data } = await httpClient.post('/creditos', condiciones)
    return data as CreditoCreado
  },

  async actualizar(id: number, condiciones: ConClave<CondicionesCredito>) {
    const { data } = await httpClient.put(`/creditos/${id}`, condiciones)
    return data as { credito: Credito }
  },

  async obtener(id: number) {
    const { data } = await httpClient.get(`/creditos/${id}`)
    return data as DetalleCredito
  },

  async estadoCuenta(id: number) {
    const { data } = await httpClient.get(`/creditos/${id}/estado-cuenta`)
    return data as EstadoCuenta
  },

  async cotizarLiquidacion(id: number, fecha?: string) {
    const { data } = await httpClient.get(`/creditos/${id}/liquidacion`, { params: fecha ? { fecha } : {} })
    return data as Liquidacion
  },

  async liquidar(id: number, cuerpo: { monto_recibido: string; payment_method_id: number; referencia?: string | null }, clave: string) {
    const { data } = await httpClient.post(`/creditos/${id}/liquidar`, { ...cuerpo, clave_idempotencia: clave })
    return data as { pago: Pago }
  },

  async cotizarPago(id: number, montoRecibido: string, destino: DestinoExcedente, signal?: AbortSignal) {
    const { data } = await httpClient.post(`/creditos/${id}/pagos/cotizar`, { monto_recibido: montoRecibido, destino_excedente: destino }, { signal })
    return data as CotizacionPago
  },

  async cobrar(id: number, solicitud: SolicitudCobro, clave: string) {
    const { data } = await httpClient.post(`/creditos/${id}/pagos`, { ...solicitud, clave_idempotencia: clave })
    return data as { pago: Pago }
  },

  async anularPago(id: number, pagoId: number, motivo: string, clave: string) {
    const { data } = await httpClient.post(`/creditos/${id}/pagos/${pagoId}/anular`, { motivo, clave_idempotencia: clave })
    return data as { pago: Pago }
  },

  async editarPago(id: number, pagoId: number, cuerpo: { referencia: string | null; observaciones: string | null }) {
    const { data } = await httpClient.patch(`/creditos/${id}/pagos/${pagoId}`, cuerpo)
    return data as { pago: Pago }
  },

  /** anular (crédito), castigar y revertir-castigo: solo piden motivo. */
  async accionConMotivo(id: number, accion: 'anular' | 'castigar' | 'revertir-castigo', motivo: string, clave: string) {
    const { data } = await httpClient.post(`/creditos/${id}/${accion}`, { motivo, clave_idempotencia: clave })
    return data as { credito: Credito }
  },

  async condonar(id: number, cuotaId: number, monto: string, motivo: string, clave: string) {
    const { data } = await httpClient.post(`/creditos/${id}/condonar-mora`, { cuota_id: cuotaId, monto, motivo, clave_idempotencia: clave })
    return data
  },

  async previewReprogramacion(id: number, solicitud: SolicitudReprogramacion) {
    const { data } = await httpClient.post(`/creditos/${id}/reprogramar/preview`, solicitud)
    return data as PreviewReprogramacion
  },

  async reprogramar(id: number, solicitud: SolicitudReprogramacion & { motivo: string }, clave: string) {
    const { data } = await httpClient.post(`/creditos/${id}/reprogramar`, { ...solicitud, clave_idempotencia: clave })
    return data
  },

  async corregir(id: number, condiciones: CondicionesCredito, motivo: string, clave: string) {
    const { cliente_id: _cliente, ...sinCliente } = condiciones
    const { data } = await httpClient.post(`/creditos/${id}/corregir`, { ...sinCliente, motivo, clave_idempotencia: clave })
    return data as { credito: Credito }
  },

  async previewRenovacion(id: number, condiciones: CondicionesCredito, signal?: AbortSignal) {
    const { cliente_id: _cliente, ...sinCliente } = condiciones
    const { data } = await httpClient.post(`/creditos/${id}/renovar/preview`, sinCliente, { signal })
    return data as PreviewRenovacion
  },

  async renovar(id: number, condiciones: CondicionesCredito, motivoAutorizacion: string | null, clave: string) {
    const { cliente_id: _cliente, ...sinCliente } = condiciones
    const { data } = await httpClient.post(`/creditos/${id}/renovar`, { ...sinCliente, motivo_autorizacion: motivoAutorizacion, clave_idempotencia: clave })
    return data as { credito: Credito }
  },

  async activar(id: number, clave: string) {
    const { data } = await httpClient.post(`/creditos/${id}/activar`, { clave_idempotencia: clave })
    return data as { credito: Credito }
  },

  async autorizar(id: number, regla: ReglaLimite, motivo: string, clave: string) {
    const { data } = await httpClient.post(`/creditos/${id}/autorizaciones`, { regla, motivo, clave_idempotencia: clave })
    return data
  },

  async resumenCliente(clienteId: number) {
    const { data } = await httpClient.get(`/clientes/${clienteId}/resumen-credito`)
    return data as ResumenClienteCredito
  },

  async configuracion() {
    const { data } = await httpClient.get('/creditos/configuracion')
    return data as ConfiguracionCredito
  },

  /**
   * Sesión de caja del usuario (aviso de la pantalla Cobrar). null si no tiene permiso de
   * caja: el backend igual rechaza el cobro sin caja abierta.
   */
  async estadoCaja(): Promise<EstadoCaja | null> {
    try {
      const { data } = await httpClient.get('/cash/status')
      if (!data.has_open_session) return { abierta: false, caja: null, cajero: null, desde: null }
      const s = data.session
      return { abierta: true, caja: s.cash_register?.name ?? null, cajero: s.opened_by_user?.name ?? null, desde: s.opened_at ?? null }
    } catch (e) {
      if ((e as { response?: { status?: number } }).response?.status === 403) return null
      throw e
    }
  },

  async metodosPago() {
    const { data } = await httpClient.get('/payment-methods', { params: { active: 1 } })
    return (data.payment_methods ?? []) as MetodoPago[]
  },
}

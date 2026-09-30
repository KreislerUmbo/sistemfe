// Módulo Créditos — cliente de la API de routes/creditos.php (Fase 3). Una función por
// endpoint. Las escrituras llevan clave_idempotencia (useClaveIdempotencia): el backend
// devuelve la misma respuesta si la clave se repite con el mismo contenido.
import httpClient from '@/helpers/http-client'
import type {
  CondicionesCredito,
  ConfiguracionCredito,
  Credito,
  CreditoCreado,
  MetodoPago,
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
    return data as { credito: Credito; resumen: Record<string, string | number>; cuotas: Credito['cuotas'] }
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

  async metodosPago() {
    const { data } = await httpClient.get('/payment-methods', { params: { active: 1 } })
    return (data.payment_methods ?? []) as MetodoPago[]
  },
}

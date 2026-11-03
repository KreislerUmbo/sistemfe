// src/services/admin/crmActividadService.ts — CRM C1 (T-050): historial de contactos con el
// cliente. "Enviar" la alternativa por WhatsApp u otro medio, "Anotar contacto" y el
// historial de una cotización. El envío por correo sigue en correoCotizacionService.
import httpClient from '@/helpers/http-client'
import type { ProximaAccion } from '@/services/admin/crmAccionService'

export type TipoActividad = 'envio' | 'llamada' | 'whatsapp' | 'visita' | 'nota' | 'estado'
export type TipoContactoManual = 'llamada' | 'whatsapp' | 'visita' | 'nota'
export type CanalEnvio = 'correo' | 'whatsapp' | 'otro'

export type ActividadHistorial = {
  id: string
  tipo: TipoActividad
  canal: CanalEnvio | null
  alternativa: string | null
  nota: string | null
  usuario: string
  fecha: string
  /** Envío por correo que no salió: no es un contacto, pero se muestra. */
  fallido: boolean
  error: string | null
}

export const TIPOS_CONTACTO: { valor: TipoContactoManual; etiqueta: string }[] = [
  { valor: 'llamada', etiqueta: 'Llamada' },
  { valor: 'whatsapp', etiqueta: 'WhatsApp' },
  { valor: 'visita', etiqueta: 'Visita' },
  { valor: 'nota', etiqueta: 'Nota' },
]

export const crmActividadService = {
  // T-051: proxima_accion = la llamada propuesta (null = quitada).
  async marcarEnviada(alternativaId: number, data: { canal: 'whatsapp' | 'otro'; nota?: string | null; proxima_accion?: ProximaAccion }) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/marcar-enviada`, data)
    return response.data as {
      message: string
      estado_alternativa: string
      fecha_envio: string | null
      fecha_vencimiento: string | null
    }
  },
  // T-053: nueva fecha de vencimiento (YYYY-MM-DD, desde hoy) de una alternativa enviada.
  async extenderVigencia(alternativaId: number, fecha: string) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/extender-vigencia`, { fecha })
    return response.data as { message: string; fecha_vencimiento: string }
  },
  async anotar(clienteId: number, data: {
    tipo: TipoContactoManual
    nota?: string | null
    referencia_tipo?: 'cotizacion' | null
    referencia_id?: number | null
  }) {
    const response = await httpClient.post(`/clients/${clienteId}/actividades`, data)
    return response.data as { message: string; actividad_id: number }
  },
  async historialCotizacion(cotizacionId: number) {
    const response = await httpClient.get(`/cotizaciones/${cotizacionId}/actividades`)
    return (response.data.actividades ?? []) as ActividadHistorial[]
  },
}

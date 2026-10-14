// src/services/admin/correoCotizacionService.ts — Envío de la cotización por correo
// (plan-modulo-envio-correo-cotizacion.md). Cotizador y sección "Correo" de
// Configuración de Agencia.
import httpClient from '@/helpers/http-client'

export type RemitenteCorreo = {
  email: string
  nombre: string
  modo: 'dominio_agencia' | 'plataforma'
  reply_to: string | null
}

export type EstadoCorreo = {
  habilitado: boolean
  limite_diario: number
  enviados_hoy: number
  remitente: RemitenteCorreo
  dominio: { dominio: string; estado: 'pendiente' | 'verificado' | 'fallido' } | null
}

export type BorradorEnvio = EstadoCorreo & {
  asunto: string
  mensaje: string
  para: string[]
  adjuntar_condiciones: boolean
  estado_alternativa: string
  puede_marcar_enviada: boolean
}

export type EnvioCorreo = {
  id: number
  fecha: string
  usuario: string
  para: string[]
  cc: string[]
  remitente: string
  asunto: string
  adjuntos: string[]
  estado: 'enviado' | 'fallido'
  error: string | null
}

export type ConfiguracionCorreo = {
  remitente_nombre: string | null
  remitente_local: string | null
  reply_to: string | null
  copia_oculta_activa: boolean
  copia_oculta_email: string | null
  asunto_plantilla: string | null
  cuerpo_plantilla: string | null
  firma: string | null
  adjuntar_condiciones_por_defecto: boolean
}

export type ConfiguracionCorreoRespuesta = EstadoCorreo & {
  configuracion_correo: ConfiguracionCorreo
  respaldo: string
  variables: string[]
  por_defecto: { asunto: string; cuerpo: string }
}

export const correoCotizacionService = {
  async estado() {
    const response = await httpClient.get('/correo-cotizaciones/estado')
    return response.data as EstadoCorreo
  },
  async preparar(alternativaId: number) {
    const response = await httpClient.get(`/alternativas/${alternativaId}/envio-correo`)
    return response.data as BorradorEnvio
  },
  async enviar(alternativaId: number, data: {
    para: string[]
    cc: string[]
    asunto: string
    mensaje: string
    adjuntar_cotizacion: boolean
    adjuntar_condiciones: boolean
    marcar_enviada: boolean
  }) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/enviar-correo`, data)
    return response.data as { message: string; estado_alternativa: string; enviados_hoy: number }
  },
  async historial(alternativaId: number) {
    const response = await httpClient.get(`/alternativas/${alternativaId}/envios-correo`)
    return (response.data.envios ?? []) as EnvioCorreo[]
  },
  async configuracion() {
    const response = await httpClient.get('/configuracion-correo')
    return response.data as ConfiguracionCorreoRespuesta
  },
  async guardarConfiguracion(data: ConfiguracionCorreo) {
    const response = await httpClient.put('/configuracion-correo', data)
    return response.data as ConfiguracionCorreoRespuesta
  },
  async enviarPrueba() {
    const response = await httpClient.post('/configuracion-correo/prueba')
    return response.data as { message: string }
  },
}

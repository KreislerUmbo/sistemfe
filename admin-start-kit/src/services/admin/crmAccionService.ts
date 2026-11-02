// src/services/admin/crmAccionService.ts — CRM C2 (T-051): próximas acciones con el cliente
// (llamar, escribir, cobrar un saldo…) con fecha y responsable. Se agendan desde la cotización,
// la reserva o la ficha, se proponen al enviar una cotización y se cierran con "Marcar hecha".
import httpClient from '@/helpers/http-client'
import { hoyPeru, sumarDiasISO } from '@/helpers/fecha'
import type { TipoContactoManual } from '@/services/admin/crmActividadService'

export type TipoAccion = 'llamar' | 'whatsapp' | 'enviar_cotizacion' | 'cobrar_saldo' | 'saludar' | 'otro'
export type ReferenciaCrm = 'cotizacion' | 'reserva'

export type OpcionesAcciones = {
  dias_proximo_contacto: number
  tipos: { valor: TipoAccion; etiqueta: string }[]
  responsables: { id: number; nombre: string }[]
}

export type AccionPendiente = {
  id: number
  cliente_id: number
  referencia_tipo: ReferenciaCrm | null
  referencia_id: number | null
  tipo: TipoAccion
  tipo_etiqueta: string
  descripcion: string | null
  fecha: string
  responsable: { id: number; nombre: string }
  puede_gestionar: boolean
}

/** La acción "Llamar" que se propone al enviar; null = el vendedor la quitó. */
export type ProximaAccion = { fecha: string; responsable_id: number } | null

export type NuevaAccion = {
  tipo: TipoAccion
  fecha: string
  responsable_id: number
  descripcion?: string | null
}

/** Dónde se miran las pendientes: una cotización, una reserva o todo el cliente (ficha). */
export type ContextoAcciones =
  | { referencia_tipo: ReferenciaCrm; referencia_id: number }
  | { cliente_id: number }

/** Fecha propuesta: hoy en Perú + los días configurados. */
export const fechaPropuesta = (dias: number, hoy: string = hoyPeru()): string => sumarDiasISO(hoy, dias)

// Las opciones (días, tipos, usuarios activos) cambian poco: una consulta por carga de la app.
let opcionesEnCurso: Promise<OpcionesAcciones> | null = null

export const crmAccionService = {
  opciones(): Promise<OpcionesAcciones> {
    opcionesEnCurso ??= httpClient.get('/crm/acciones/opciones')
      .then((r) => r.data as OpcionesAcciones)
      .catch((error) => {
        opcionesEnCurso = null
        throw error
      })
    return opcionesEnCurso
  },
  async pendientes(contexto: ContextoAcciones) {
    const response = await httpClient.get('/crm/acciones', { params: contexto })
    return (response.data.acciones ?? []) as AccionPendiente[]
  },
  async agendar(clienteId: number, data: NuevaAccion & { referencia_tipo?: ReferenciaCrm | null; referencia_id?: number | null }) {
    const response = await httpClient.post(`/clients/${clienteId}/acciones`, data)
    return response.data as { message: string; accion_id: number }
  },
  async reprogramar(accionId: number, data: { fecha: string; responsable_id?: number }) {
    const response = await httpClient.put(`/crm-acciones/${accionId}`, data)
    return response.data as { message: string }
  },
  async marcarHecha(accionId: number, data: {
    resultado?: { tipo: TipoContactoManual; nota?: string | null } | null
    siguiente?: NuevaAccion | null
  }) {
    const response = await httpClient.post(`/crm-acciones/${accionId}/hecha`, data)
    return response.data as { message: string; actividad_id: number | null; siguiente_id: number | null }
  },
}

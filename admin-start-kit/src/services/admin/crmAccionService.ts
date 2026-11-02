// src/services/admin/crmAccionService.ts — CRM C2 (T-051): próximas acciones con el cliente
// (llamar, escribir, cobrar un saldo…) con fecha y responsable. Se agendan desde la cotización,
// la reserva o la ficha, se proponen al enviar una cotización y se cierran con "Marcar hecha".
import httpClient from '@/helpers/http-client'
import { hoyPeru, sumarDiasISO } from '@/helpers/fecha'
import type { TipoContactoManual } from '@/services/admin/crmActividadService'

export type TipoAccion = 'llamar' | 'whatsapp' | 'enviar_cotizacion' | 'cobrar_saldo' | 'saludar' | 'otro'
export type ReferenciaCrm = 'cotizacion' | 'reserva'

export type OpcionesAcciones = {
  /** El usuario de la sesión (el del login guardado no trae su id). */
  usuario_id: number
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

// Las opciones (días, tipos, usuarios activos) cambian poco: una consulta por sesión. Traen el
// usuario_id de la sesión, así que se olvidan al cerrar sesión (el logout no recarga la página:
// sin esto, quien entra después proponía las llamadas a nombre del anterior) y al guardar la
// Configuración de agencia (dias_proximo_contacto).
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
  olvidarOpciones() {
    opcionesEnCurso = null
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

// ── Bandeja de pendientes (Inicio y Seguimiento) ──
// Una sola bandeja (decidido 27-oct-2026): hoy solo trae acciones del CRM, pero cada fila dice su
// `fuente`. Un pendiente de operación (fila 11f) no se marca hecho: se resuelve en su pantalla
// (`enlace`) y desaparece solo. La pantalla no supone que toda fila es una acción manual.
export type FuentePendiente = 'crm' | 'operacion'
export type BloquePendiente = 'atrasadas' | 'hoy' | 'proximos'

export type FilaBandeja = {
  id: string
  fuente: FuentePendiente
  bloque: BloquePendiente
  fecha: string
  titulo: string
  descripcion: string | null
  cliente: { id: number; nombre: string } | null
  referencia: { tipo: ReferenciaCrm; id: number; codigo: string | null } | null
  responsable: { id: number; nombre: string } | null
  /** Solo fuente crm: la acción que se marca hecha o se reprograma desde la fila. */
  accion: AccionPendiente | null
  /** Pendientes automáticos: dónde se resuelven. */
  enlace: string | null
}

export type FiltrosBandeja = {
  ambito?: 'mios' | 'todos'
  responsable_id?: number | null
  tipo?: TipoAccion | null
  hasta?: string
}

export type RespuestaBandeja = { hoy: string; hasta: string; puede_ver_todos: boolean; filas: FilaBandeja[] }

export const bandejaService = {
  async pendientes(filtros: FiltrosBandeja = {}) {
    const response = await httpClient.get('/crm/pendientes', { params: filtros })
    return response.data as RespuestaBandeja
  },
}

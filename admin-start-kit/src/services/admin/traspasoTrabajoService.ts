// src/services/admin/traspasoTrabajoService.ts — CRM (T-061): pasar el trabajo abierto (cotizaciones
// abiertas y acciones pendientes) de un usuario que se va a otro. Solo Agencia; desde Usuarios y
// desde el aviso de Inicio.
import httpClient from '@/helpers/http-client'

export type TrabajoAbierto = { cotizaciones: number; acciones: number }

export type ResumenTraspaso = {
  usuario: { id: number; nombre: string }
  data: TrabajoAbierto
  /** Usuarios activos que pueden recibirlo (con permiso para editar cotizaciones), sin el origen. */
  destinos: { id: number; nombre: string }[]
}

export type ResultadoTraspaso = TrabajoAbierto & { clientes: number }

/** «2 cotizaciones abiertas y 1 acción pendiente». */
export const textoTrabajo = ({ cotizaciones, acciones }: TrabajoAbierto): string => {
  const partes: string[] = []
  if (cotizaciones) partes.push(`${cotizaciones} ${cotizaciones === 1 ? 'cotización abierta' : 'cotizaciones abiertas'}`)
  if (acciones) partes.push(`${acciones} ${acciones === 1 ? 'acción pendiente' : 'acciones pendientes'}`)
  return partes.join(' y ')
}

export const tieneTrabajo = (t: TrabajoAbierto): boolean => t.cotizaciones + t.acciones > 0

export const traspasoTrabajoService = {
  async resumen(usuarioId: number) {
    const { data } = await httpClient.get(`/users/${usuarioId}/trabajo-abierto`)
    return data as ResumenTraspaso
  },
  async traspasar(usuarioId: number, haciaUsuarioId: number) {
    const { data } = await httpClient.post(`/users/${usuarioId}/traspasar-trabajo`, { hacia_usuario_id: haciaUsuarioId })
    return data.data as ResultadoTraspaso
  },
}

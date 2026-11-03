// src/services/admin/crmOrigenContactoService.ts — CRM C4 (T-059): por dónde llegó un contacto
// (WhatsApp, Facebook…). Configuración de Agencia lo mantiene (sin borrar: se desactiva);
// «Nueva cotización», «Nueva consulta» y la ficha del cliente leen la lista.
import httpClient from '@/helpers/http-client'

export type OrigenContacto = {
  id: number
  nombre: string
  activo: boolean
  orden: number
}

export const crmOrigenContactoService = {
  /** ultimo_del_usuario: origen de su cotización más reciente con origen (si sigue activo). */
  async listar(params: { solo_activos?: boolean } = {}) {
    const response = await httpClient.get('/crm-origenes-contacto', {
      params: { solo_activos: params.solo_activos ? 1 : undefined },
    })
    return response.data as { origenes: OrigenContacto[]; ultimo_del_usuario: number | null }
  },
  async crear(data: { nombre: string }) {
    const response = await httpClient.post('/crm-origenes-contacto', data)
    return response.data.origen as OrigenContacto
  },
  async actualizar(id: number, data: Partial<Pick<OrigenContacto, 'nombre' | 'activo' | 'orden'>>) {
    const response = await httpClient.put(`/crm-origenes-contacto/${id}`, data)
    return response.data.origen as OrigenContacto
  },
}

// src/services/admin/consultaService.ts — CRM C4 (T-059): «Nueva consulta». Crea (o usa) el cliente
// y la cotización con su «Alternativa A» en un solo paso.
import httpClient from '@/helpers/http-client'

export type CoincidenciaCliente = {
  id: number
  full_name: string
  phone: string | null
  type_document: string | null
}

export type DatosConsulta = {
  cliente_id?: number | null
  nombre: string
  telefono?: string | null
  origen_contacto_id?: number | null
  destino: string
  fecha_viaje_desde?: string | null
  fecha_viaje_hasta?: string | null
  pasajeros: number
  nota?: string | null
  agendar_envio?: boolean
  tipo_cambio_valor?: number
  tipo_cambio_confirmado?: boolean
}

export const consultaService = {
  async coincidencias(params: { telefono?: string; nombre?: string }) {
    const response = await httpClient.get('/crm/consultas/coincidencias', { params })
    return response.data.clientes as CoincidenciaCliente[]
  },
  async crear(data: DatosConsulta) {
    const response = await httpClient.post('/crm/consultas', data)
    return response.data as { cotizacion: { id: number; codigo: string }; cliente_creado: boolean }
  },
}

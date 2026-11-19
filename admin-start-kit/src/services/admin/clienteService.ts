// Clientes (Fase 4c, todos los giros). Alta/edición van por helpers/clientes/guardarCliente
// (resuelve confirmar nombre repetido y restaurar eliminados).
import httpClient from '@/helpers/http-client'
import type { Client } from '@/types/clients'

export interface ContextoCliente {
  giro: string | null
  /** Tenant del giro Créditos: la página muestra ficha de cobro, ubicación, documentos y cartera. */
  creditos: boolean
}

export const clienteService = {
  async contexto() {
    const { data } = await httpClient.get('/clients/contexto')
    return data as ContextoCliente
  },

  async obtener(id: number) {
    const { data } = await httpClient.get(`/clients/${id}`)
    return data.client as Client & { regimen_tributario?: string; es_agente_retencion?: boolean }
  },
}

// T-065 — otras direcciones del cliente (sucursales). El comprobante copia solo `direccion`.
export interface DireccionCliente {
  id: number
  client_id: number
  nombre: string
  direccion: string
  referencia: string | null
  region: string | null
  provincia: string | null
  distrito: string | null
  ubigeo_region: string | null
  ubigeo_provincia: string | null
  ubigeo_distrito: string | null
  activo: boolean
}

export type DatosDireccionCliente = Omit<DireccionCliente, 'id' | 'client_id'>

export const direccionClienteService = {
  /** `todas`: incluye las desactivadas (la ficha las muestra atenuadas; los selectores de emisión no). */
  async listar(clienteId: number, todas = false) {
    const { data } = await httpClient.get(`/clients/${clienteId}/direcciones`, { params: todas ? { todas: 1 } : {} })
    return data.direcciones as DireccionCliente[]
  },

  async crear(clienteId: number, datos: Omit<DatosDireccionCliente, 'activo'>) {
    const { data } = await httpClient.post(`/clients/${clienteId}/direcciones`, datos)
    return data.direccion as DireccionCliente
  },

  async actualizar(id: number, datos: DatosDireccionCliente) {
    const { data } = await httpClient.put(`/client-direcciones/${id}`, datos)
    return data.direccion as DireccionCliente
  },
}

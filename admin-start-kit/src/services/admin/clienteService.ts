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

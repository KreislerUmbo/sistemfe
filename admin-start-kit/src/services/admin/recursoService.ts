// frontend/src/services/manualService.ts
import httpClient from '@/helpers/http-client'

export interface RecursoManual {
  id?: number
  titulo: string
  descripcion?: string
  tipo: 'video' | 'documento' | 'imagen' | 'link'
  url?: string
  archivo?: string | File
  miniatura?: string | File
  categoria?: string
  orden: number
  destacado: boolean
  estado: boolean
  created_at?: string
  updated_at?: string
}

export const recursoService = {
  async listar(params: any = {}) {
    const response = await httpClient.get('/recursos', { params })
    return response.data
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/recursos/${id}`)
    return response.data
  },
  async crear(data: FormData | RecursoManual) {
    const response = await httpClient.post('/recursos', data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async actualizar(id: number, data: FormData | RecursoManual) {
    const response = await httpClient.post(`/recursos/${id}`, data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/recursos/${id}`)
    return response.data
  },
  // Para la galería (vendedores)
  async listarPublicos(params: any = {}) {//recursos publicos son los que se muestran en la galeria
    const response = await httpClient.get('/recursos/publicos', { params })
    return response.data
  }
}
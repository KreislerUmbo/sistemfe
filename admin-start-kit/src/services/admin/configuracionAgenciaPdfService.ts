// src/services/admin/configuracionAgenciaPdfService.ts — Mejora del PDF de
// cotización (plan-mejora-pdf-cotizacion-cliente.md §4.4). Singleton por
// tenant, mismo patrón que configuracionAgenciaService — un solo GET/PUT,
// más 4 endpoints propios para el header/footer custom (multipart).
import httpClient from '@/helpers/http-client'

export interface ConfiguracionAgenciaPdf {
  id?: number
  color_primario: string | null
  color_secundario: string | null
  color_categoria_local: string | null
  color_categoria_nacional: string | null
  color_categoria_internacional: string | null
  eslogan: string | null
  redes_sociales: Array<{ red: 'facebook' | 'instagram' | 'tiktok'; usuario: string }> | null
  mostrar_fotos_tour: boolean
  mostrar_afiliaciones: boolean
  imagen_header_custom?: string | null
  imagen_footer_custom?: string | null
  imagen_header_custom_url?: string | null
  imagen_footer_custom_url?: string | null
}

export interface AfiliacionTurismoOpcion {
  id: number
  codigo: string
  nombre: string
  logo_url: string | null
  marcada: boolean
  numero_registro: string | null
}

export const configuracionAgenciaPdfService = {
  async obtener() {
    const response = await httpClient.get('/configuracion-agencia-pdf')
    return response.data as { configuracion_agencia_pdf: ConfiguracionAgenciaPdf; afiliaciones: AfiliacionTurismoOpcion[] }
  },
  async actualizar(data: ConfiguracionAgenciaPdf & { afiliaciones: Array<{ afiliacion_id: number; numero_registro: string | null }> }) {
    const response = await httpClient.put('/configuracion-agencia-pdf', data)
    return response.data
  },
  async subirHeader(archivo: File) {
    const fd = new FormData()
    fd.append('imagen', archivo)
    const response = await httpClient.post('/configuracion-agencia-pdf/header', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    return response.data as { code: number; message: string; url: string }
  },
  async eliminarHeader() {
    const response = await httpClient.delete('/configuracion-agencia-pdf/header')
    return response.data
  },
  async subirFooter(archivo: File) {
    const fd = new FormData()
    fd.append('imagen', archivo)
    const response = await httpClient.post('/configuracion-agencia-pdf/footer', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    return response.data as { code: number; message: string; url: string }
  },
  async eliminarFooter() {
    const response = await httpClient.delete('/configuracion-agencia-pdf/footer')
    return response.data
  },
}

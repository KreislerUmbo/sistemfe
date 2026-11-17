// T-062 (CRM C6b) — «Reportes comerciales»: embudo, por origen, por vendedor, motivos y tiempo de
// respuesta (ReporteComercialController). Montos en texto con 2 decimales (numeric de Postgres).
import httpClient from '@/helpers/http-client'

export type FiltrosReporteComercial = {
  desde: string
  hasta: string
  /** id, 'sin' (sin vendedor) o '' (todos). */
  vendedor_id?: string
  /** id, 'sin' (sin origen) o '' (todos). */
  origen_contacto_id?: string
}

export type MontosPorMoneda = { PEN: string; USD: string }

export type ConteosComerciales = {
  consultas: number
  enviadas: number
  ganadas: number
  perdidas: number
  abiertas: number
  anuladas: number
  conversion_pct: number
  monto: MontosPorMoneda
}

export type FilaGrupoComercial = ConteosComerciales & { id: number | null; nombre: string; inactivo: boolean }

export type FilaMotivo = { id: number | null; nombre: string; cantidad: number; pct: number }

export type ReporteComercialDatos = {
  embudo: ConteosComerciales & { pct: Record<'enviadas' | 'ganadas' | 'perdidas' | 'abiertas' | 'anuladas', number> }
  por_origen: FilaGrupoComercial[]
  por_vendedor: FilaGrupoComercial[]
  motivos: { ganada: FilaMotivo[]; perdida: FilaMotivo[] }
  tiempo_respuesta: { mediana_horas: number | null; cotizaciones_con_envio: number }
}

export type OpcionFiltro = { id: number; nombre: string; inactivo: boolean }

const params = (f: FiltrosReporteComercial) => ({
  desde: f.desde,
  hasta: f.hasta,
  vendedor_id: f.vendedor_id || undefined,
  origen_contacto_id: f.origen_contacto_id || undefined,
})

export const reporteComercialService = {
  async obtener(filtros: FiltrosReporteComercial) {
    const response = await httpClient.get('/reportes-comerciales', { params: params(filtros) })
    return response.data.data as ReporteComercialDatos
  },

  async opciones() {
    const response = await httpClient.get('/reportes-comerciales/opciones')
    return response.data as { vendedores: OpcionFiltro[]; origenes: OpcionFiltro[] }
  },

  async exportarExcel(filtros: FiltrosReporteComercial) {
    const response = await httpClient.get('/reportes-comerciales/export', { params: params(filtros), responseType: 'blob' })
    return response.data as Blob
  },
}

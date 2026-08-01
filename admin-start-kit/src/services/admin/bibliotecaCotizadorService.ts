// src/services/admin/bibliotecaCotizadorService.ts — Sesión 11b3 (Parte A)
// Un único endpoint que decide contra qué tabla(s) consulta según `tipo` —
// no dos endpoints/modos de búsqueda distintos en el frontend, ver
// BibliotecaCotizadorController en el backend.
import httpClient from '@/helpers/http-client'
import type { BibliotecaResultado } from '@/types/agencia-viajes'

export type BibliotecaTipo = 'todos' | 'tour' | 'paquete' | 'proveedor'

export const bibliotecaCotizadorService = {
  async buscar(params: { tipo: BibliotecaTipo; proveedor_tipo_id?: number | null; search?: string }) {
    const response = await httpClient.get('/biblioteca-cotizador', { params })
    return response.data as { resultados: BibliotecaResultado[] }
  }
}

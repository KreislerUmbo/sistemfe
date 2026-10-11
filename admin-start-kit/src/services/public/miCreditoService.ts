// Módulo Créditos (Fase 5b) — "Mi crédito", la página pública del cliente. Cliente HTTP propio y
// sin sesión: no usa httpClient (token del personal) ni publicHttpClient (token y login del portal).
// El celular que acertó los 4 dígitos guarda una credencial firmada (90 días) en localStorage; si el
// navegador no lo permite (modo privado), queda en memoria solo mientras la pestaña esté abierta.
import axios from 'axios'
import { resolveApiBaseUrl } from '@/helpers/apiBaseUrl'
import type { MiCredito } from '@/types/creditos'

const cliente = axios.create({
  baseURL: resolveApiBaseUrl(),
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

const CABECERA = 'X-Mi-Credito'
const clave = (token: string) => `mi-credito:${token}`
const enMemoria = new Map<string, string>()

function leerCredencial(token: string): string | null {
  try {
    return localStorage.getItem(clave(token)) ?? enMemoria.get(token) ?? null
  } catch {
    return enMemoria.get(token) ?? null
  }
}

function guardarCredencial(token: string, credencial: string) {
  enMemoria.set(token, credencial)
  try {
    localStorage.setItem(clave(token), credencial)
  } catch {
    /* sin almacenamiento: vale para esta visita */
  }
}

export type ResultadoConsulta =
  | { tipo: 'datos'; datos: MiCredito }
  | { tipo: 'verificar' }
  | { tipo: 'invalido' }

export type ResultadoVerificacion =
  | { tipo: 'correcto' }
  | { tipo: 'incorrecto'; restantes: number }
  | { tipo: 'bloqueado' }
  | { tipo: 'invalido' }

const estadoDe = (e: unknown) => (e as { response?: { status?: number; data?: { intentos_restantes?: number } } }).response

export const miCreditoService = {
  /** Nombre y logo del negocio antes de verificar (endpoint público que ya usa el login). */
  async negocio(): Promise<{ nombre: string | null; logo: string | null }> {
    const { data } = await cliente.get('/branding')
    return { nombre: data.razon_social_comercial ?? null, logo: data.logo_horizontal ?? null }
  },

  async consultar(token: string): Promise<ResultadoConsulta> {
    const credencial = leerCredencial(token)
    if (!credencial) return { tipo: 'verificar' }
    try {
      const { data } = await cliente.get(`/mi-credito/${encodeURIComponent(token)}`, { headers: { [CABECERA]: credencial } })
      return { tipo: 'datos', datos: data as MiCredito }
    } catch (e) {
      const r = estadoDe(e)
      if (r?.status === 401) {
        this.olvidar(token)
        return { tipo: 'verificar' }
      }
      if (r?.status === 404) return { tipo: 'invalido' }
      throw e
    }
  },

  async verificar(token: string, ultimos4: string): Promise<ResultadoVerificacion> {
    try {
      const { data } = await cliente.post(`/mi-credito/${encodeURIComponent(token)}/verificar`, { ultimos4 })
      guardarCredencial(token, data.credencial)
      return { tipo: 'correcto' }
    } catch (e) {
      const r = estadoDe(e)
      if (r?.status === 422) return { tipo: 'incorrecto', restantes: r.data?.intentos_restantes ?? 0 }
      if (r?.status === 423 || r?.status === 429) return { tipo: 'bloqueado' }
      if (r?.status === 404) return { tipo: 'invalido' }
      throw e
    }
  },

  /** "Salir en este celular": la próxima vez vuelve a pedir el documento. */
  olvidar(token: string) {
    enMemoria.delete(token)
    try {
      localStorage.removeItem(clave(token))
    } catch {
      /* nada que borrar */
    }
  },
}

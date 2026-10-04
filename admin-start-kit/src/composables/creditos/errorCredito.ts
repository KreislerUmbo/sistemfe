// Módulo Créditos (04-frontend "Errores"): traduce un error de la API a algo que la
// pantalla sabe mostrar. Nunca expone detalles internos: solo el message que el backend
// ya redacta para el usuario, o un texto genérico.
import type { Infraccion } from '@/types/creditos'

export type TipoErrorCredito = 'red' | 'validacion' | 'limites' | 'conflicto' | 'permiso' | 'negocio' | 'desconocido'

export interface ErrorCredito {
  tipo: TipoErrorCredito
  mensaje: string
  /** Validación (422 de FormRequest): campo → primer mensaje. */
  campos: Record<string, string>
  /** Límites de otorgamiento que bloquean (422 de LimitesExcedidos). */
  bloqueos: Infraccion[]
  /** Se puede reintentar con la misma clave sin riesgo de duplicar. */
  reintentable: boolean
}

const MENSAJE_RED = 'No hay conexión con el servidor. Tus datos siguen en el formulario: revisa la conexión y toca "Reintentar".'
const MENSAJE_GENERICO = 'Ocurrió un error inesperado. Intenta de nuevo; si continúa, avisa al administrador.'

export const ETIQUETA_REGLA: Record<string, string> = {
  max_creditos: 'Tiene el máximo de créditos activos',
  deuda_maxima: 'Supera la deuda máxima permitida',
  moroso: 'Tiene cuotas atrasadas',
  bloqueado: 'Cliente bloqueado (manual o con crédito castigado)',
  propio_garante: 'El cliente no puede ser su propio garante',
  garante_moroso: 'El garante tiene cuotas atrasadas',
  garante_saturado: 'El garante ya respalda muchos créditos',
  ficha_incompleta: 'Ficha del cliente incompleta',
}

export function interpretarErrorCredito(error: unknown): ErrorCredito {
  const base: ErrorCredito = { tipo: 'desconocido', mensaje: MENSAJE_GENERICO, campos: {}, bloqueos: [], reintentable: false }
  const e = error as { response?: { status?: number; data?: Record<string, unknown> }; code?: string; name?: string }

  if (e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED') {
    return { ...base, tipo: 'desconocido', mensaje: '' }
  }
  if (!e?.response) {
    return { ...base, tipo: 'red', mensaje: MENSAJE_RED, reintentable: true }
  }

  const { status = 0, data = {} } = e.response
  const mensaje = typeof data.message === 'string' && data.message !== '' ? data.message : MENSAJE_GENERICO

  if (status === 422 && Array.isArray(data.bloqueos)) {
    return { ...base, tipo: 'limites', mensaje, bloqueos: data.bloqueos as Infraccion[] }
  }
  if (status === 422 && data.errors && typeof data.errors === 'object') {
    const campos: Record<string, string> = {}
    for (const [campo, mensajes] of Object.entries(data.errors as Record<string, string[]>)) {
      campos[campo] = Array.isArray(mensajes) ? mensajes[0] : String(mensajes)
    }
    return { ...base, tipo: 'validacion', mensaje: 'Revisa los datos marcados.', campos }
  }
  if (status === 409) {
    return { ...base, tipo: 'conflicto', mensaje: 'Esta operación ya fue registrada con otros datos. Vuelve a abrir la acción e intenta de nuevo.' }
  }
  if (status === 403) {
    return { ...base, tipo: 'permiso', mensaje }
  }
  if (status >= 500) {
    return { ...base, tipo: 'desconocido', mensaje: MENSAJE_GENERICO, reintentable: true }
  }

  return { ...base, tipo: 'negocio', mensaje }
}

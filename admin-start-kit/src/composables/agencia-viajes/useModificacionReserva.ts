// T-007 "Modificar reserva": estado y acciones de la diferencia de una modificación abierta.
// Lo que da el backend se muestra tal cual: acá no se calcula ningún importe ni saldo.
import { computed, ref, type Ref } from 'vue'
import {
  reservaModificacionService as api,
  type ComposicionPasajeros,
  type DiferenciaModificacion,
  type PenalidadPayload,
} from '@/services/admin/reservaModificacionService'

const ETIQUETAS_TIPO: Record<string, [string, string]> = {
  adulto: ['adulto', 'adultos'],
  nino: ['niño', 'niños'],
  infante: ['infante', 'infantes'],
  sin_tipo: ['pasajero sin tipo', 'pasajeros sin tipo'],
}

/** {"adulto":2,"nino":1} → "2 adultos y 1 niño". */
export function textoComposicion(comp: ComposicionPasajeros | null | undefined): string {
  const partes = Object.entries(comp ?? {})
    .filter(([, n]) => n > 0)
    .map(([tipo, n]) => {
      const [singular, plural] = ETIQUETAS_TIPO[tipo] ?? [tipo, tipo]
      return `${n} ${n === 1 ? singular : plural}`
    })
  if (partes.length === 0) return 'sin pasajeros'
  if (partes.length === 1) return partes[0]
  return `${partes.slice(0, -1).join(', ')} y ${partes[partes.length - 1]}`
}

/** Centavos enteros del backend → "PEN 1,234.50" (solo formato, sin aritmética con decimales). */
export function textoMonto(centavos: number, moneda: string): string {
  const signo = centavos < 0 ? '-' : ''
  const abs = Math.abs(centavos)
  const enteros = Math.trunc(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `${signo}${moneda} ${enteros}.${String(abs % 100).padStart(2, '0')}`
}

/** Centavos enteros → "12.50" para precargar un campo de monto (solo formato). */
export function centavosATexto(centavos: number): string {
  return `${Math.trunc(centavos / 100)}.${String(centavos % 100).padStart(2, '0')}`
}

const PREFIJO_MOTIVO = /^Penalidad por cancelación — [^:]*: /

/** El motivo se guarda dentro de descripcion_manual: se recorta el prefijo para editarlo aparte. */
export function motivoDePenalidad(descripcionManual: string | null | undefined): string {
  return (descripcionManual ?? '').replace(PREFIJO_MOTIVO, '')
}

/** Los errores de un blob (PDF) llegan como Blob JSON: se lee el mensaje real. */
export async function mensajeDeError(error: any, fallback: string): Promise<string> {
  const data = error?.response?.data
  if (data instanceof Blob && data.type === 'application/json') {
    try {
      return JSON.parse(await data.text()).message ?? fallback
    } catch {
      return fallback
    }
  }
  return data?.message ?? fallback
}

export function useModificacionReserva(reservaId: Ref<number | null>) {
  const diferencia = ref<DiferenciaModificacion | null>(null)
  const cargando = ref(false)
  const error = ref<string | null>(null)
  // La huella de la última diferencia que VIO el usuario: es la que se manda al aplicar.
  const huellaMostrada = computed(() => diferencia.value?.huella ?? null)

  const cargar = async () => {
    if (!reservaId.value) return
    cargando.value = true
    try {
      diferencia.value = await api.diferencia(reservaId.value)
      error.value = null
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'No se pudo calcular la diferencia'
    } finally {
      cargando.value = false
    }
  }

  // Confirmar varias líneas "para revisar" a la vez: en paralelo, y al final se vuelve a
  // pedir la diferencia (cada respuesta individual es parcial respecto de las demás).
  const confirmarRevisados = async (itemIds: number[]) => {
    if (!reservaId.value || itemIds.length === 0) return
    const id = reservaId.value
    await Promise.all(itemIds.map((itemId) => api.marcarRevisado(id, itemId)))
    await cargar()
  }

  const guardarPenalidad = async (reservaPasajeroId: number, payload: PenalidadPayload, itemId?: number | null) => {
    const id = reservaId.value!
    diferencia.value = itemId
      ? await api.actualizarPenalidad(id, itemId, payload)
      : await api.crearPenalidad(id, reservaPasajeroId, payload)
  }

  const borrarPenalidad = async (itemId: number) => {
    diferencia.value = await api.borrarPenalidad(reservaId.value!, itemId)
  }

  const aplicar = async () => {
    if (!reservaId.value || !huellaMostrada.value) throw new Error('Sin diferencia que aplicar')
    return api.aplicar(reservaId.value, huellaMostrada.value)
  }

  return { diferencia, cargando, error, huellaMostrada, cargar, confirmarRevisados, guardarPenalidad, borrarPenalidad, aplicar }
}

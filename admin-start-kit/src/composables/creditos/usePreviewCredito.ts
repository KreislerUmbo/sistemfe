// Módulo Créditos (04-frontend): el resumen y el cronograma del formulario vienen de
// POST creditos/preview, nunca de un cálculo local. Debounce de ~300 ms; si llega una
// respuesta vieja después de una nueva (red lenta), se descarta.
import { onBeforeUnmount, ref, watch, type Ref } from 'vue'
import { creditoService } from '@/services/admin/creditoService'
import type { CondicionesCredito, PreviewCredito } from '@/types/creditos'
import { interpretarErrorCredito, type ErrorCredito } from './errorCredito'

export const DEBOUNCE_PREVIEW_MS = 300

type Fetcher<T, C> = (c: C, signal: AbortSignal) => Promise<T>

/**
 * @param condiciones null mientras el formulario no esté completo (cualquier solicitud plana)
 * @param fetcher por defecto POST creditos/preview; la renovación y el cobro usan el suyo
 */
export function usePreviewCredito<T = PreviewCredito, C extends object = CondicionesCredito>(
  condiciones: Ref<C | null>,
  fetcher: Fetcher<T, C> = creditoService.preview as unknown as Fetcher<T, C>,
) {
  const preview = ref<T | null>(null) as Ref<T | null>
  const cargando = ref(false)
  const error = ref<ErrorCredito | null>(null)

  let temporizador: ReturnType<typeof setTimeout> | null = null
  let controlador: AbortController | null = null
  let ultimaSolicitud = 0

  const pedir = async (datos: C) => {
    controlador?.abort()
    controlador = new AbortController()
    const numero = ++ultimaSolicitud
    cargando.value = true
    try {
      const respuesta = await fetcher(datos, controlador.signal)
      if (numero !== ultimaSolicitud) return
      preview.value = respuesta
      error.value = null
    } catch (e) {
      if (numero !== ultimaSolicitud) return
      const interpretado = interpretarErrorCredito(e)
      if (interpretado.mensaje !== '') {
        error.value = interpretado
        preview.value = null
      }
    } finally {
      if (numero === ultimaSolicitud) cargando.value = false
    }
  }

  watch(
    condiciones,
    (datos) => {
      if (temporizador) clearTimeout(temporizador)
      if (!datos) {
        ultimaSolicitud++
        controlador?.abort()
        preview.value = null
        error.value = null
        cargando.value = false
        return
      }
      temporizador = setTimeout(() => pedir({ ...datos }), DEBOUNCE_PREVIEW_MS)
    },
    { deep: true, immediate: true },
  )

  onBeforeUnmount(() => {
    if (temporizador) clearTimeout(temporizador)
    controlador?.abort()
  })

  return { preview, cargando, error }
}

// Módulo Créditos (04-frontend): el resumen y el cronograma del formulario vienen de
// POST creditos/preview, nunca de un cálculo local. Debounce de ~300 ms; si llega una
// respuesta vieja después de una nueva (red lenta), se descarta.
import { onBeforeUnmount, ref, watch, type Ref } from 'vue'
import { creditoService } from '@/services/admin/creditoService'
import type { CondicionesCredito, PreviewCredito } from '@/types/creditos'
import { interpretarErrorCredito, type ErrorCredito } from './errorCredito'

export const DEBOUNCE_PREVIEW_MS = 300

type Fetcher = (c: CondicionesCredito, signal: AbortSignal) => Promise<PreviewCredito>

/** @param condiciones null mientras el formulario no esté completo */
export function usePreviewCredito(condiciones: Ref<CondicionesCredito | null>, fetcher: Fetcher = creditoService.preview) {
  const preview = ref<PreviewCredito | null>(null)
  const cargando = ref(false)
  const error = ref<ErrorCredito | null>(null)

  let temporizador: ReturnType<typeof setTimeout> | null = null
  let controlador: AbortController | null = null
  let ultimaSolicitud = 0

  const pedir = async (datos: CondicionesCredito) => {
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

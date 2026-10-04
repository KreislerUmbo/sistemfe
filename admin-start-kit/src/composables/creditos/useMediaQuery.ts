// Módulo Créditos: media query reactiva, para montar UN solo componente según el ancho
// (ocultarlo con d-none lo deja montado: doble carga de datos e ids repetidos).
import { onBeforeUnmount, ref } from 'vue'

export function useMediaQuery(consulta: string) {
  const lista = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(consulta) : null
  const coincide = ref(lista?.matches ?? false)
  const alCambiar = (e: MediaQueryListEvent) => {
    coincide.value = e.matches
  }
  lista?.addEventListener('change', alCambiar)
  onBeforeUnmount(() => lista?.removeEventListener('change', alCambiar))

  return coincide
}

// Módulo Créditos (04-frontend "Idempotencia"): una clave estable por intento. Se genera
// al abrir la acción, se conserva en los reintentos (un reintento tras un corte de red no
// duplica la operación) y se renueva solo después de un éxito.
import { ref } from 'vue'

function nuevaClave(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  // Respaldo para navegadores sin randomUUID (http no seguro en algunos Android viejos).
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

export function useClaveIdempotencia() {
  const clave = ref(nuevaClave())

  /** Llamar solo cuando la operación terminó bien: la siguiente será otra operación. */
  const renovar = () => {
    clave.value = nuevaClave()
  }

  return { clave, renovar }
}

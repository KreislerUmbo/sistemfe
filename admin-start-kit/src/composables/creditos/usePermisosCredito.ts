// Módulo Créditos: acceso a los permisos del usuario con el mismo criterio que el guard
// de rutas (Super-Admin pasa todo; "a|b" = cualquiera de los dos).
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

export function usePermisosCredito() {
  const auth = useAuthStore()

  const puede = (permiso: string): boolean => auth.isPermitedRoute(permiso)
  const usuarioId = computed<number>(() => Number(auth.user?.id ?? 0))

  return { puede, usuarioId }
}

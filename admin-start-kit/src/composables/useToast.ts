// src/composables/useToast.ts — Sesión 11c (reserva y pasajeros)
//
// Toast reutilizable pedido para las pantallas de reserva ("no alert()") —
// no existía ningún componente de toast en el proyecto (confirmado por
// grep, el resto del sistema usa Swal.fire modal para todo, éxito y
// error). Se envuelve el propio SweetAlert2 ya instalado en modo "toast"
// (esquina, auto-dismiss) en vez de traer una librería nueva.
import Swal from 'sweetalert2/dist/sweetalert2.js'

type TVueSwalInstance = typeof Swal & typeof Swal.fire

const toastBase = (Swal as TVueSwalInstance).mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 2800,
  timerProgressBar: true,
})

export function useToast() {
  const success = (message: string) => toastBase.fire({ icon: 'success', title: message })
  const warning = (message: string) => toastBase.fire({ icon: 'warning', title: message })
  const error = (message: string) => toastBase.fire({ icon: 'error', title: message })

  return { success, warning, error }
}

// "Avisa a los proveedores" (D15, T-040): qué cambió en cada salida operativa
// después de una acción sobre la reserva (cancelar, reprogramar, quitar un
// servicio, cambiar su fecha, «Modificar reserva»). Sin cambios, sin cuadro.
// La misma instancia que useToast(): con la de 'sweetalert2' a secas, el
// temporizador del toast de éxito cerraba este cuadro (comparten el contenedor).
import Swal from 'sweetalert2/dist/sweetalert2.js'

type TVueSwalInstance = typeof Swal & typeof Swal.fire

export type AvisoSalida = { salida_id?: number; tour: string; fecha: string; avisos: string[] }

const escapar = (t: string) =>
  t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)

export const htmlAvisosProveedores = (salidas: AvisoSalida[]): string =>
  '<div class="text-start small">'
  + salidas.map((s) => `<strong>${escapar(s.tour)} · ${escapar(s.fecha)}</strong><ul class="mt-1">`
    + s.avisos.map((a) => `<li>${escapar(a)}</li>`).join('') + '</ul>').join('')
  + '</div>'

export const avisarProveedores = async (salidas?: AvisoSalida[] | null): Promise<void> => {
  const conAvisos = (salidas ?? []).filter((s) => s.avisos.length > 0)
  if (conAvisos.length === 0) return
  await (Swal as TVueSwalInstance).fire({ title: 'Avisa a los proveedores', icon: 'info', html: htmlAvisosProveedores(conAvisos) })
}

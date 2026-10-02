// Alta/edición de clientes (Fase 4c, todos los giros). El backend responde HTTP 200 con un
// "code" de negocio; este helper resuelve con el usuario los dos casos que no son error:
//  - 409: cliente sin documento con un nombre ya registrado → confirmar y reenviar.
//  - 410: el documento es de un cliente eliminado → ofrecer restaurarlo.
// Cualquier otro code (405 = documento repetido, etc.) se muestra como aviso.
import Swal from 'sweetalert2'
import httpClient from '@/helpers/http-client'
import { accionSegunRespuesta, type RespuestaCliente } from './respuestaCliente'

export type ResultadoGuardar<C> = { ok: true; client: C; restaurado: boolean } | { ok: false }

export async function guardarCliente<C = any>(payload: Record<string, unknown>, clienteId?: number | null): Promise<ResultadoGuardar<C>> {
  const enviar = (datos: Record<string, unknown>) => (clienteId
    ? httpClient.put(`clients/${clienteId}`, datos)
    : httpClient.post('clients', datos))

  let { data } = await enviar(payload) as { data: RespuestaCliente<C> }
  let accion = accionSegunRespuesta(data)

  if (accion === 'confirmar_nombre') {
    const r = await Swal.fire({
      icon: 'question', title: 'Nombre ya registrado', text: data.message,
      showCancelButton: true, confirmButtonText: 'Sí, es otra persona', cancelButtonText: 'Revisar',
    })
    if (!r.isConfirmed) return { ok: false }
    data = (await enviar({ ...payload, confirmar_nombre_repetido: true })).data
    accion = accionSegunRespuesta(data)
  }

  if (accion === 'restaurar') {
    const r = await Swal.fire({
      icon: 'info', title: 'Cliente eliminado', text: data.message,
      showCancelButton: true, confirmButtonText: 'Restaurar cliente', cancelButtonText: 'Cancelar',
    })
    if (!r.isConfirmed) return { ok: false }
    const restaurado = (await httpClient.post(`clients/${data.cliente_eliminado!.id}/restaurar`)).data as RespuestaCliente<C>
    if (restaurado.code === 200 && restaurado.client) return { ok: true, client: restaurado.client, restaurado: true }
    await Swal.fire('Atención', restaurado.message, 'warning')
    return { ok: false }
  }

  if (accion === 'ok' && data.client) return { ok: true, client: data.client, restaurado: false }
  await Swal.fire('Atención', data.message, 'warning')
  return { ok: false }
}

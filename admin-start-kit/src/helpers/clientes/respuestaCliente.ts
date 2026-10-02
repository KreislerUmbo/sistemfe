// Respuesta de POST/PUT clients (Fase 4c): HTTP 200 con un "code" de negocio.
export interface RespuestaCliente<C = any> {
  code: number
  message: string
  client?: C
  requiere_confirmacion?: boolean
  cliente_eliminado?: { id: number; full_name: string }
  cliente_existente?: { id: number; full_name: string } | null
}

/**
 * 200 = guardado; 409 = sin documento con nombre repetido (confirmar y reenviar);
 * 410 = documento de un cliente eliminado (ofrecer restaurarlo); lo demás es un aviso
 * (405 = documento ya registrado).
 */
export function accionSegunRespuesta(r: RespuestaCliente): 'ok' | 'confirmar_nombre' | 'restaurar' | 'aviso' {
  if (r.code === 200) return 'ok'
  if (r.code === 409 && r.requiere_confirmacion) return 'confirmar_nombre'
  if (r.code === 410 && r.cliente_eliminado) return 'restaurar'
  return 'aviso'
}

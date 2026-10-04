import { describe, expect, it } from 'vitest'
import { accionSegunRespuesta } from './respuestaCliente'

describe('accionSegunRespuesta', () => {
  it('distingue guardado, confirmar nombre, restaurar y aviso', () => {
    expect(accionSegunRespuesta({ code: 200, message: 'ok' })).toBe('ok')
    expect(accionSegunRespuesta({ code: 409, message: 'x', requiere_confirmacion: true })).toBe('confirmar_nombre')
    expect(accionSegunRespuesta({ code: 410, message: 'x', cliente_eliminado: { id: 3, full_name: 'Ana' } })).toBe('restaurar')
    expect(accionSegunRespuesta({ code: 405, message: 'Ya existe' })).toBe('aviso')
    // Un 409/410 sin los datos esperados no se trata como confirmable.
    expect(accionSegunRespuesta({ code: 409, message: 'x' })).toBe('aviso')
    expect(accionSegunRespuesta({ code: 410, message: 'x' })).toBe('aviso')
  })
})

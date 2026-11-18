import { afterEach, describe, expect, it, vi } from 'vitest'
import { generarUuidGrupo } from './grupoOpcion'

const FORMA_UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('generarUuidGrupo', () => {
  afterEach(() => { vi.unstubAllGlobals() })

  it('usa crypto.randomUUID cuando existe', () => {
    vi.stubGlobal('crypto', { randomUUID: () => 'id-del-navegador' })
    expect(generarUuidGrupo()).toBe('id-del-navegador')
  })

  it('sin contexto seguro (sin randomUUID) arma un id con forma de UUID v4, distinto cada vez', () => {
    vi.stubGlobal('crypto', {})
    const a = generarUuidGrupo()
    const b = generarUuidGrupo()
    expect(a).toMatch(FORMA_UUID_V4)
    expect(b).toMatch(FORMA_UUID_V4)
    expect(a).not.toBe(b)
  })
})

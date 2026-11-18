import { describe, expect, it } from 'vitest'
import { createApp, ref } from 'vue'
import { CLAVE_PAGINA, CLAVE_PRECIOS, inyectar } from './claves'

// runWithContext da a inject() el contexto de una app, sin montar ningún componente.
describe('inyectar', () => {
  it('devuelve el objeto publicado con provide', () => {
    const app = createApp({})
    const precios = { totalLocal: ref(10) } as any
    app.provide(CLAVE_PRECIOS, precios)

    expect(app.runWithContext(() => inyectar(CLAVE_PRECIOS))).toBe(precios)
  })

  it('lanza un error que nombra la clave si falta el provide', () => {
    const app = createApp({})

    expect(() => app.runWithContext(() => inyectar(CLAVE_PAGINA)))
      .toThrowError('pagina usado fuera de la pantalla del cotizador')
  })
})

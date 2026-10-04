import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { formatoCentavos, formatoFecha, formatoSoles, hoyEnLima } from '@/helpers/creditos/formato'
import type { CondicionesCredito, PreviewCredito } from '@/types/creditos'
import { accionesDisponibles, puedeAnularPago } from './accionesCredito'
import { interpretarErrorCredito } from './errorCredito'
import { useClaveIdempotencia } from './useClaveIdempotencia'
import { DEBOUNCE_PREVIEW_MS, usePreviewCredito } from './usePreviewCredito'

// El servicio real arma httpClient (usa window); los tests inyectan su propio fetcher.
vi.mock('@/services/admin/creditoService', () => ({ creditoService: { preview: vi.fn() } }))

// Intl de Node usa espacio duro entre "S/" y el número; se normaliza para comparar.
const sinEspaciosDuros = (texto: string) => texto.replace(/\s/g, ' ')

describe('formato', () => {
  it('formatea soles sin aritmética propia', () => {
    expect(sinEspaciosDuros(formatoSoles('1234.5'))).toBe('S/ 1,234.50')
    expect(sinEspaciosDuros(formatoSoles('0.10'))).toBe('S/ 0.10')
    expect(sinEspaciosDuros(formatoSoles(null))).toBe('S/ 0.00')
    expect(sinEspaciosDuros(formatoSoles('no-es-numero'))).toBe('S/ 0.00')
  })

  it('formatea centavos enteros sin dividir en coma flotante', () => {
    expect(sinEspaciosDuros(formatoCentavos(550_000))).toBe('S/ 5,500.00')
    expect(sinEspaciosDuros(formatoCentavos(7))).toBe('S/ 0.07')
    expect(sinEspaciosDuros(formatoCentavos(null))).toBe('S/ 0.00')
  })

  it('formatea fechas sin pasar por Date (sin corrimiento de zona)', () => {
    expect(formatoFecha('2026-10-01')).toBe('01/10/2026')
    expect(formatoFecha('2026-10-01 23:59:00')).toBe('01/10/2026')
    expect(formatoFecha(null)).toBe('')
  })

  it('hoy en Lima aunque en UTC ya sea el día siguiente', () => {
    // 30/09 22:00 en Lima = 01/10 03:00 UTC.
    expect(hoyEnLima(new Date('2026-10-01T03:00:00Z'))).toBe('2026-09-30')
  })
})

describe('useClaveIdempotencia', () => {
  it('conserva la clave en reintentos y la renueva solo tras un éxito', () => {
    const { clave, renovar } = useClaveIdempotencia()
    const primera = clave.value

    expect(primera).toMatch(/^[0-9a-f-]{36}$/)
    expect(clave.value).toBe(primera)   // un reintento usa la misma
    renovar()
    expect(clave.value).not.toBe(primera)
  })
})

describe('interpretarErrorCredito', () => {
  const respuesta = (status: number, data: Record<string, unknown>) => ({ response: { status, data } })

  it('sin respuesta es un error de red reintentable', () => {
    const e = interpretarErrorCredito({ message: 'Network Error' })
    expect(e.tipo).toBe('red')
    expect(e.reintentable).toBe(true)
  })

  it('422 de límites trae las infracciones', () => {
    const e = interpretarErrorCredito(respuesta(422, {
      message: 'Excede límites', bloqueos: [{ regla: 'moroso', autorizable: true, detalle: { dias_atraso: 9 } }],
    }))
    expect(e.tipo).toBe('limites')
    expect(e.bloqueos[0].regla).toBe('moroso')
  })

  it('422 de validación trae el primer mensaje por campo', () => {
    const e = interpretarErrorCredito(respuesta(422, { message: 'x', errors: { monto_capital: ['Debe ser múltiplo de S/ 0.10.', 'otro'] } }))
    expect(e.tipo).toBe('validacion')
    expect(e.campos.monto_capital).toBe('Debe ser múltiplo de S/ 0.10.')
  })

  it('409 es conflicto de clave y 403 conserva el mensaje del backend', () => {
    expect(interpretarErrorCredito(respuesta(409, { message: 'clave usada' })).tipo).toBe('conflicto')
    const permiso = interpretarErrorCredito(respuesta(403, { message: 'Requiere creditos.anular_pago' }))
    expect(permiso.tipo).toBe('permiso')
    expect(permiso.mensaje).toBe('Requiere creditos.anular_pago')
  })

  it('500 no muestra datos internos', () => {
    const e = interpretarErrorCredito(respuesta(500, { message: 'SQLSTATE[23505] duplicate key', trace: [] }))
    expect(e.mensaje).not.toContain('SQLSTATE')
  })
})

describe('accionesDisponibles', () => {
  const todo = () => true

  it('borrador: editar, activar y anular', () => {
    expect(accionesDisponibles('borrador', todo, false)).toEqual(['editar', 'activar', 'anular'])
  })

  it('un crédito sin mora no ofrece condonar mora', () => {
    expect(accionesDisponibles('activo', todo, false)).toContain('condonar')
    expect(accionesDisponibles('activo', todo, false, false)).not.toContain('condonar')
  })

  it('con reprogramaciones, condonaciones o cargos no ofrece corregir, pero sí anular', () => {
    const acciones = accionesDisponibles('activo', todo, false, true, true)
    expect(acciones).not.toContain('corregir')
    expect(acciones).toContain('anular')
  })

  it('activo con pagos no ofrece corregir ni anular', () => {
    const acciones = accionesDisponibles('activo', todo, true)
    expect(acciones).toContain('cobrar')
    expect(acciones).not.toContain('corregir')
    expect(acciones).not.toContain('anular')
  })

  it('sin permisos no ofrece acciones; el cobrador solo cobra y liquida', () => {
    expect(accionesDisponibles('activo', () => false, false)).toEqual([])
    expect(accionesDisponibles('activo', (p) => p === 'creditos.cobrar', false)).toEqual(['cobrar', 'liquidar'])
  })

  it('castigado ofrece revertir y no castigar; finalizado no ofrece nada', () => {
    const acciones = accionesDisponibles('castigado', todo, true)
    expect(acciones).toContain('revertir_castigo')
    expect(acciones).not.toContain('castigar')
    expect(accionesDisponibles('finalizado', todo, true)).toEqual([])
  })

  it('anular pago: propio o con creditos.anular_pago', () => {
    const cajero = (p: string) => p === 'creditos.cobrar'
    expect(puedeAnularPago(7, 7, cajero)).toBe(true)
    expect(puedeAnularPago(8, 7, cajero)).toBe(false)
    expect(puedeAnularPago(8, 7, (p) => p === 'creditos.anular_pago')).toBe(true)
  })
})

describe('usePreviewCredito', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  const condiciones = (monto: string): CondicionesCredito => ({
    cliente_id: 1, monto_capital: monto, tasa_interes: '20', unidad_tasa: 'total', frecuencia_unidad: 'dia',
    frecuencia_intervalo: 1, numero_cuotas: 30, fecha_desembolso: '2026-10-01',
  })
  const previewDe = (monto: string) => ({ cronograma: { monto_total: monto } }) as unknown as PreviewCredito

  it('espera el debounce y descarta una respuesta vieja que llega tarde', async () => {
    const resolvers: Record<string, (p: PreviewCredito) => void> = {}
    const fetcher = vi.fn((c: CondicionesCredito) => new Promise<PreviewCredito>((r) => { resolvers[c.monto_capital] = r }))
    const datos = ref<CondicionesCredito | null>(condiciones('1000'))
    const scope = effectScope()
    const { preview } = scope.run(() => usePreviewCredito(datos, fetcher))!

    await vi.advanceTimersByTimeAsync(DEBOUNCE_PREVIEW_MS)
    expect(fetcher).toHaveBeenCalledTimes(1)

    datos.value = condiciones('2000')
    await nextTick()
    await vi.advanceTimersByTimeAsync(DEBOUNCE_PREVIEW_MS)
    expect(fetcher).toHaveBeenCalledTimes(2)

    resolvers['2000'](previewDe('2400'))
    await vi.runAllTimersAsync()
    resolvers['1000'](previewDe('1200'))   // la vieja llega después
    await vi.runAllTimersAsync()

    expect(preview.value?.cronograma.monto_total).toBe('2400')
    scope.stop()
  })

  it('no pide nada mientras el formulario esté incompleto', async () => {
    const fetcher = vi.fn()
    const scope = effectScope()
    scope.run(() => usePreviewCredito(ref(null), fetcher))
    await vi.advanceTimersByTimeAsync(DEBOUNCE_PREVIEW_MS * 2)

    expect(fetcher).not.toHaveBeenCalled()
    scope.stop()
  })
})

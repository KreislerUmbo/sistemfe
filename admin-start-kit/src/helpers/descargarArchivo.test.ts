import { afterEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@/helpers/http-client', () => ({ default: { get: (...a: unknown[]) => get(...a) } }))

import { descargarArchivo, leerMensajeErrorBlob, nombreDeContentDisposition } from './descargarArchivo'

describe('nombreDeContentDisposition', () => {
  it('prefiere filename* en UTF-8', () => {
    expect(nombreDeContentDisposition(`attachment; filename="x.pdf"; filename*=UTF-8''CDKM-1 - Ru%C3%ADz - Base.pdf`)).toBe('CDKM-1 - Ruíz - Base.pdf')
  })
  it('cae a filename simple, con o sin comillas', () => {
    expect(nombreDeContentDisposition('attachment; filename="cotizacion.pdf"')).toBe('cotizacion.pdf')
    expect(nombreDeContentDisposition('attachment; filename=cotizacion.pdf')).toBe('cotizacion.pdf')
  })
  it('UTF-8 mal codificado cae al simple', () => {
    expect(nombreDeContentDisposition(`attachment; filename*=UTF-8''%E0%A4%A; filename="b.pdf"`)).toBe('b.pdf')
  })
  it('sin cabecera o sin nombre: null', () => {
    expect(nombreDeContentDisposition(undefined)).toBeNull()
    expect(nombreDeContentDisposition('inline')).toBeNull()
  })
})

describe('leerMensajeErrorBlob', () => {
  const blob = (obj: unknown) => new Blob([JSON.stringify(obj)], { type: 'application/json' })
  it('blob JSON: el primer error de validación antes que message', async () => {
    const error = { response: { data: blob({ message: 'Datos inválidos', errors: { fecha: ['Falta la fecha'] } }) } }
    expect(await leerMensajeErrorBlob(error, 'genérico')).toBe('Falta la fecha')
  })
  it('blob JSON sin errors: message', async () => {
    expect(await leerMensajeErrorBlob({ response: { data: blob({ message: 'No existe' }) } }, 'genérico')).toBe('No existe')
  })
  it('blob ilegible: el fallback', async () => {
    const roto = new Blob(['{no json'], { type: 'application/json' })
    expect(await leerMensajeErrorBlob({ response: { data: roto } }, 'genérico')).toBe('genérico')
  })
  it('respuesta JSON normal o sin respuesta', async () => {
    expect(await leerMensajeErrorBlob({ response: { data: { message: 'Hola' } } }, 'genérico')).toBe('Hola')
    expect(await leerMensajeErrorBlob(new Error('red'), 'genérico')).toBe('genérico')
  })
})

describe('descargarArchivo', () => {
  afterEach(() => { vi.unstubAllGlobals(); get.mockReset() })

  it('descarga con el nombre que manda el servidor, o el sugerido', async () => {
    const click = vi.fn()
    const atributos: Record<string, string> = {}
    const link = { href: '', click, remove: vi.fn(), setAttribute: (k: string, v: string) => { atributos[k] = v } }
    vi.stubGlobal('document', { createElement: () => link, body: { appendChild: vi.fn() } })
    vi.stubGlobal('window', { URL: { createObjectURL: () => 'blob:1', revokeObjectURL: vi.fn() } })

    get.mockResolvedValueOnce({ data: 'pdf', headers: { 'content-disposition': 'attachment; filename="servidor.pdf"' } })
    await descargarArchivo('/alternativas/1/pdf', 'sugerido.pdf')
    expect(get).toHaveBeenCalledWith('/alternativas/1/pdf', { responseType: 'blob' })
    expect(atributos.download).toBe('servidor.pdf')
    expect(click).toHaveBeenCalledOnce()

    get.mockResolvedValueOnce({ data: 'pdf', headers: {} })
    await descargarArchivo('/alternativas/1/pdf', 'sugerido.pdf')
    expect(atributos.download).toBe('sugerido.pdf')
  })
})

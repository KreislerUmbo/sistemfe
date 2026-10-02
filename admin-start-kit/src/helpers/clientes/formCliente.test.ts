import { describe, expect, it } from 'vitest'
import { aPayload, erroresDeValidacion, formDesdeCliente, formVacio, nombreCompleto, ubigeoDesdeTexto } from './formCliente'

describe('formulario de cliente', () => {
  it('DNI: nombre completo = nombres + apellidos, sin datos tributarios', () => {
    const f = { ...formVacio(), n_document: ' 45678912 ', name: ' Ana ', surname: 'Torres  ', gender: 'F' as const }
    const p = aPayload(f, { tributarios: true })

    expect(p.full_name).toBe('Ana Torres')
    expect(p.n_document).toBe('45678912')
    expect(p.cod_tipo_doc_sunat).toBe('1')
    expect(p.gender).toBe('F')
    expect(p).not.toHaveProperty('regimen_tributario')
  })

  it('RUC: razón social, nombre comercial y tributarios solo si la pantalla los muestra', () => {
    const f = { ...formVacio(), type_document: 'RUC' as const, n_document: '20123456789', full_name: 'Empresa SAC', name_comerc: 'Tienda', regimen_tributario: 'MYPE', es_agente_retencion: true, gender: 'M' as const }

    expect(aPayload(f, { tributarios: true })).toMatchObject({ full_name: 'Empresa SAC', name_comerc: 'Tienda', regimen_tributario: 'MYPE', es_agente_retencion: true, gender: null, cod_tipo_doc_sunat: '6' })
    expect(aPayload(f, { tributarios: false })).not.toHaveProperty('regimen_tributario')
  })

  it('sin documento: número nulo y la referencia como nombre', () => {
    const p = aPayload({ ...formVacio(), type_document: 'SND', n_document: '00000000', full_name: 'Manuel recomienda' }, { tributarios: false })
    expect(p).toMatchObject({ n_document: null, full_name: 'Manuel recomienda', name: null, cod_tipo_doc_sunat: '0' })
  })

  it('ubigeo: nombres desde los ids y Amazonía desde regiones.json', () => {
    const p = aPayload({ ...formVacio(), ubigeo_region: '22', ubigeo_provincia: '2209', ubigeo_distrito: '220901' }, { tributarios: false })
    expect(p.region).toBe('San Martín')
    expect(p.es_amazonia).toBe(true)
    expect(p.distrito).toBe('Tarapoto')
  })

  it('ubigeo desde los textos de la búsqueda de RUC, sin importar tildes ni mayúsculas', () => {
    expect(ubigeoDesdeTexto('SAN MARTIN', 'SAN MARTIN', 'TARAPOTO')).toEqual({ ubigeo_region: '22', ubigeo_provincia: '2209', ubigeo_distrito: '220901' })
    expect(ubigeoDesdeTexto('Narnia')).toEqual({ ubigeo_region: '', ubigeo_provincia: '', ubigeo_distrito: '' })
  })

  it('vuelve al formulario desde un cliente guardado', () => {
    const f = formDesdeCliente({ type_document: 'SND', n_document: '00000000', full_name: 'Ref', state: 2, gender: 'X' })
    expect(f).toMatchObject({ type_document: 'SND', n_document: '', state: 2, gender: '' })
    expect(nombreCompleto(f)).toBe('Ref')
  })

  it('lee los errores de un 422 de Laravel', () => {
    expect(erroresDeValidacion({ response: { status: 422, data: { errors: { n_document: ['El DNI debe tener 8 dígitos.', 'otro'] } } } }))
      .toEqual({ n_document: 'El DNI debe tener 8 dígitos.' })
    expect(erroresDeValidacion(new Error('red'))).toEqual({})
  })
})

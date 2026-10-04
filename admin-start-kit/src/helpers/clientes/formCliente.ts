// Formulario de cliente (Fase 4c, todos los giros): lo comparten la página del cliente y el
// modal rápido de Ventas/Cotizador/Nuevo crédito. Funciones puras: armar el formulario desde
// un cliente, armar el cuerpo para la API y resolver el ubigeo (regiones.json manda sobre
// Amazonía, Ley 27037).
import type { Client } from '@/types/clients'
import REGIONES from '@/views/clients/json/regiones.json'
import PROVINCIAS from '@/views/clients/json/provincias.json'
import DISTRITOS from '@/views/clients/json/distritos.json'

export type TipoDocumento = 'DNI' | 'RUC' | 'CE' | 'PAS' | 'TM' | 'SND'

export const TIPOS_DOCUMENTO: { valor: TipoDocumento; texto: string; sunat: string; buscable: boolean }[] = [
  { valor: 'DNI', texto: 'DNI', sunat: '1', buscable: true },
  { valor: 'RUC', texto: 'RUC', sunat: '6', buscable: true },
  { valor: 'CE', texto: 'Carné de extranjería', sunat: '4', buscable: false },
  { valor: 'PAS', texto: 'Pasaporte', sunat: '7', buscable: false },
  { valor: 'TM', texto: 'Tarjeta militar', sunat: 'A', buscable: false },
  { valor: 'SND', texto: 'Sin documento', sunat: '0', buscable: false },
]

export const REGIMENES = [
  { valor: 'SIN REGIMEN', texto: 'Sin régimen' },
  { valor: 'NRUS', texto: 'NRUS' },
  { valor: 'RER', texto: 'RER' },
  { valor: 'MYPE', texto: 'MYPE' },
  { valor: 'GENERAL', texto: 'General' },
]

export interface FormCliente {
  type_client: string
  type_document: TipoDocumento
  n_document: string
  name: string
  surname: string
  /** Razón social (RUC) o nombre/referencia (sin documento). */
  full_name: string
  name_comerc: string
  email: string
  phone: string
  birth_date: string
  gender: '' | 'M' | 'F'
  state: number
  address: string
  ubigeo_region: string
  ubigeo_provincia: string
  ubigeo_distrito: string
  regimen_tributario: string
  es_agente_retencion: boolean
}

interface Ubigeo { id: string; name: string; department_id?: string; province_id?: string; es_amazonia?: boolean }
const regiones = REGIONES as Ubigeo[]
const provincias = PROVINCIAS as Ubigeo[]
const distritos = DISTRITOS as Ubigeo[]

export const listaRegiones = (): Ubigeo[] => regiones
export const provinciasDe = (regionId: string): Ubigeo[] => provincias.filter((p) => p.department_id === regionId)
export const distritosDe = (provinciaId: string): Ubigeo[] => distritos.filter((d) => d.province_id === provinciaId)
export const esAmazonia = (regionId: string): boolean => regiones.find((r) => r.id === regionId)?.es_amazonia ?? false

export function formVacio(): FormCliente {
  return {
    type_client: '1', type_document: 'DNI', n_document: '', name: '', surname: '', full_name: '', name_comerc: '',
    email: '', phone: '', birth_date: '', gender: '', state: 1, address: '',
    ubigeo_region: '', ubigeo_provincia: '', ubigeo_distrito: '', regimen_tributario: 'SIN REGIMEN', es_agente_retencion: false,
  }
}

const TIPOS = new Set(TIPOS_DOCUMENTO.map((t) => t.valor))

export function formDesdeCliente(c: Partial<Client> & { regimen_tributario?: string; es_agente_retencion?: boolean }): FormCliente {
  const tipo = (c.type_document ?? 'DNI') as TipoDocumento
  return {
    ...formVacio(),
    type_client: String(c.type_client ?? '1'),
    type_document: TIPOS.has(tipo) ? tipo : 'DNI',
    n_document: tipo === 'SND' ? '' : (c.n_document ?? ''),
    name: c.name ?? '',
    surname: c.surname ?? '',
    full_name: c.full_name ?? '',
    name_comerc: c.name_comerc ?? '',
    email: c.email ?? '',
    phone: c.phone ?? '',
    birth_date: c.birth_date ?? '',
    gender: c.gender === 'M' || c.gender === 'F' ? c.gender : '',
    state: Number(c.state ?? 1) === 2 ? 2 : 1,
    address: c.address ?? '',
    ubigeo_region: c.ubigeo_region ?? '',
    ubigeo_provincia: c.ubigeo_provincia ?? '',
    ubigeo_distrito: c.ubigeo_distrito ?? '',
    regimen_tributario: c.regimen_tributario ?? 'SIN REGIMEN',
    es_agente_retencion: Boolean(c.es_agente_retencion),
  }
}

/** Nombre que se guarda en full_name: razón social, referencia (sin documento) o nombres + apellidos. */
export function nombreCompleto(f: Pick<FormCliente, 'type_document' | 'name' | 'surname' | 'full_name'>): string {
  if (f.type_document === 'RUC' || f.type_document === 'SND') return f.full_name.trim()
  return `${f.name.trim()} ${f.surname.trim()}`.trim()
}

/** Cuerpo de POST/PUT clients. Los datos tributarios solo viajan si la pantalla los muestra. */
export function aPayload(f: FormCliente, opciones: { tributarios: boolean }): Record<string, unknown> {
  const tipo = TIPOS_DOCUMENTO.find((t) => t.valor === f.type_document)!
  const persona = f.type_document !== 'RUC'
  const nombre = (lista: Ubigeo[], id: string) => lista.find((x) => x.id === id)?.name ?? null

  return {
    type_client: f.type_client,
    type_document: f.type_document,
    n_document: f.type_document === 'SND' ? null : f.n_document.trim(),
    cod_tipo_doc_sunat: tipo.sunat,
    name: persona && f.type_document !== 'SND' ? f.name.trim() : null,
    surname: persona && f.type_document !== 'SND' ? f.surname.trim() : null,
    full_name: nombreCompleto(f),
    name_comerc: persona ? null : f.name_comerc.trim() || null,
    email: f.email.trim() || null,
    phone: f.phone.trim() || null,
    birth_date: persona ? f.birth_date || null : null,
    gender: persona ? f.gender || null : null,
    state: f.state,
    address: f.address.trim() || null,
    ubigeo_region: f.ubigeo_region || null,
    ubigeo_provincia: f.ubigeo_provincia || null,
    ubigeo_distrito: f.ubigeo_distrito || null,
    region: nombre(regiones, f.ubigeo_region),
    provincia: nombre(provincias, f.ubigeo_provincia),
    distrito: nombre(distritos, f.ubigeo_distrito),
    es_amazonia: esAmazonia(f.ubigeo_region),
    ...(opciones.tributarios && !persona
      ? { regimen_tributario: f.regimen_tributario, es_agente_retencion: f.es_agente_retencion }
      : {}),
  }
}

const normal = (t: string) => t.toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '').trim()
const coincide = (a: string, b: string) => normal(a).includes(normal(b)) || normal(b).includes(normal(a))

/** Ubigeo a partir de los textos que devuelve la búsqueda de RUC ("LIMA", "LIMA", "MIRAFLORES"). */
export function ubigeoDesdeTexto(departamento?: string, provincia?: string, distrito?: string) {
  const region = departamento ? regiones.find((r) => coincide(r.name, departamento)) : undefined
  const prov = region && provincia ? provinciasDe(region.id).find((p) => coincide(p.name, provincia)) : undefined
  const dist = prov && distrito ? distritosDe(prov.id).find((d) => coincide(d.name, distrito)) : undefined
  return { ubigeo_region: region?.id ?? '', ubigeo_provincia: prov?.id ?? '', ubigeo_distrito: dist?.id ?? '' }
}

/** Errores del 422 de Laravel ({errors: {campo: [mensaje]}}) → primer mensaje por campo. */
export function erroresDeValidacion(e: unknown): Record<string, string> {
  const errores = (e as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })?.response
  if (errores?.status !== 422 || !errores.data?.errors) return {}
  return Object.fromEntries(Object.entries(errores.data.errors).map(([campo, mensajes]) => [campo, mensajes[0]]))
}

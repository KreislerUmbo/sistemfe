// Módulo Créditos — Configuración del negocio (04-frontend pantalla 7). Definición de los
// campos por sección, conversión formulario ↔ API y envío solo de lo que cambió (el PUT
// valida con "sometimes"). Los montos viajan como texto decimal; aquí no se calcula nada.
import type { ConfiguracionCredito } from '@/types/creditos'
import { ETIQUETA_REQUISITO, REQUISITOS } from './ficha'

/** lista = varias casillas de "opciones" (se guarda como arreglo de valores). */
type Tipo = 'entero' | 'porcentaje' | 'soles' | 'booleano' | 'select' | 'dias' | 'lista'

export interface CampoConfig {
  clave: string
  tipo: Tipo
  etiqueta: string
  ayuda?: string
  /** Vacío = sin límite (se envía null). */
  opcional?: boolean
  opciones?: { valor: string; texto: string }[]
  /** Se muestra solo si devuelve true con el formulario actual. */
  visible?: (f: FormConfig) => boolean
}

export interface SeccionConfig {
  titulo: string
  descripcion?: string
  campos: CampoConfig[]
}

export type ValorConfig = string | boolean | number[] | string[]
export type FormConfig = Record<string, ValorConfig>

const conMora = (f: FormConfig) => f.cobra_mora === true

export const SECCIONES_CONFIG: SeccionConfig[] = [
  {
    titulo: 'Condiciones por defecto',
    descripcion: 'Se copian a cada crédito nuevo y quedan fijas al activarlo: cambiarlas no afecta créditos ya entregados.',
    campos: [
      { clave: 'tasa_interes_minimo', tipo: 'porcentaje', etiqueta: 'Interés mínimo al cancelar antes', ayuda: 'Porcentaje del capital que se cobra aunque pague antes.' },
      {
        clave: 'cobra_mora', tipo: 'booleano', etiqueta: 'Cobrar mora por atraso',
        ayuda: 'Valor inicial de cada crédito nuevo; se puede cambiar por crédito en "Opciones avanzadas".',
      },
      { clave: 'dias_gracia', tipo: 'entero', etiqueta: 'Días de gracia', ayuda: 'Días después del vencimiento sin mora.', visible: conMora },
      {
        clave: 'tope_mora_tipo', tipo: 'select', etiqueta: 'Tope de la mora', visible: conMora, opciones: [
          { valor: 'porcentaje_cuota', texto: '% de la cuota' },
          { valor: 'porcentaje_capital', texto: '% del capital' },
          { valor: 'dias_maximos', texto: 'Máximo de días' },
          { valor: 'sin_tope', texto: 'Sin tope' },
        ],
      },
      {
        clave: 'tope_mora_valor', tipo: 'entero', etiqueta: 'Valor del tope', opcional: true,
        ayuda: 'En % o en días, según el tipo de tope.', visible: (f) => conMora(f) && f.tope_mora_tipo !== 'sin_tope',
      },
      { clave: 'max_numero_cuotas', tipo: 'entero', etiqueta: 'Máximo de pagos por crédito' },
      {
        clave: 'tasa_maxima', tipo: 'porcentaje', etiqueta: 'Tasa máxima permitida', opcional: true,
        ayuda: 'Tope legal (BCRP / usura) a confirmar con su abogado. Vacío = sin tope.',
      },
    ],
  },
  {
    titulo: 'Cartera y ficha del cliente',
    descripcion: 'Quién atiende a cada cliente y qué datos se exigen antes de prestarle.',
    campos: [
      {
        clave: 'asesor_cobra', tipo: 'booleano', etiqueta: 'El asesor también cobra',
        ayuda: 'Apagado: cada cliente tiene un asesor y un cobrador por separado.',
      },
      {
        clave: 'requisitos_ficha', tipo: 'lista', etiqueta: 'Exigir para activar un crédito',
        ayuda: 'Si falta algo, activar el crédito pide autorización. En créditos migrados solo avisa.',
        opciones: REQUISITOS.map((r) => ({ valor: r, texto: ETIQUETA_REQUISITO[r] })),
      },
    ],
  },
  {
    titulo: 'Calendario de cobro',
    campos: [
      { clave: 'dias_no_laborables', tipo: 'dias', etiqueta: 'Días sin cobro' },
      { clave: 'saltar_feriados', tipo: 'booleano', etiqueta: 'No cobrar en feriados' },
      {
        clave: 'regla_no_laborable', tipo: 'select', etiqueta: 'Si un pago cae en día sin cobro', opciones: [
          { valor: 'siguiente', texto: 'Pasa al siguiente día hábil' },
          { valor: 'anterior', texto: 'Pasa al día hábil anterior' },
          { valor: 'mantener', texto: 'Se mantiene la fecha' },
        ],
      },
      { clave: 'mora_cuenta_no_laborables', tipo: 'booleano', etiqueta: 'La mora corre también en días sin cobro', visible: conMora },
    ],
  },
  {
    titulo: 'Límites para prestar',
    descripcion: 'Se revisan al activar un crédito; con permiso se pueden autorizar excepciones.',
    campos: [
      { clave: 'max_creditos_activos', tipo: 'entero', etiqueta: 'Créditos activos por cliente' },
      { clave: 'deuda_maxima_cliente', tipo: 'soles', etiqueta: 'Deuda máxima por cliente', opcional: true, ayuda: 'Vacío = sin límite.' },
      { clave: 'dias_atraso_bloqueo', tipo: 'entero', etiqueta: 'Bloquear al cliente con más de … días de atraso' },
    ],
  },
  {
    titulo: 'Cobranza y atrasos',
    campos: [
      { clave: 'dias_max_pago_retroactivo', tipo: 'entero', etiqueta: 'Días máximos para registrar un pago con fecha anterior' },
      { clave: 'dias_para_castigo', tipo: 'entero', etiqueta: 'Castigar el crédito con más de … días de atraso' },
      {
        clave: 'umbral_alerta_anulaciones', tipo: 'entero', etiqueta: 'Alertar cuando un usuario supere … anulaciones de pago',
        ayuda: 'Lo usa el reporte de control y auditoría.',
      },
    ],
  },
  {
    titulo: 'Reprogramación',
    campos: [
      {
        clave: 'cargo_reprogramacion_tipo', tipo: 'select', etiqueta: 'Cargo por reprogramar', opciones: [
          { valor: 'ninguno', texto: 'Sin cargo' },
          { valor: 'fijo', texto: 'Monto fijo' },
          { valor: 'interes_por_dias', texto: 'Interés de los días movidos' },
        ],
      },
      { clave: 'cargo_reprogramacion_monto', tipo: 'soles', etiqueta: 'Monto del cargo', visible: (f) => f.cargo_reprogramacion_tipo === 'fijo' },
    ],
  },
  {
    titulo: 'Garantías y prendas',
    descripcion: 'Se usan cuando estén activos los módulos de garantes y prendas.',
    campos: [
      { clave: 'max_garantias_por_garante', tipo: 'entero', etiqueta: 'Créditos que puede respaldar un garante' },
      { clave: 'dias_aviso_garante', tipo: 'entero', etiqueta: 'Avisar al garante con más de … días de atraso' },
      { clave: 'dias_para_venta', tipo: 'entero', etiqueta: 'Prenda apta para venta con más de … días de atraso' },
      { clave: 'porcentaje_prestamo_max', tipo: 'porcentaje', etiqueta: 'Préstamo máximo sobre la tasación', opcional: true, ayuda: 'Vacío = sin límite.' },
      {
        clave: 'validacion_tasacion', tipo: 'select', etiqueta: 'Si el préstamo supera ese %', opciones: [
          { valor: 'ninguna', texto: 'No revisar' },
          { valor: 'advertir', texto: 'Solo advertir' },
          { valor: 'bloquear', texto: 'Bloquear' },
        ],
      },
    ],
  },
]

const CAMPOS = SECCIONES_CONFIG.flatMap((s) => s.campos)

/** "10.0000" → "10": solo presentación (quita ceros a la derecha del texto). */
function decimalATexto(valor: unknown): string {
  if (valor === null || valor === undefined || valor === '') return ''
  const texto = String(valor)
  return texto.includes('.') ? texto.replace(/\.?0+$/, '') : texto
}

export function formularioConfig(config: ConfiguracionCredito): FormConfig {
  const form: FormConfig = {}
  for (const c of CAMPOS) {
    const v = config[c.clave]
    if (c.tipo === 'booleano') form[c.clave] = Boolean(v)
    else if (c.tipo === 'dias') form[c.clave] = Array.isArray(v) ? [...(v as number[])].sort() : []
    else if (c.tipo === 'lista') form[c.clave] = ordenLista(c, Array.isArray(v) ? (v as string[]) : [])
    else if (c.tipo === 'porcentaje' || c.tipo === 'soles') form[c.clave] = decimalATexto(v)
    else form[c.clave] = v === null || v === undefined ? '' : String(v)
  }
  return form
}

const ENTERO = /^\d{1,6}$/
const DECIMAL = { porcentaje: /^\d{1,6}(?:\.\d{1,4})?$/, soles: /^\d{1,12}(?:\.\d{1,2})?$/ }

type ValorApi = string | number | boolean | number[] | string[] | null

/** Valores de una lista en el orden de sus opciones (para comparar sin falsos cambios). */
export function ordenLista(campo: CampoConfig, valores: string[]): string[] {
  const orden = (campo.opciones ?? []).map((o) => o.valor)
  return orden.filter((v) => valores.includes(v))
}

/** Valor para la API o un mensaje de error. */
function aApi(campo: CampoConfig, valor: ValorConfig): { valor: ValorApi } | { error: string } {
  if (campo.tipo === 'lista') return { valor: ordenLista(campo, valor as string[]) }
  if (campo.tipo === 'booleano' || campo.tipo === 'dias' || campo.tipo === 'select') {
    if (campo.tipo === 'dias' && (valor as number[]).length > 6) return { error: 'Debe quedar al menos un día de cobro.' }
    return { valor: valor as ValorApi }
  }
  const texto = String(valor).trim().replace(',', '.')
  if (texto === '') return campo.opcional ? { valor: null } : { error: 'Obligatorio.' }
  if (campo.tipo === 'entero') return ENTERO.test(texto) ? { valor: Number.parseInt(texto, 10) } : { error: 'Número entero.' }
  return DECIMAL[campo.tipo].test(texto) ? { valor: texto } : { error: campo.tipo === 'soles' ? 'Monto inválido.' : 'Porcentaje inválido.' }
}

/** Solo los campos que cambiaron respecto de lo guardado, o los errores de validación local. */
export function cambiosConfig(original: FormConfig, form: FormConfig): { cambios: Record<string, ValorApi>; errores: Record<string, string> } {
  const cambios: Record<string, ValorApi> = {}
  const errores: Record<string, string> = {}
  for (const c of CAMPOS) {
    if (c.visible && !c.visible(form)) continue
    const nuevo = aApi(c, form[c.clave])
    if ('error' in nuevo) {
      errores[c.clave] = nuevo.error
      continue
    }
    const antes = aApi(c, original[c.clave])
    if (JSON.stringify('valor' in antes ? antes.valor : undefined) !== JSON.stringify(nuevo.valor)) cambios[c.clave] = nuevo.valor
  }
  return { cambios, errores }
}

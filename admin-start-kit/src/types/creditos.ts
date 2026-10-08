// Módulo Créditos (docs/planning/creditos/04-frontend.md) — tipos de la API de
// routes/creditos.php. Los montos llegan y se envían como string decimal ("1234.50"):
// el frontend nunca hace aritmética de dinero, solo los muestra (00 §4).

export type Soles = string

export type CreditoEstado = 'borrador' | 'activo' | 'castigado' | 'finalizado' | 'anulado'
export type CuotaEstado = 'pendiente' | 'pagada' | 'anulada'
export type UnidadTasa = 'total' | 'mensual'
export type FrecuenciaUnidad = 'dia' | 'semana' | 'quincena' | 'mes' | 'anio'
export type ReglaNoLaborable = 'siguiente' | 'anterior' | 'mantener'
export type TopeMoraTipo = 'porcentaje_cuota' | 'porcentaje_capital' | 'dias_maximos' | 'sin_tope'
export type DestinoExcedente = 'devolver' | 'adelanto' | 'saldo_a_favor'
export type ReglaLimite = 'max_creditos' | 'deuda_maxima' | 'moroso' | 'bloqueado' | 'propio_garante' | 'garante_moroso' | 'garante_saturado'
  | 'ficha_incompleta'

/** Cuerpo de POST creditos/preview, POST creditos y PUT creditos/{id}. */
export interface CondicionesCredito {
  cliente_id: number | null
  monto_capital: string
  tasa_interes: string
  unidad_tasa: UnidadTasa
  frecuencia_unidad: FrecuenciaUnidad
  frecuencia_intervalo: number
  dias_quincena?: (number | 'ultimo')[] | null
  numero_cuotas: number
  fecha_desembolso: string
  fecha_primer_vencimiento?: string | null
  payment_method_id?: number | null
  dias_no_laborables?: number[]
  saltar_feriados?: boolean
  regla_no_laborable?: ReglaNoLaborable
  mora_cuenta_no_laborables?: boolean
  cobra_mora?: boolean
  tasa_interes_minimo?: string
  dias_gracia?: number
  tope_mora_tipo?: TopeMoraTipo
  tope_mora_valor?: number | null
}

export interface CuotaPrevia {
  numero_cuota: number
  fecha_vencimiento: string
  monto_capital: Soles
  monto_interes: Soles
  monto_total: Soles
  fecha_forzada_a_siguiente: boolean
  /** Fecha antes del ajuste por día sin cobro o feriado; null si no se movió. */
  fecha_original: string | null
  /** "domingo", "feriado: Navidad" o "no se pudo adelantar". */
  motivo_ajuste: string | null
  /** Nombre del feriado si el pago cae en uno (solo pasa con "no cobrar feriados" apagado). */
  feriado: string | null
}

export interface CronogramaPrevio {
  interes_total: Soles
  monto_total: Soles
  primer_vencimiento: string
  ultimo_vencimiento: string
  /** Interés prorrateado a 30 días del plazo (referencia simple, no TEA). */
  tasa_mensual_equivalente: string | null
  cuotas: CuotaPrevia[]
}

export interface Infraccion {
  regla: ReglaLimite
  autorizable: boolean
  /** ficha_incompleta trae "faltan": lista de requisitos (Fase 4c). */
  detalle: Record<string, number | string[] | null>
}

export interface ResultadoLimites {
  bloquea: boolean
  bloqueos: Infraccion[]
  advertencias: Infraccion[]
}

export interface PreviewCredito {
  cronograma: CronogramaPrevio
  limites: ResultadoLimites
}

export interface ClienteCredito {
  id: number
  nombre: string
  documento: string
  telefono: string | null
}

export interface Cuota {
  id: number
  numero_cuota: number
  fecha_inicio_periodo: string
  fecha_vencimiento: string
  fecha_vencimiento_original: string
  monto_capital: Soles
  monto_interes: Soles
  monto_total: Soles
  cargo_monto: Soles
  capital_pagado: Soles
  interes_pagado: Soles
  cargo_pagado: Soles
  mora_pagada: Soles
  mora_condonada: Soles
  mora_congelada: Soles
  interes_condonado: Soles
  estado: CuotaEstado
  fecha_pago: string | null
  dias_atraso_al_pagar: number | null
  // Solo en el detalle (situación calculada a hoy)
  mora_pendiente_hoy?: Soles
  dias_atraso_hoy?: number
  mora_tope_alcanzado?: boolean
}

export interface Credito {
  id: number
  numero_credito: string | null
  estado: CreditoEstado
  cliente?: ClienteCredito
  cliente_id: number
  monto_capital: Soles
  tasa_interes: string
  unidad_tasa: UnidadTasa
  interes_total: Soles
  monto_total: Soles
  frecuencia_unidad: FrecuenciaUnidad
  frecuencia_intervalo: number
  dias_quincena: (number | 'ultimo')[] | null
  numero_cuotas: number
  fecha_desembolso: string
  fecha_primer_vencimiento: string | null
  dias_no_laborables: number[]
  saltar_feriados: boolean
  regla_no_laborable: ReglaNoLaborable
  mora_cuenta_no_laborables: boolean
  cobra_mora: boolean
  tasa_interes_minimo: string
  dias_gracia: number
  tope_mora_tipo: TopeMoraTipo
  tope_mora_valor: number | null
  payment_method_id: number | null
  origen_registro: 'normal' | 'migracion' | 'renovacion'
  credito_renovado_id: number | null
  version_cronograma_actual: number
  fecha_castigo: string | null
  motivo_cierre: string | null
  motivo_anulacion: string | null
  cuotas?: Cuota[]
  created_at: string
}

export interface CreditoCreado {
  credito: Credito
  limites?: ResultadoLimites
}

/** GET clientes/{id}/resumen-credito — tarjeta del cliente en el formulario (00 1.10). */
export interface ResumenClienteCredito {
  creditos_activos: number
  max_creditos_activos: number
  deuda_actual: Soles
  deuda_maxima: Soles | null
  deuda_disponible: Soles | null
  dias_atraso_maximo: number
  cuotas_pagadas: number
  cuotas_pagadas_a_tiempo: number
  bloqueado: boolean
  /** 04c.1: saldo a favor del cliente en soles. */
  saldo_a_favor: Soles
  ficha_faltante: RequisitoFaltante[]
}

/** GET creditos/configuracion — defaults del negocio. */
export interface ConfiguracionCredito {
  /** Fase 4c: el asesor del cliente también lo cobra (una sola asignación). */
  asesor_cobra?: boolean
  /** Fase 4c: datos de la ficha exigidos para activar un crédito. */
  requisitos_ficha?: RequisitoFicha[] | null
  tasa_interes_minimo: string
  dias_gracia: number
  paso_redondeo: string
  dias_no_laborables: number[]
  saltar_feriados: boolean
  regla_no_laborable: ReglaNoLaborable
  mora_cuenta_no_laborables: boolean
  cobra_mora: boolean
  tope_mora_tipo: TopeMoraTipo
  tope_mora_valor: number | null
  max_numero_cuotas: number
  tasa_maxima: string | null
  [clave: string]: unknown
}

/** GET creditos/{id}: crédito + situación calculada a hoy por el backend. */
export interface ResumenDetalle {
  saldo_capital: Soles
  saldo_interes: Soles
  mora_pendiente: Soles
  exigible_hoy: Soles
  dias_atraso: number
  saldo_por_pagar: Soles
  total_pagado: Soles
  cuotas_pagadas: number
  cuotas_total: number
  cuotas_vencidas: number
  proxima: ProximaCuota | null
  /** Desglose del exigible de hoy: sus líneas (pendiente + mora) suman exigible_hoy. */
  deuda_hoy: DeudaHoy[]
}

export interface ProximaCuota {
  numero_cuota: number
  fecha_vencimiento: string
  pendiente: Soles
}

export interface DeudaHoy {
  numero_cuota: number
  fecha_vencimiento: string
  /** false = la próxima cuota por vencer (o una con solo cargo). */
  vencida: boolean
  pendiente: Soles
  mora: Soles
  dias_atraso: number
  mora_tope_alcanzado: boolean
}

/** POST creditos/{id}/pagos/cotizar: cómo se aplicaría el monto hoy (sin escribir). */
export interface CotizacionPago {
  exigible_hoy: Soles
  monto_aplicado: Soles
  monto_excedente: Soles
  finaliza_credito: boolean
  saldo_despues: Soles
  proxima_despues: ProximaCuota | null
  aplicacion: { numero_cuota: number; concepto: 'interes' | 'capital' | 'cargo' | 'mora'; monto: Soles }[]
}

export interface SolicitudCobro {
  monto_recibido: string
  destino_excedente: DestinoExcedente
  /** null solo al pagar con saldo a favor (no entra dinero a caja). */
  payment_method_id: number | null
  /** 04c.1: paga con el saldo a favor del cliente. */
  usar_saldo_a_favor?: boolean
  referencia?: string | null
  observaciones?: string | null
  /** Pago con fecha anterior (creditos.pago_fecha_anterior); exige motivo. */
  fecha_pago?: string | null
  motivo?: string | null
}

export interface DetalleCredito {
  credito: Credito
  resumen: ResumenDetalle
  cuotas: Cuota[]
}

export type OrigenPago = 'cobro' | 'liquidacion' | 'renovacion' | 'venta_prenda' | 'saldo_a_favor' | 'saldo_inicial'

export interface Pago {
  id: number
  credito_id: number
  numero_recibo: string
  monto_recibido: Soles
  monto_aplicado: Soles
  monto_excedente: Soles
  destino_excedente: DestinoExcedente | null
  fecha_pago: string
  origen: OrigenPago
  es_cierre: boolean
  pagado_por_cliente_id: number | null
  payment_method_id: number | null
  referencia: string | null
  observaciones: string | null
  estado: 'valido' | 'anulado'
  motivo_anulacion: string | null
  registrado_por: number
  aplicaciones?: { numero_cuota: number | null; concepto: 'interes' | 'capital' | 'cargo' | 'mora'; monto: Soles }[]
}

export interface EstadoCuenta {
  credito: Credito
  cuotas: Cuota[]
  pagos: Pago[]
  condonaciones: { numero_cuota: number | null; monto: Soles; motivo: string; estado: string; fecha: string | null }[]
  cargos: { numero_cuota: number | null; monto: Soles; estado: string }[]
  castigos: { fecha_castigo: string; fecha_reversion: string | null; tipo: string; motivo: string | null }[]
  reprogramaciones: { fecha: string | null; motivo: string; cargo: Soles; accion_mora: string; cuotas: { cuota_id: number; fecha_anterior: string; fecha_nueva: string }[] }[]
  total_pagado: Soles
}

export interface Liquidacion {
  fecha: string
  capital_pendiente: Soles
  interes_devengado: Soles
  interes_minimo: Soles
  interes_final: Soles
  interes_cobrado: Soles
  interes_a_cobrar: Soles
  interes_descontado: Soles
  cargos_pendientes: Soles
  mora_pendiente: Soles
  monto_liquidacion: Soles
  reparto: { numero_cuota: number; capital: Soles; interes: Soles; interes_descontado: Soles; cargo: Soles; mora: Soles }[]
}

export interface SolicitudReprogramacion {
  modo: 'desplazar' | 'editar'
  desde_cuota?: number
  dias?: number
  fechas?: Record<number, string>
  accion_mora?: 'mantener' | 'condonar'
  cargo?: string | null
}

export interface PreviewReprogramacion {
  cambios: { numero_cuota: number; fecha_anterior: string; fecha_nueva: string; mora_congelada: Soles; cae_en_no_laborable: boolean }[]
  mora_acumulada: Soles
  accion_mora: 'mantener' | 'condonar' | 'no_aplica'
  cargo_tipo: 'ninguno' | 'fijo' | 'interes_por_dias'
  cargo_sugerido: Soles
  cargo: Soles
}

export interface PreviewRenovacion {
  liquidacion: Liquidacion
  capital_nuevo: Soles
  entrega_neta: Soles
  /** Qué pasa con el dinero (1.21 ampliada): se entrega, no se mueve o el cliente paga la diferencia. */
  movimiento: 'entrega' | 'sin_movimiento' | 'cobro'
  /** Monto de ese movimiento, siempre en positivo (lo calcula el backend). */
  monto_movimiento: Soles
  cronograma: CronogramaPrevio
  limites: ResultadoLimites
}

export interface MetodoPago {
  id: number
  code: string
  name: string
}

/** GET cash/status reducido a lo que muestra Cobrar. */
export interface EstadoCaja {
  abierta: boolean
  caja: string | null
  cajero: string | null
  /** opened_at tal como lo manda el backend (ISO). */
  desde: string | null
}

/** GET creditos: una fila del listado con su situación a hoy (null en borrador/anulado). */
export interface FilaCredito {
  id: number
  numero_credito: string | null
  estado: CreditoEstado
  cliente: { id: number; nombre: string; documento: string | null; telefono: string | null }
  monto_capital: Soles
  monto_total: Soles
  frecuencia_unidad: FrecuenciaUnidad
  frecuencia_intervalo: number
  numero_cuotas: number
  fecha_desembolso: string | null
  origen_registro: string
  situacion: {
    saldo_por_pagar: Soles
    exigible_hoy: Soles
    mora_pendiente: Soles
    dias_atraso: number
    cuotas_pagadas: number
    cuotas_vencidas: number
    cuotas_total: number
    proxima: ProximaCuota | null
  } | null
}

export interface Paginado<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

/** GET creditos/cobranza-del-dia (mockup 4): montos y conteos sumados por el backend. */
export interface CobranzaDelDia {
  fecha: string
  resumen: {
    por_cobrar: Soles
    cobrado: Soles
    /** Pagaron hoy y no les queda nada exigible (un abono parcial no cuenta). */
    clientes_al_dia: number
    clientes_total: number
  }
  /** Vacío si el usuario no ve toda la cartera. */
  cobradores: { id: number; nombre: string }[]
  data: PendienteCobranza[]
  cobrados: CobradoHoy[]
}

export interface PendienteCobranza {
  credito_id: number
  numero_credito: string | null
  cliente: {
    id: number
    nombre: string
    telefono: string | null
    telefono_alterno: string | null
    direccion_cobro: string | null
    referencia: string | null
    latitud: string | null
    longitud: string | null
  }
  cobrador: { id: number; nombre: string | null } | null
  estado: 'vencido' | 'hoy'
  exigible_hoy: Soles
  mora_pendiente: Soles
  dias_atraso: number
  cuotas_vencidas: number
}

export interface CobradoHoy {
  credito_id: number
  numero_credito: string | null
  cliente: { id: number; nombre: string }
  cobrador: { id: number; nombre: string | null } | null
  monto_aplicado: Soles
  /** "HH:MM" en Lima. */
  ultimo_pago: string
  metodos: string[]
}

/** POST creditos/migrar: crédito anterior al sistema (00 1.12). No mueve caja. */
export type SolicitudMigracion = CondicionesCredito & {
  condonar_mora: boolean
} & ({ modo_pagos: 'rapido'; cuotas_pagadas: number } | { modo_pagos: 'detallado'; pagos: { fecha: string; monto: string }[] })

export interface Feriado {
  id: number
  fecha: string
  descripcion: string
  origen: 'nacional' | 'propio'
}

export type TipoArchivoCliente = 'dni_anverso' | 'dni_reverso' | 'foto_cliente' | 'otro'

/** GET clientes/{id}/ficha-credito: datos de cobro del cliente (dato personal, Ley 29733). */
export interface FichaCliente {
  ficha: {
    direccion_cobro: string | null
    tipo_direccion: 'casa' | 'negocio' | null
    referencia: string | null
    latitud: string | null
    longitud: string | null
    telefono_alterno: string | null
    ocupacion: string | null
    notas: string | null
  } | null
  archivos: { id: number; tipo: TipoArchivoCliente; created_at: string }[]
  cartera: CarteraCliente
  historial_cartera: AsignacionCartera[]
  limites: LimitesCliente | null
  ficha_faltante: RequisitoFaltante[]
  saldo_a_favor: Soles
}

/** 04c.1: saldo a favor del cliente y sus movimientos (abono, uso, devolución, reverso). */
export interface SaldoAFavor {
  saldo: Soles
  movimientos: {
    id: number
    tipo: 'abono' | 'uso' | 'devolucion' | 'reverso'
    /** Devolución anulada (su salida de caja se revirtió). */
    anulada: boolean
    monto: Soles
    numero_recibo: string | null
    motivo: string | null
    fecha: string | null
  }[]
}

/** Fase 4c: asesor y cobrador del cliente. Con asesor_cobra son la misma persona. */
export interface CarteraCliente {
  asesor_id: number | null
  cobrador_id: number | null
  asesor_cobra: boolean
}

export interface AsignacionCartera {
  funcion: 'asesor' | 'cobrador'
  usuario: string | null
  vigente_desde: string | null
  vigente_hasta: string | null
  asignado_por: string | null
}

export interface UsuarioCartera { id: number; nombre: string }

/** 04c.1: usuario con clientes en su cartera (puede estar inactivo o eliminado). */
export interface TitularCartera { id: number; nombre: string; activo: boolean; eliminado: boolean; asesor: number; cobrador: number }

export interface LimitesCliente {
  max_creditos_activos: number | null
  deuda_maxima: string | null
  bloqueado: boolean
  motivo_bloqueo: string | null
}

export type RequisitoFicha =
  | 'dni_anverso' | 'dni_reverso' | 'foto_cliente' | 'telefono' | 'direccion_cobro' | 'referencia' | 'ubicacion' | 'ocupacion'

export interface RequisitoFaltante { requisito: RequisitoFicha; etiqueta: string }

export type DatosFicha = NonNullable<FichaCliente['ficha']>

// ── Documentos (Fase 4b) ──

export type FormatoPdf = 'a4' | 'ticket80mm'
export type TipoDocumentoPdf = 'contrato' | 'cronograma' | 'estado-cuenta' | 'constancia' | 'reprogramacion'

/** GET creditos/{id}/documentos: archivos guardados y reprogramaciones con acuerdo. */
export interface DocumentosCredito {
  documentos: { id: number; tipo: 'contrato' | 'contrato_firmado'; plantilla_version: number | null; creado: string | null; registrado_por: string | null }[]
  reprogramaciones: { id: number; fecha: string | null }[]
}

export interface PlantillaContrato {
  contenido: string
  version: number
  actualizado: string | null
  variables: { clave: string; descripcion: string }[]
}

// ── Panel y reportes (Fase 4d). Montos en soles como texto, ya sumados por el backend. ──

export type NombreReporte = 'agenda' | 'cartera' | 'morosidad' | 'ingresos' | 'asesores' | 'castigados' | 'control'
export type RangoAtraso = 'al_dia' | '1-7' | '8-15' | '16-30' | '31-60' | '60+'
export type AgrupacionIngresos = 'dia' | 'semana' | 'mes'

export interface PeriodoReporte { desde: string; hasta: string; texto: string }
export interface OpcionReporte { id: number; nombre: string }

/** Filtros que acepta GET creditos/reportes/{reporte} (cada reporte usa los suyos). */
export interface FiltrosReporte {
  desde?: string
  hasta?: string
  agrupacion?: AgrupacionIngresos
  asesor_id?: number | null
  cobrador_id?: number | null
  rango?: RangoAtraso | ''
  estado?: 'activo' | 'castigado' | ''
  distrito?: string
  accion?: string
  incluir_atrasados?: boolean
}

export interface FilaAtraso {
  credito_id: number
  numero_credito: string
  cliente: string
  cliente_id: number
  dias_atraso: number
  saldo_capital: string
  mora: string
}

export interface PanelCreditos {
  corte: string
  cartera: { saldo_capital: string; creditos: number; clientes: number }
  riesgo: { porcentaje: string; saldo_capital: string; creditos: number }
  cobranza_hoy: { por_cobrar: string; cobrado: string; porcentaje: string }
  conciliacion: { creditos: string; caja: string; cuadra: boolean; diferencia: string }
  cobrado_dias: { fecha: string; monto: string }[]
  mas_atrasados: FilaAtraso[]
}

export interface TotalesAgenda { cuotas: number; clientes: number; monto: string }

export interface FilaAgenda {
  dia: string | null
  fecha_vencimiento: string
  cobrador_id: number | null
  cobrador: string
  credito_id: number
  numero_credito: string
  forma_pago: string
  numero_cuota: number
  cuotas_total: number
  pendiente: string
  mora: string
  con_atraso: boolean
  cliente: {
    id: number
    nombre: string
    telefono: string | null
    telefono_alterno: string | null
    direccion_cobro: string | null
    referencia: string | null
    distrito: string | null
    latitud: string | null
    longitud: string | null
  }
}

export interface GrupoCobradorAgenda { cobrador: string; totales: TotalesAgenda; filas: FilaAgenda[] }

/** dia null = el grupo de cuotas ya atrasadas (con "incluir atrasados"). */
export interface AgendaCobranza {
  periodo: PeriodoReporte
  hoy: string
  dias: { dia: string | null; totales: TotalesAgenda; cobradores: GrupoCobradorAgenda[] }[]
  totales: TotalesAgenda
  opciones: { cobradores: OpcionReporte[]; distritos: string[] }
}

export interface FilaCartera extends FilaAtraso {
  estado: 'activo' | 'castigado'
  asesor: string | null
  capital_prestado: string
  saldo_interes: string
  cuotas_pagadas: number
  cuotas_total: number
  proximo_vencimiento: string | null
  rango_atraso: RangoAtraso
}

export interface ReporteCartera {
  corte: string
  filas: FilaCartera[]
  totales: { creditos: number; capital_prestado: string; saldo_capital: string; saldo_interes: string; mora: string }
  opciones: { asesores: OpcionReporte[] }
}

export interface GrupoMorosidad {
  rangos: { rango: RangoAtraso; creditos: number; saldo: string }[]
  saldo_total: string
  saldo_riesgo: string
  porcentaje_riesgo: string
}

export interface ReporteMorosidad {
  corte: string
  total: GrupoMorosidad
  por_asesor: (GrupoMorosidad & { asesor: string })[]
}

export interface MontosIngresos {
  desembolsado: string
  creditos_entregados: number
  capital: string
  interes: string
  mora: string
  cargo: string
  mora_condonada: string
  interes_descontado: string
  saldo_favor_devuelto: string
  cobrado: string
}

export interface ReporteIngresos {
  periodo: PeriodoReporte
  agrupacion: AgrupacionIngresos
  filas: (MontosIngresos & { periodo: string })[]
  totales: MontosIngresos
}

export interface ReporteAsesores {
  periodo: PeriodoReporte
  filas: {
    asesor_id: number | null
    asesor: string
    colocados: number
    capital_colocado: string
    cobrado: string
    cartera_creditos: number
    cartera_saldo: string
    porcentaje_riesgo: string
  }[]
}

export interface ReporteCastigados {
  periodo: PeriodoReporte
  castigos: { credito_id: number; numero_credito: string | null; cliente: string | null; fecha_castigo: string; tipo: string; revertido: boolean; saldo_hoy: string; motivo: string | null }[]
  recuperos: { pago_id: number; numero_recibo: string | null; fecha: string; credito_id: number; numero_credito: string; cliente: string; monto: string }[]
  total_recuperado: string
}

export interface ReporteControl {
  periodo: PeriodoReporte
  eventos: {
    fecha: string
    accion: string
    descripcion: string
    usuario_id: number | null
    usuario: string | null
    credito_id: number | null
    numero_credito: string | null
    monto: string | null
    motivo: string | null
  }[]
  umbral_anulaciones: number
  alertas: { usuario: string | null; anulaciones: number }[]
  acciones: { valor: string; texto: string }[]
}

export interface ReportesPorNombre {
  agenda: AgendaCobranza
  cartera: ReporteCartera
  morosidad: ReporteMorosidad
  ingresos: ReporteIngresos
  asesores: ReporteAsesores
  castigados: ReporteCastigados
  control: ReporteControl
}

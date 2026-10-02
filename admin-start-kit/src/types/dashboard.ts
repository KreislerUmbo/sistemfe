// Inicio con datos reales (GET /dashboard, DashboardService). Montos como texto decimal por
// moneda, ya sumados por el backend; cada bloque llega solo si el giro y los permisos lo permiten.

export interface ResumenVentas {
  moneda: string
  bruto: string
  notas_credito: string
  notas_credito_cantidad: number
  neto: string
  comprobantes: number
  internos: number
  fiscal: string
  interno: string
  ticket_promedio: string
}

export interface ResumenVentasMes extends ResumenVentas {
  neto_mes_anterior: string
  /** Variación % con un decimal ("12.5"); null sin ventas el mes anterior. */
  variacion: string | null
}

export interface RangoFechas { desde: string; hasta: string }

export interface PendienteSunat {
  id: number
  comprobante: string | null
  adelanto: boolean
  cliente: string | null
  fecha: string | null
  moneda: string
  total: string
  estado: 'por_enviar' | 'error'
  error: string | null
}

export interface BloquesDashboard {
  ventas?: { hoy: ResumenVentas[]; mes: ResumenVentasMes[]; periodo_mes: RangoFechas; periodo_anterior: RangoFechas }
  ventas_dias?: { monedas: string[]; dias: { fecha: string; montos: Record<string, string> }[] }
  sunat?: { por_enviar: number; con_error: number; lista: PendienteSunat[]; notas: { pendientes: number; rechazadas: number } | null }
  por_cobrar?: { por_moneda: { moneda: string; ventas: number; clientes: number; saldo: string; vencidas: number; saldo_vencido: string }[] }
  productos?: {
    top: { id: number; producto: string; cantidad: string; ventas: number }[]
    sin_stock: number
    sin_stock_lista: { id: number; producto: string; stock: number }[]
  }
  cotizaciones?: { creadas: number; reservadas: number; enviadas: number; borrador: number; conversion: string }
  proximos?: {
    hasta: string
    viajes: { id: number; codigo: string | null; cliente: string | null; desde: string | null; hasta: string | null; pasajeros: number }[]
    viajes_total: number
    salidas: { id: number; fecha: string; hora: string | null; tour: string | null; guia: string | null; reservas: number; pasajeros: number; cupo: number | null }[]
    sin_asignar: number
  }
}

export interface Dashboard {
  giro: string | null
  hoy: string
  bloques: BloquesDashboard
}

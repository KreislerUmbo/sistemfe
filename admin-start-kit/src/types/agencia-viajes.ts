// Sesión 11a — maestros del vertical Agencia de Viajes. Tipos livianos,
// solo los campos que el frontend efectivamente usa (no un espejo 1:1 de
// cada columna de BD).

export type ProveedorTipo = {
  id: number;
  nombre: string;
  slug: string;
  habilitado: boolean;
};

export type Proveedor = {
  id: number;
  codigo?: string | null;
  razon_social: string;
  nombre_comercial?: string | null;
  tipo_persona?: string | null;
  tipo_documento?: string | null;
  numero_documento?: string | null;
  direccion?: string | null;
  telefono?: string | null;
  celular?: string | null;
  whatsapp?: string | null;
  email?: string | null;
  pagina_web?: string | null;
  facebook?: string | null;
  instagram?: string | null;
  tiktok?: string | null;
  linkedin?: string | null;
  logo?: string | null;
  observaciones?: string | null;
  estado: boolean;
  tipo_id: number;
  margen_default_tipo?: 'porcentaje' | 'fijo' | null;
  margen_default_valor?: number | null;
  proveedor_servicios?: ProveedorServicio[];
  created_at?: string;
};

export type Proveedores = {
  total: number;
  paginate: number;
  proveedores: Proveedor[];
};

export type ProveedorResponse = {
  code: number;
  message: string;
  proveedor?: Proveedor;
};

export type DestinoServicio = {
  id: number;
  destino_atractivo_id: number;
  servicio_id: number;
  destino_atractivo?: DestinoAtractivo;
  servicio?: Servicio;
};

export type ProveedorServicio = {
  id: number;
  proveedor_id: number;
  destino_servicio_id: number;
  destino_servicio?: DestinoServicio;
  proveedor?: Proveedor;
  proveedor_tarifas?: ProveedorTarifa[];
};

export type ProveedorTarifa = {
  id: number;
  proveedor_servicio_id: number;
  proveedor_servicio?: ProveedorServicio;
  tipo_tarifa: 'corporativa' | 'grupal' | 'publica';
  modalidad: 'compartido' | 'privado';
  moneda: 'PEN' | 'USD';
  diferenciador?: Record<string, any> | null;
  tipo_habitacion?: 'simple' | 'matrimonial' | 'doble' | 'triple' | 'familiar' | null;
  precio_costo: number;
  margen_tipo: 'porcentaje' | 'fijo';
  margen_valor: number;
  descuento_maximo_pct?: number | null;
  margen_minimo_pct?: number | null;
  precio_venta_adulto: number;
  precio_venta_nino?: number | null;
  precio_venta_infante?: number | null;
  edad_min_nino?: number | null;
  edad_max_nino?: number | null;
  edad_max_infante?: number | null;
  temporada_id?: number | null;
  vigente_desde: string;
  vigente_hasta?: string | null;
  tip_afe_igv: string;
  destino_tributario: 'amazonia' | 'nacional' | 'extranjero';
};

export type DestinoAtractivo = {
  id: number;
  parent_id?: number | null;
  nombre: string;
  tipo: 'zona' | 'lugar' | 'atractivo';
  descripcion?: string | null;
  fotos?: string[] | null;
  hijos?: DestinoAtractivo[];
};

export type Servicio = {
  id: number;
  nombre: string;
  tipo_proveedor_id?: number | null;
};

export type Servicios = {
  total: number;
  paginate: number;
  servicios: Servicio[];
};

export type Temporada = {
  id: number;
  nombre: string;
  tipo: 'fija' | 'movil';
  temporada_ocurrencias?: TemporadaOcurrencia[];
};

export type TemporadaOcurrencia = {
  id: number;
  temporada_id: number;
  anio: number;
  fecha_desde: string;
  fecha_hasta: string;
};

export type Guia = {
  id: number;
  nombre: string;
  documento: string;
  telefono: string;
  activo: boolean;
  guia_tarifas?: GuiaTarifa[];
};

export type Guias = {
  total: number;
  paginate: number;
  guias: Guia[];
};

export type GuiaTarifa = {
  id: number;
  guia_id: number;
  destino_id: number;
  modalidad: 'dia_local' | 'grupo_multidia';
  costo_diario: number;
  tipo_margen: 'porcentaje' | 'fijo';
  margen_valor: number;
  moneda: 'PEN' | 'USD';
  vigente_desde: string;
  vigente_hasta?: string | null;
  destino?: DestinoAtractivo;
};

export type ConfiguracionAgencia = {
  edad_max_infante: number;
  edad_max_nino: number;
  formato_descuento_pdf: 'solo_final' | 'tachado' | 'separado';
  mostrar_descuento_como_linea: boolean;
  dias_vigencia_cotizacion: number | null;
  dias_limpieza_alternativas_descartadas: number | null;
  max_pax_reserva_con_vuelo: number;
  max_pax_reserva_grupo: number;
  meses_margen_vencimiento_documento: number;
  dias_aviso_pago_proveedor: number;
  dias_cotizacion_estancada: number;
};

export type ApiMessageResponse = {
  code: number;
  message: string;
};

// ═══════════════════════════════════════════════════════════════
// Sesión 11b — cotizador
// ═══════════════════════════════════════════════════════════════

export type CotizacionPasajero = {
  id: number;
  cotizacion_id: number;
  tipo_pax: 'adulto' | 'nino' | 'infante';
  edad: number;
};

export type Cotizacion = {
  id: number;
  cliente_id: number;
  codigo_prefijo: string;
  codigo: string;
  destino: string;
  fecha_viaje_desde?: string | null;
  fecha_viaje_hasta?: string | null;
  cliente?: { id: number; full_name: string; n_document?: string };
  pasajeros?: CotizacionPasajero[];
  alternativas?: Alternativa[];
  alternativas_count?: number;
};

export type Cotizaciones = {
  total: number;
  paginate: number;
  cotizaciones: Cotizacion[];
};

export type CotizacionResponse = {
  code: number;
  message: string;
  cotizacion: Cotizacion;
};

export type Alternativa = {
  id: number;
  cotizacion_id: number;
  nombre: string;
  estado: 'borrador' | 'enviada' | 'aceptada' | 'descartada';
  moneda_cotizacion: 'PEN' | 'USD';
  tipo_cambio_aplicado: number;
  tipo_cambio_origen: 'dia' | 'agencia';
  fecha_envio?: string | null;
  fecha_vencimiento?: string | null;
  descuento_global_pct?: number | null;
  total: number;
  items?: AlternativaItem[];
};

export type AlternativaResponse = {
  code: number;
  message: string;
  alternativa: Alternativa;
};

export type OrigenItem = 'proveedor' | 'mayorista' | 'pasaje_aereo' | 'manual';

export type AlternativaItem = {
  id: number;
  alternativa_id: number;
  origen_tipo: OrigenItem;
  proveedor_tarifa_id?: number | null;
  opcion_mayorista_id?: number | null;
  descripcion_manual?: string | null;
  modo_precio: 'por_persona' | 'tarifa_fija';
  cantidad: number;
  pax_incluidos?: number[] | null;
  moneda_costo: 'PEN' | 'USD';
  costo_snapshot?: number | null;
  precio_venta_snapshot: number;
  descuento_pct?: number | null;
  precio_convertido: number;
  total: number;
  total_convertido: number;
  proveedor_tarifa?: ProveedorTarifa;
  opcion_mayorista?: OpcionMayorista;
  cotizacion_pasaje_aereo?: CotizacionPasajeAereo;
};

export type AlternativaItemResponse = {
  code: number;
  message: string;
  alternativa_item: AlternativaItem;
  precio_minimo_permitido?: number | null;
  alerta_piso?: boolean;
};

export type CotizacionPasajeAereo = {
  id: number;
  alternativa_item_id: number;
  aerolinea: string;
  itinerario?: string | null;
  moneda: 'PEN' | 'USD';
  tarifa_base_adulto: number;
  tarifa_base_nino?: number | null;
  tarifa_base_infante?: number | null;
  cargos: Array<{ codigo?: string; nombre: string; monto: number; tipo?: 'impuesto' | 'tasa_aeropuerto' | 'fee_agencia' }>;
  tua_incluida_en_tarifa: boolean;
  fee_agencia_monto: number;
  tip_afe_igv?: string | null;
  fecha_cotizado: string;
  costo_total: number;
  precio_venta_total: number;
};

export type OpcionMayorista = {
  id: number;
  alternativa_id: number;
  proveedor_id: number;
  salida_mayorista_id?: number | null;
  moneda: 'PEN' | 'USD';
  incluye?: string | null;
  notas?: string | null;
  vuelo_aerolinea?: string | null;
  vuelo_detalle?: string | null;
  estado: 'candidata' | 'elegida' | 'descartada';
  proveedor?: Proveedor;
  opciones_hotel?: OpcionHotel[];
  opcionales?: OpcionMayoristaOpcional[];
};

export type OpcionHotel = {
  id: number;
  opcion_mayorista_id?: number | null;
  paquete_plantilla_id?: number | null;
  proveedor_id?: number | null;
  nombre_hotel: string;
  categoria_estrellas?: number | null;
  opciones_hotel_tarifas?: OpcionHotelTarifa[];
};

export type OpcionHotelTarifa = {
  id: number;
  opcion_hotel_id: number;
  tipo_habitacion: 'simple' | 'matrimonial' | 'doble' | 'triple' | 'familiar';
  precio_costo: number;
  precio_venta: number;
};

export type OpcionMayoristaOpcional = {
  id: number;
  opcion_mayorista_id: number;
  nombre: string;
  precio_por_persona: number;
  moneda: 'PEN' | 'USD';
  incluye?: string | null;
  no_incluye?: string | null;
};

// ═══════════════════════════════════════════════════════════════
// Sesión 11b2 — catálogo de paquetes/tours de plantilla
// ═══════════════════════════════════════════════════════════════

export type PaquetePlantilla = {
  id: number;
  codigo?: string | null;
  categoria: 'local' | 'nacional' | 'internacional';
  nombre: string;
  descripcion?: string | null;
  fotos?: string[] | null;
  destino_atractivo_id: number;
  destino_atractivo?: DestinoAtractivo;
  duracion_horas: number;
  hora_salida?: string | null;
  hora_retorno?: string | null;
  lugar_recojo?: string | null;
  no_incluye?: string | null;
  recomendaciones?: string | null;
  vuelo_incluido: boolean;
  vuelo_aerolinea?: string | null;
  vuelo_detalle?: string | null;
  precio_venta_final?: number | null;
  vigencia_desde?: string | null;
  vigencia_hasta?: string | null;
  publicado_web: boolean;
  items?: PaquetePlantillaItem[];
  paquete_itinerario?: TourItinerarioItem[];
};

export type PaquetePlantillaItem = {
  id: number;
  paquete_plantilla_id: number;
  proveedor_tarifa_id?: number | null;
  proveedor_tarifa?: ProveedorTarifa;
  guia_tarifa_id?: number | null;
  guia_tarifa?: GuiaTarifa;
  orden?: number | null;
};

export type TourItinerarioItem = {
  id: number;
  tour_id: number;
  dia_relativo: number;
  hora?: string | null;
  orden?: number | null;
  destino_atractivo_id?: number | null;
  destino_atractivo?: DestinoAtractivo;
  descripcion: string;
};

export type PaquetesPlantilla = {
  total: number;
  paginate: number;
  paquetes_plantilla: PaquetePlantilla[];
};

export type PaquetePlantillaResponse = {
  code: number;
  message: string;
  paquete_plantilla: PaquetePlantilla;
};

export type PaquetePlantillaShowResponse = {
  paquete_plantilla: PaquetePlantilla;
  opciones_hotel: OpcionHotel[];
};

// ═══════════════════════════════════════════════════════════════
// Sesión 11c — reserva y pasajeros
// ═══════════════════════════════════════════════════════════════

export type MotivoCancelacion = 'voluntaria' | 'fuerza_mayor' | 'clima' | 'falta_pago_cuotas';

export type ReservaPasajero = {
  id: number;
  reserva_id: number;
  tipo_pax?: 'adulto' | 'nino' | 'infante' | null;
  nombre?: string | null;
  documento?: string | null;
  nacionalidad?: string | null;
  alimentacion_especial?: string | null;
  // texto libre (no booleano) — permite decir QUÉ discapacidad, no solo sí/no
  discapacidad?: string | null;
  vuelo_aerolinea_ida?: string | null;
  vuelo_hora_ida?: string | null;
  vuelo_aerolinea_vuelta?: string | null;
  vuelo_hora_vuelta?: string | null;
  pasajero_catalogo_id?: number | null;
  pasajero_catalogo?: PasajeroCatalogo | null;
};

export type PasajeroCatalogo = {
  id: number;
  cliente_id?: number | null;
  nombre: string;
  nacionalidad?: string | null;
  fecha_nacimiento?: string | null;
  documentos?: Array<{ id: number; tipo_documento: string; numero_documento: string }>;
};

export type ReservaItem = {
  id: number;
  reserva_id: number;
  alternativa_item_id: number;
  fecha?: string | null;
  hora?: string | null;
  guia_id?: number | null;
  proveedor_tarifa_id?: number | null;
  guia?: Guia | null;
  proveedor_tarifa?: ProveedorTarifa | null;
  alternativa_item?: AlternativaItem;
};

export type ReservaItemPasajero = {
  id: number;
  reserva_item_id: number;
  reserva_pasajero_id: number;
  reserva_pasajero?: ReservaPasajero;
  checkin_realizado?: boolean;
  checkin_hora?: string | null;
};

export type Reserva = {
  id: number;
  alternativa_id: number;
  mayorista_elegida_id?: number | null;
  estado_reserva_mayorista?: 'pendiente' | 'confirmada' | null;
  estado: 'activa' | 'cancelada';
  fecha_cancelacion?: string | null;
  motivo_cancelacion?: MotivoCancelacion | null;
  alternativa?: Alternativa & { cotizacion?: Cotizacion };
  pasajeros?: ReservaPasajero[];
  items?: ReservaItem[];
};

export type ReservaResumenItem = {
  reserva_item_id: number;
  nombre: string;
  precio_venta_snapshot: number;
  total_convertido: number;
};

export type ReservaCabecera = {
  cliente?: { id: number; full_name: string; n_document?: string };
  destino: string;
  fecha_viaje_desde?: string | null;
  fecha_viaje_hasta?: string | null;
  codigo_cotizacion: string;
};

export type ReservaDetalleResponse = {
  code?: number;
  message?: string;
  reserva: Reserva;
  resumen: ReservaResumenItem[];
  total: number;
  moneda: 'PEN' | 'USD';
  cabecera: ReservaCabecera;
  alerta_cupo_excedido?: boolean;
};

export type Reservas = {
  total: number;
  paginate: number;
  reservas: Reserva[];
};

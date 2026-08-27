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
  descripcion?: string | null;
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
  fotos?: string[] | null;
  observaciones?: string | null;
  estado: boolean;
  tipo_id: number;
  margen_default_tipo?: 'porcentaje' | 'fijo' | null;
  margen_default_valor?: number | null;
  // Sesión 11b4a — precio de lista de la agencia cuando todavía no se sabe
  // qué empresa específica va a operar el servicio.
  es_referencial?: boolean;
  proveedor_servicios?: ProveedorServicio[];
  // Consolidación de hoteles — solo poblado si el proveedor es tipo
  // Alojamiento y tiene la fila creada (ver ProveedorController).
  alojamiento_detalle?: ProveedorAlojamientoDetalle | null;
  amenidades?: Amenidad[];
  created_at?: string;
};

// Consolidación de hoteles — catálogo central, solo lectura desde acá.
export type Amenidad = {
  id: number;
  nombre: string;
  icono: string;
  slug: string;
};

export type ProveedorAlojamientoDetalle = {
  id: number;
  proveedor_id: number;
  hora_checkin?: string | null;
  hora_checkout?: string | null;
  edad_max_infante_gratis: number;
  edad_max_nino_cama_adicional: number;
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
  // Retiro del catálogo activo (26-ago-2026) — reversible, separado de
  // vigente_desde/vigente_hasta a propósito (ver backend). Filtra
  // biblioteca() y la validación al crear un ítem nuevo; la pantalla de
  // gestión del proveedor sigue mostrando todo, con badge.
  activo: boolean;
  proveedor_servicio_id: number;
  proveedor_servicio?: ProveedorServicio;
  tipo_tarifa: 'corporativa' | 'grupal' | 'publica';
  modalidad: 'compartido' | 'privado';
  moneda: 'PEN' | 'USD';
  diferenciador?: Record<string, any> | null;
  tipo_habitacion?: 'simple' | 'matrimonial' | 'doble' | 'triple' | 'familiar' | null;
  // Consolidación de hoteles — solo aplican a tarifas de hotel
  // (tipo_habitacion no nulo).
  descripcion?: string | null;
  regimen_comida?: 'solo_alojamiento' | 'desayuno' | 'media_pension' | 'pension_completa' | null;
  tipo_cama?: string | null;
  precio_costo: number;
  margen_tipo: 'porcentaje' | 'fijo';
  margen_valor: number;
  descuento_maximo_pct?: number | null;
  margen_minimo_pct?: number | null;
  precio_venta_adulto: number;
  precio_venta_nino?: number | null;
  precio_venta_infante?: number | null;
  precio_costo_cama_adicional?: number | null;
  precio_venta_cama_adicional?: number | null;
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
  // Sesión 11b4a — mismo criterio que Proveedor.es_referencial.
  es_referencial?: boolean;
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
  // Faltaba en el tipo pese a que ya se usaba en detalle.vue desde Sesión
  // 11b2 (el backend siempre carga esta relación, ver
  // PaquetePlantillaController::show()) — corregido acá al notar que
  // Sesión 11b4b multiplicó los usos y el gap dejó de pasar desapercibido.
  guia?: Guia;
};

export type ConfiguracionAgencia = {
  // Módulo 12 (códigos y numeración) — sigla única de la agencia (ej.
  // "DKM"), usada para sugerir el prefijo de tour/paquete/cotización/
  // reserva/venta_directa. Nullable: puede no estar configurada todavía.
  sigla_comercial?: string | null;
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
  // Sesión 11i — descuento configurable por agencia (% o monto), por ítem
  // del lienzo y global del resumen del cotizador.
  permitir_descuento_item: boolean;
  modo_descuento_item: 'porcentaje' | 'monto';
  modo_descuento_global: 'porcentaje' | 'monto';
  // Sesión fix/incluye-tour — margen mínimo aceptable (%) del tab "Incluye"
  // (tour_simple), antes hardcodeado en detalle.vue.
  margen_minimo_aceptable_pct: number;
  // Sesión 11o — defaults precargados al crear un OpcionHotel nuevo
  // (cama adicional para niños), editables por hotel después.
  edad_max_infante_gratis_hotel_default: number;
  edad_max_nino_cama_adicional_hotel_default: number;
  // Sesión perfil-agencia — condiciones generales del servicio, texto
  // propio de cada agencia, descargable aparte de la parte comercial de
  // una cotización.
  condiciones_generales_servicio?: string | null;
};

// Módulo 12 — plan-modulo-codigos-numeracion.md §6.2. Una fila por tipo de
// documento (tour/paquete/cotizacion/reserva/venta_directa).
export type ConfiguracionCodigo = {
  id: number;
  tipo: 'tour' | 'paquete' | 'cotizacion' | 'reserva' | 'venta_directa';
  prefijo: string;
  deriva_de: string | null; // 'cotizacion' para reserva, null para el resto
  formato_periodo: string;
  separador: string;
  incluye_periodo: boolean;
  longitud_correlativo: number;
  reinicio_correlativo: 'nunca' | 'mensual' | 'anual';
  activo: boolean;
};

export type CuentaBancaria = {
  id: number;
  banco: string;
  titular: string;
  numero_cuenta: string;
  cci?: string | null;
  alias?: string | null;
  activo: boolean;
  orden: number;
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
  // Sesión 11b3 — solo poblado cuando el PUT incluyó descuento_global_pct.
  // Ver AlternativaController::aplicarDescuentoGlobal() en el backend.
  lineas_fuera_de_piso?: Array<{ alternativa_item_id: number; precio_minimo_permitido: number | null }>;
};

export type OrigenItem = 'proveedor' | 'mayorista' | 'pasaje_aereo' | 'manual' | 'guia';

export type AlternativaItem = {
  id: number;
  alternativa_id: number;
  origen_tipo: OrigenItem;
  proveedor_tarifa_id?: number | null;
  opcion_mayorista_id?: number | null;
  // Fix guia-como-item-real — de qué guia_tarifa vino el costo de este
  // ítem (origen_tipo=guia), ver crearItemGuia()/desdePlantilla().
  guia_tarifa_id?: number | null;
  guia_tarifa?: GuiaTarifa;
  // Sesión 11b4a — de qué tour_simple vino este ítem al explotar un
  // paquete_combo (agrupación visual, no afecta precio).
  tour_origen_id?: number | null;
  tour_origen?: PaquetePlantilla | null;
  // Sesión 11b3 — día del lienzo (cotización concreta), NO confundir con
  // TourItinerarioItem.dia_relativo (día dentro de la PLANTILLA del tour).
  dia_referencial?: number | null;
  descripcion_manual?: string | null;
  // Sesión 11q — dato interno, nunca visible al cliente: solo prellena el
  // nombre al promover este ítem manual a un proveedor real.
  proveedor_sugerido_manual?: string | null;
  // Poblado solo si el ítem ya fue promovido (ver
  // AlternativaItemController::promoverAProveedor()) — informativo, no
  // relinkea proveedor_tarifa_id ni cambia origen_tipo.
  proveedor_promovido_id?: number | null;
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

// Sesión 11b3 — respuesta de POST alternativas/{id}/items/desde-plantilla.
export type DesdePlantillaResponse = {
  code: number;
  message: string;
  items_agregados: AlternativaItem[];
  guias_pendientes: Array<{
    tour_origen_id: number;
    tour_origen_nombre: string | null;
    guia_nombre: string | null;
    destino_nombre: string | null;
  }>;
  // Sesión 11m — último día que ocupa el paquete/combo cargado, contando
  // incluso un tour-hijo sin itinerario (ocupa igual el día en que arranca).
  // Usado en el cotizador para no dejar un día "invisible" sin pestaña.
  dia_final_combo: number;
  resumen: { tours: number | null; items: number };
};

// Sesión 11b3 — GET biblioteca-cotizador (BibliotecaCotizadorController).
// tipo_resultado discrimina qué tarjeta renderizar en editar.vue.
export type BibliotecaResultado =
  | (PaquetePlantilla & { tipo_resultado: 'tour' | 'paquete'; resumen_items: { tours: number | null; items: number } })
  | (ProveedorTarifa & { tipo_resultado: 'proveedor_tarifa' });

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
  proveedor_id?: number | null;
  nombre_hotel: string;
  categoria_estrellas?: number | null;
  // Sesión 11k, Fix 9 — un hotel cotiza todas sus habitaciones en la misma
  // moneda.
  moneda: 'PEN' | 'USD';
  // Sesión 11o — cama adicional para niños: tramo (edad_max_infante_gratis,
  // edad_max_nino_cama_adicional] activa la opción de cama extra en una
  // habitación de ESTE hotel. Precargados desde configuracion_agencia al
  // crear el hotel, editables después.
  edad_max_infante_gratis: number;
  edad_max_nino_cama_adicional: number;
  opciones_hotel_tarifas?: OpcionHotelTarifa[];
};

export type OpcionHotelTarifa = {
  id: number;
  opcion_hotel_id: number;
  tipo_habitacion: 'simple' | 'matrimonial' | 'doble' | 'triple' | 'familiar';
  precio_costo: number;
  precio_venta: number;
  // Sesión 11k, Fix 9 — si está seteado, el precio es "en vivo" desde la
  // tarifa real del proveedor (ver accessor en el backend), no un valor
  // tipeado a mano.
  proveedor_tarifa_id?: number | null;
  // Sesión 11o — nullable: no toda habitación admite cama adicional.
  precio_costo_cama_adicional?: number | null;
  precio_venta_cama_adicional?: number | null;
  // Sesión 11k — solo poblado cuando el backend lo eager-carga (ver
  // CotizacionController::show()), para mostrar el nombre del hotel de un
  // ítem origen_tipo=hotel_plantilla en el lienzo.
  opcion_hotel?: OpcionHotel;
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

// Sesión 11b4a — 'tour_simple' es el comportamiento original (Sesión 6);
// 'paquete_combo' agrupa 2+ tours_simple, ver ComboPrecioCalculado/
// ComboItinerarioPaso más abajo.
export type PaquetePlantillaTipo = 'tour_simple' | 'paquete_combo';

export type PaquetePlantilla = {
  id: number;
  codigo?: string | null;
  categoria: 'local' | 'nacional' | 'internacional';
  tipo: PaquetePlantillaTipo;
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
  activo: boolean;
  descuento_tipo?: 'porcentaje' | 'monto' | null;
  descuento_valor?: number | null;
  margen_minimo_pct?: number | null;
  // Fix ajuste de redondeo (2026-08-18) — positivo o negativo (a diferencia
  // de descuento_valor, que solo resta), aplica a AMBOS tipos. null = sin
  // ajuste. Ver ComboPrecioCalculado.venta_final_combo para paquete_combo;
  // para tour_simple se aplica client-side sobre totalesIncluye (sin
  // endpoint de "precio_calculado" propio, mismo criterio ya existente).
  ajuste_redondeo?: number | null;
  items?: PaquetePlantillaItem[];
  paquete_itinerario?: TourItinerarioItem[];
  // Solo presente en index() para tipo=paquete_combo (Sesión 11b4a) — ver
  // PaquetePlantillaController::index().
  precio_calculado?: ComboPrecioCalculado;
  // withCount('items') en PaquetePlantillaController::index() — solo viene
  // en el listado, evita un GET aparte por tour para saber cuántos ítems
  // tiene (ver bibliotecaTours en paquetes/detalle.vue).
  items_count?: number;
};

export type PaquetePlantillaItem = {
  id: number;
  paquete_plantilla_id: number;
  proveedor_tarifa_id?: number | null;
  proveedor_tarifa?: ProveedorTarifa;
  guia_tarifa_id?: number | null;
  guia_tarifa?: GuiaTarifa;
  // Sesión 11b4a — tour-hijo dentro de un paquete_combo. Mutuamente
  // excluyente con proveedor_tarifa_id/guia_tarifa_id.
  paquete_plantilla_hijo_id?: number | null;
  paquete_plantilla_hijo?: PaquetePlantilla | null;
  orden?: number | null;
};

// ═══════════════════════════════════════════════════════════════
// Sesión 11b4a — paquete_combo: precio/itinerario calculados en vivo
// ═══════════════════════════════════════════════════════════════

export type ComboPrecioCalculado = {
  costo_total_combo: number;
  venta_bruta_combo: number;
  venta_neta_combo: number;
  descuento_aplicado: number;
  margen_resultante_pct: number | null;
  // Fix ajuste de redondeo (2026-08-18) — venta_final_combo es el número
  // que realmente se cobra/se suma en una cotización real
  // (desdePlantilla()): venta_neta_combo + ajuste_redondeo, aplicado
  // DESPUÉS del descuento. margen_resultante_pct arriba sigue midiéndose
  // SOLO sobre venta_neta_combo, sin este ajuste (decisión de negocio: es
  // cosmético, no una decisión de rentabilidad).
  ajuste_redondeo: number | null;
  venta_final_combo: number;
  componentes_inactivos: Array<{ id: number; nombre: string }>;
  // Sesión 11m — tour-hijo activo sin Incluye/Itinerario cargado (suma 0
  // en silencio en el precio, pero rompe la cotización más adelante).
  componentes_sin_incluye: Array<{ id: number; nombre: string }>;
  componentes_sin_itinerario: Array<{ id: number; nombre: string }>;
};

export type ComboItinerarioPaso = {
  dia_relativo: number;
  hora?: string | null;
  orden?: number | null;
  destino_atractivo_id?: number | null;
  descripcion: string;
  tour_origen_id: number;
  tour_origen_nombre: string;
};

export type ComboDatos = {
  precio_calculado: ComboPrecioCalculado;
  itinerario_derivado: ComboItinerarioPaso[];
  tours_incluidos: PaquetePlantilla[];
};

export type PaquetePlantillaResumen = {
  id: number;
  nombre: string;
  codigo?: string | null;
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
  // null para tour_simple — solo poblado para paquete_combo (Sesión 11b4a).
  combo: ComboDatos | null;
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
  vuelo_fecha_ida?: string | null;
  vuelo_hora_ida?: string | null;
  vuelo_aerolinea_vuelta?: string | null;
  vuelo_fecha_vuelta?: string | null;
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
  // Fase 1 del fix Cotización↔Reserva (2026-08-18): 'auto' = calculada por
  // la fórmula (reserva.fecha_viaje_desde + dia_referencial); 'manual' =
  // editada a mano en esta pantalla.
  fecha_origen?: 'auto' | 'manual';
  hora?: string | null;
  guia_id?: number | null;
  proveedor_tarifa_id?: number | null;
  tour_origen_id?: number | null;
  tour_origen?: PaquetePlantilla | null;
  guia?: Guia | null;
  proveedor_tarifa?: ProveedorTarifa | null;
  alternativa_item?: AlternativaItem;
  salida_operativa_id?: number | null;
  salida_operativa?: SalidaOperativa | null;
};

// feature/salida-operativa — agrupa reserva_items de DISTINTAS reservas
// que comparten tour_origen_id + fecha (modalidad='compartido'). El guía
// se asigna una vez acá, no por reserva. El proveedor de transporte NUNCA
// se centraliza, sigue en reserva_items.proveedor_tarifa_id.
export type SalidaOperativa = {
  id: number;
  tour_origen_id: number | null;
  tour_nombre?: string | null;
  fecha: string;
  hora?: string | null;
  guia_id: number | null;
  guia?: Guia | null;
  cupo_maximo?: number | null;
  vehiculo_descripcion?: string | null;
  estado: 'activa' | 'cancelada';
  notas?: string | null;
  total_pax?: number;
  total_reservas?: number;
  reservas?: Array<{ id: number; codigo: string | null; cliente: string | null; total_pax: number }>;
};

export type SalidasOperativasResponse = {
  total: number;
  salidas: SalidaOperativa[];
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
  // Módulo 12 (códigos y numeración) — derivado del código de la
  // cotización padre. Nullable: reservas creadas antes del módulo se
  // quedan sin código retroactivo (fallback a alternativa.cotizacion.codigo
  // en las pantallas que lo muestran).
  codigo?: string | null;
  alternativa_id: number;
  mayorista_elegida_id?: number | null;
  estado_reserva_mayorista?: 'pendiente' | 'confirmada' | null;
  estado: 'activa' | 'cancelada';
  // Fase 1 del fix Cotización↔Reserva (2026-08-18): fecha propia de la
  // reserva, copiada de la cotización una sola vez al aceptar la
  // alternativa — NO es un espejo en vivo de `alternativa.cotizacion`
  // (esa sigue siendo la propuesta comercial, editable libremente). Usar
  // siempre estos dos campos (o `ReservaCabecera`) para mostrar "la fecha
  // de la reserva", nunca `alternativa.cotizacion.fecha_viaje_desde/hasta`.
  fecha_viaje_desde?: string | null;
  fecha_viaje_hasta?: string | null;
  fecha_cancelacion?: string | null;
  motivo_cancelacion?: MotivoCancelacion | null;
  // Facturación externa por tenant + por reserva (PEGAR-EN-CLAUDE-CODE-
  // facturacion-externa-tenant.md, 2026-08-20): override por reserva,
  // independiente del flag del tenant — editable solo mientras la reserva
  // no tenga ninguna venta asociada (ver facturacion_externa_editable en
  // ReservaDetalleResponse).
  facturacion_externa?: boolean;
  referencia_externa?: string | null;
  fecha_facturacion_externa?: string | null;
  alternativa?: Alternativa & { cotizacion?: Cotizacion };
  pasajeros?: ReservaPasajero[];
  items?: ReservaItem[];
};

export type ReservaResumenItem = {
  reserva_item_id: number;
  nombre: string;
  // Fecha del servicio (día del itinerario) — sin esto, dos ítems del
  // mismo servicio en días distintos se ven como filas idénticas.
  fecha?: string | null;
  precio_venta_snapshot: number;
  total_convertido: number;
};

// Tier 0 — conexión Adelantos↔Reservas (hallazgo de auditoría del módulo
// Adelantos, 2026-08-21): anticipos ya pagados hacia esta reserva.
export type AnticipoReserva = {
  id: number;
  advance_id: number;
  monto: number;
  disponible: number;
  moneda: 'PEN' | 'USD';
  fecha_asignacion: string | null;
  comprobante_enviado: boolean;
};

export type ReservaCabecera = {
  // cod_tipo_doc_sunat (Catálogo 06 SUNAT: '6' = RUC) — el backend ya
  // serializa el Client completo, este campo solo lo hace visible al
  // tipado para poder deshabilitar "Factura" cuando el cliente no tiene RUC.
  cliente?: { id: number; full_name: string; n_document?: string; cod_tipo_doc_sunat?: string };
  destino: string;
  // Fase 1 del fix Cotización↔Reserva (2026-08-18): ya NO es un espejo de
  // Cotizacion.fecha_viaje_desde/hasta — son las fechas propias de la
  // reserva (Reserva.fecha_viaje_desde/hasta), congeladas al aceptar.
  // cliente/destino/codigo_cotizacion sí siguen siendo de la cotización.
  fecha_viaje_desde?: string | null;
  fecha_viaje_hasta?: string | null;
  codigo_cotizacion: string;
  // Módulo 12 — código propio de la reserva (con fallback al de la
  // cotización si la reserva es previa al módulo). Usar este, no
  // codigo_cotizacion, para mostrar "la reserva N".
  codigo: string;
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
  items_pendientes_sincronizar?: Array<{ id: number; nombre: string }>;
  // Fase A — facturación de reservas: reserva_item_ids ya cubiertos por
  // alguna venta, para no volver a ofrecerlos al facturar.
  items_facturados_ids?: number[];
  // Facturación múltiple por grupo de pasajeros (2026-08-20): pasajero_ids
  // ya cubiertos por ALGUNA ReservaVenta — usado para el badge
  // "Facturación completa" vs. "Falta facturar a N pasajeros".
  pasajeros_facturados_ids?: number[];
  // Cuántos reserva_items reales de la reserva NO están cubiertos todavía
  // por ninguna ReservaVenta (hallazgo 2026-08-24: "Facturación completa"
  // solo miraba pasajeros — un ítem compartido o "sin asignar" podía
  // quedar pendiente para siempre con el badge en verde).
  items_pendientes_de_facturar_count?: number;
  // Facturación externa por tenant (PEGAR-EN-CLAUDE-CODE-facturacion-externa-
  // tenant.md): flag del TENANT (no de la reserva) — controla si se ofrecen
  // los botones "Facturar"/"Facturación especial". El backend igual bloquea
  // con 403 si se intenta directo.
  facturacion_habilitada_tenant?: boolean;
  // Editable solo mientras la reserva no tenga ninguna ReservaVenta.
  facturacion_externa_editable?: boolean;
  // Tier 0 — conexión Adelantos↔Reservas.
  anticipos?: AnticipoReserva[];
  total_anticipos_disponibles?: number;
};

export type Reservas = {
  total: number;
  paginate: number;
  reservas: Reserva[];
};

// Sesión 11d — GET /reporte-operativo (backend real de Sesión 11e). Una fila por
// pasajero × reserva_item, ya agregada por fecha en el backend.
export type ReporteOperativoFila = {
  reserva_id: number;
  reserva_item_id: number;
  checkin_realizado: boolean;
  checkin_hora: string | null;
  codigo_reserva?: string | null;
  pasajero: {
    id: number;
    nombre?: string | null;
    documento?: string | null;
    tipo_pax?: string | null;
    alimentacion_especial?: string | null;
    discapacidad?: string | null;
  };
  vuelo_ida: { aerolinea: string; fecha: string | null; hora: string | null } | null;
  vuelo_vuelta: { aerolinea: string; fecha: string | null; hora: string | null } | null;
  origen_tipo?: OrigenItem | null;
  servicio?: string | null;
  servicio_id?: number | null;
  destino?: string | null;
  // Nombre del proveedor asignado a CUALQUIER ítem origen_tipo='proveedor' (no solo
  // hoteles, a diferencia de `hotel` abajo) — necesario para reasignar inline.
  proveedor?: string | null;
  hotel?: string | null;
  fecha: string | null;
  hora: string | null;
  guia: { id: number; nombre: string; es_referencial: boolean } | null;
  sin_guia: boolean;
  vinculo_especifico: boolean;
  // Si está seteado, el guía mostrado arriba viene de la Salida Operativa (compartida
  // con otras reservas) — el reporte NO debe permitir editarlo directamente acá, mismo
  // criterio que reservas/detalle.vue.
  salida_operativa_id?: number | null;
  salida_vehiculo?: string | null;
};

export type ReporteOperativoResponse = {
  code: number;
  fecha_desde: string;
  fecha_hasta: string;
  total_items: number;
  total_sin_guia: number;
  filas: ReporteOperativoFila[];
};

// Sesión 11d (mejoras) — GET /reporte-operativo/filtros. Catálogo de opciones para los
// 4 selects nuevos, acotado al rango de fecha vigente (no un catálogo global).
export type ReporteOperativoFiltrosDisponibles = {
  code: number;
  destinos: { id: number; nombre: string }[];
  servicios: { id: number; nombre: string }[];
  tours: { id: number; nombre: string; codigo: string | null }[];
  hoteles: { id: number; nombre: string }[];
};

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
  proveedor_tarifas?: ProveedorTarifa[];
};

export type ProveedorTarifa = {
  id: number;
  proveedor_servicio_id: number;
  tipo_tarifa: 'corporativa' | 'grupal' | 'publica';
  modalidad: 'compartido' | 'privado';
  moneda: 'PEN' | 'USD';
  diferenciador?: Record<string, any> | null;
  tipo_habitacion?: 'matrimonial' | 'doble' | 'triple' | 'familiar' | null;
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

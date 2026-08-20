// Interfaces derivadas 1:1 de los shapes reales confirmados por curl contra el backend
// (Fase D, Paso 2 — plan-panel-superadmin.md tiene el detalle completo endpoint por
// endpoint). No inventar campos nuevos acá sin volver a confirmar contra el backend.

// Mismos 2 valores que TenantProvisioningService::GIROS_VALIDOS (backend) — sin
// endpoint de catálogo dedicado, se mantiene sincronizado a mano (mismo criterio ya
// aceptado en otros selectores fijos de este panel, ej. tabs de TenantDetailView).
export type Giro = 'retail' | 'agencia_viajes';

// GET tenants/{id} — metadata del Tenant (central), NO es lo mismo que Company.
export interface TenantOverview {
  id: string;
  ruc: string;
  razon_social: string;
  razon_social_comercial: string;
  giro: Giro;
  tipo: string;
  sunat_modo: string;
  // Facturación externa por tenant (PEGAR-EN-CLAUDE-CODE-facturacion-externa-
  // tenant.md) — true = factura en esta plataforma, false = "solo operativo"
  // (factura afuera), null = sin decidir todavía (tratado como falsy por el
  // backend). Editable en cualquier momento vía updateFacturacionHabilitada().
  facturacion_habilitada: boolean | null;
  status: string;
  fecha_archivado: string | null;
  fecha_alta: string;
  domains: string[];
}

// GET/POST tenants/{id}/company. La fila real trae más columnas (config de Caja/
// Amortizaciones agregada en fases anteriores: mora_habilitada_default, etc.) que esta
// interfaz no declara — no hace falta, TS no exige que el objeto recibido tenga
// exactamente estos campos y ninguno más, solo que los que se usan estén tipados.
export interface Company {
  id: number;
  razon_social: string;
  razon_social_comercial: string;
  n_document: string;
  urbanizacion: string;
  cod_local: string;
  phone: string | null;
  email: string | null;
  birth_date: string | null;
  address: string | null;
  ubigeo_distrito: string | null;
  ubigeo_provincia: string | null;
  ubigeo_region: string | null;
  distrito: string | null;
  provincia: string | null;
  region: string | null;
  created_at: string;
  updated_at: string;
}

// Payload editable del formulario de Company — mismos campos que valida el backend.
export interface CompanyPayload {
  razon_social: string;
  razon_social_comercial: string;
  n_document: string;
  urbanizacion: string;
  cod_local: string;
  phone?: string | null;
  email?: string | null;
  birth_date?: string | null;
  address?: string | null;
  ubigeo_distrito?: string | null;
  ubigeo_provincia?: string | null;
  ubigeo_region?: string | null;
  distrito?: string | null;
  provincia?: string | null;
  region?: string | null;
}

export interface Plan {
  id: number;
  nombre: string;
  limite_usuarios: number | null;
  limite_comprobantes_mes: number | null;
  limite_storage_mb: number | null;
  precio_mensual: string;
  activo: boolean;
  created_at: string;
  updated_at: string;
}

// Payload de POST/PUT tenants/{id}/subscription — mismos campos que valida
// TenantSubscriptionController::validatedSubscription().
export interface SubscriptionPayload {
  tenant_plan_id: number;
  monto_mensual_override?: number | null;
  dia_corte?: number | null;
  dias_gracia_suspension?: number | null;
  facturacion_automatica?: boolean;
}

export interface Subscription {
  id: number;
  tenant_id: string;
  tenant_plan_id: number;
  monto_mensual_override: string | null;
  dia_corte: number;
  dias_gracia_suspension: number | null;
  facturacion_automatica: boolean;
  estado: string;
  created_at: string;
  updated_at: string;
  plan: Plan;
}

export type VoucherEstado = 'pendiente_verificacion' | 'verificado' | 'rechazado';

export interface Voucher {
  id: number;
  tenant_invoice_id: number;
  path: string;
  estado: VoucherEstado;
  motivo_rechazo: string | null;
  created_at: string;
  updated_at: string;
}

export interface Invoice {
  id: number;
  tenant_subscription_id: number;
  tenant_id: string;
  periodo: string;
  folio_interno: string;
  monto: string;
  fecha_vencimiento: string;
  estado: string;
  fecha_pago: string | null;
  sunat_document_id: number | null;
  aviso_gracia_enviado_at: string | null;
  created_at: string;
  updated_at: string;
  vouchers: Voucher[];
}

export interface Backup {
  id: number;
  tenant_id: string;
  path: string | null;
  size_bytes: number | null;
  tipo: string;
  estado: string;
  error_message: string | null;
  created_at: string;
  updated_at: string;
  integridad_verificada: boolean;
  verificado_at: string | null;
}

// Paginador crudo de Laravel (sin envoltorio propio) — GET tenants/{id}/backups.
export interface BackupsPage {
  current_page: number;
  data: Backup[];
  first_page_url: string | null;
  from: number | null;
  last_page: number;
  last_page_url: string | null;
  links: { url: string | null; label: string; active: boolean }[];
  next_page_url: string | null;
  path: string;
  per_page: number;
  prev_page_url: string | null;
  to: number | null;
  total: number;
}

export interface RestorePreview {
  restore: {
    id: number;
    confirm_token: string;
    confirm_token_expires_at: string;
  };
  backup: {
    id: number;
    tipo: string;
    size_bytes: number | null;
    created_at: string;
  };
}

export interface RestoreRecord {
  id: number;
  tenant_id: string;
  backup_id: number;
  pre_restore_backup_id: number | null;
  estado: string;
  confirm_token: string;
  confirm_token_expires_at: string;
  confirmed_at: string | null;
  error_message: string | null;
  created_at: string;
  updated_at: string;
}

export interface SunatConfig {
  id: number;
  ruc: string | null;
  razon_social_sunat: string | null;
  modo: 'beta' | 'produccion';
  sol_usuario: string | null;
  cuenta_bancaria_detraccion: string | null;
  certificado_cargado: boolean;
  certificado_valido: boolean;
  certificado_fecha_vencimiento: string | null;
  activo: boolean;
}

export interface SunatConfigPayload {
  ruc?: string | null;
  razon_social_sunat?: string | null;
  modo: 'beta' | 'produccion';
  sol_usuario: string;
  sol_clave: string;
  cuenta_bancaria_detraccion?: string | null;
}

export interface TestEmissionResult {
  company: {
    id: number;
    razon_social: string;
    n_document: string;
  };
  modo: string;
  certificado: {
    cargado: boolean;
    propio_o_demo: 'propio' | 'demo';
    valido: boolean | null;
    fecha_vencimiento: string | null;
  };
}

// Shape real confirmado por curl contra GET central/audit-logs (nuevo endpoint, esta
// sesión) — ver plan-panel-superadmin.md, sección Audit Logs, para el detalle completo.

export interface AuditLog {
  id: number;
  central_user_id: number | null;
  action: string;
  auditable_type: string;
  auditable_id: string;
  payload: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string;
  updated_at: string | null; // el modelo nunca lo setea (const UPDATED_AT = null), pero la columna existe y se serializa igual
  central_user: { id: number; name: string; email: string } | null;
}

// Paginador crudo de Laravel — mismo shape que BackupsPage (tenant-detail.ts), pero
// declarado aparte para no acoplar tipos de dominios distintos.
export interface AuditLogsPage {
  current_page: number;
  data: AuditLog[];
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

// Auditable types reales usados hoy (confirmado por grep de los `use` en cada archivo
// que llama a AuditLogger::log()) — el filtro de "tenant" del selector arma
// auditable_type=App\Models\Tenant junto al auditable_id elegido.
export const AUDITABLE_TYPE_TENANT = 'App\\Models\\Tenant';

// 28 acciones reales (25 confirmadas por grep en la sesión de Audit Logs + 3 agregadas
// en la sesión de archivar/restaurar/eliminar tenant) — lista cerrada para el selector,
// no inventar ninguna nueva acá sin confirmar contra el backend real.
export const AUDIT_LOG_ACTIONS = [
  'tenant.created',
  'tenant.archived',
  'tenant.restored',
  'tenant.deleted',
  'tenant.company.updated',
  'tenant.sunat_config.updated',
  'tenant.certificado.uploaded',
  'tenant.test_emission',
  'tenant.invoice.generated',
  'tenant.invoice.paid_manually',
  'tenant.invoice.voucher_uploaded',
  'tenant.invoice.voucher_verified',
  'tenant.invoice.voucher_rejected',
  'tenant.invoice.overdue_reminder_sent',
  'tenant.invoice.grace_midpoint_notified',
  'tenant.subscription.suspended_manually',
  'tenant.subscription.suspended_for_nonpayment',
  'tenant.subscription.reactivated_manually',
  'tenant.subscription.reactivated_automatically',
  'tenant.subscription.automatic_billing_toggled',
  'tenant.backup.created',
  'tenant.backup.failed',
  'tenant.backup.integrity_checked',
  'tenant.backup.pruned',
  'tenant.restore.preview_requested',
  'tenant.restore.confirmed',
  'tenant.restore.completed',
  'tenant.restore.failed',
] as const;

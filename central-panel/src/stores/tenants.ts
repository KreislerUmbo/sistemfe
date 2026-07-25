import { defineStore } from 'pinia';
import { reactive, ref } from 'vue';
import httpClient from '@/services/httpClient';
import type {
  BackupsPage,
  Invoice,
  RestorePreview,
  RestoreRecord,
  Subscription,
  SubscriptionPayload,
  SunatConfig,
  SunatConfigPayload,
  TenantOverview,
  TestEmissionResult,
} from '@/types/tenant-detail';

// Shape real de GET central/tenants (TenantAdminController::index()/serialize()),
// confirmado contra el backend real corriendo en local — no asumido. La
// paginación del backend es un Eloquent paginate(15) pero el serialize() propio
// del controller solo devuelve total/paginate (tamaño de página fijo), sin
// current_page/last_page/links — con los 4 tenants reales de hoy entra todo en
// una sola página, así que no hace falta UI de paginación todavía. Si el conteo
// pasa de 15 en el futuro, el backend necesitaría exponer la página actual para
// poder paginar de verdad (anotado en el plan, no bloqueante hoy).
export interface Tenant {
  id: string;
  ruc: string;
  razon_social: string;
  razon_social_comercial: string;
  status: string;
  fecha_archivado: string | null;
  fecha_alta: string;
  domains: string[];
}

// Payload de POST central/tenants — mismos campos que valida
// TenantAdminController::store() (todos required, admin_password min:8).
export interface CreateTenantPayload {
  ruc: string;
  razon_social: string;
  razon_social_comercial: string;
  domain: string;
  admin_name: string;
  admin_email: string;
  admin_password: string;
}

export const useTenantsStore = defineStore('tenants', () => {
  const tenants = ref<Tenant[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);

  // Cache básico en memoria: no se pierde el conteo mientras el usuario navega
  // entre vistas dentro de la misma sesión de navegador. Nada más elaborado
  // hasta que haga falta (primera vista real, mantenerlo mínimo).
  let fetched = false;

  const fetchTenants = async (force = false) => {
    if (fetched && !force) return;

    loading.value = true;
    error.value = null;

    try {
      const { data } = await httpClient.get('central/tenants');
      tenants.value = data.tenants;
      total.value = data.total;
      fetched = true;
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'No se pudo cargar el listado de tenants.';
    } finally {
      loading.value = false;
    }
  };

  // Alta de tenant — POST central/tenants (TenantAdminController::store(), ya existía
  // desde Fase A, nunca tenía UI). Dispara CreateDatabase + MigrateDatabase de forma
  // SÍNCRONA del lado del backend (TenantProvisioningService) — puede tardar varios
  // segundos, el componente debe reflejar eso en el loading, no asumir que es instantáneo.
  const creating = ref(false);
  const createError = ref<string | null>(null);

  const createTenant = async (payload: CreateTenantPayload): Promise<boolean> => {
    creating.value = true;
    createError.value = null;

    try {
      await httpClient.post('central/tenants', payload);
      await fetchTenants(true);
      return true;
    } catch (e: any) {
      createError.value = e.response?.data?.message ?? 'No se pudo crear el tenant.';
      return false;
    } finally {
      creating.value = false;
    }
  };

  // "Archivado, no borrado" — bloquea login/API del tenant, conserva su base/storage
  // intactos (retención legal SUNAT). Reversible en cualquier momento (restoreTenant).
  const archiving = ref(false);
  const archiveError = ref<string | null>(null);

  const archiveTenant = async (id: string): Promise<boolean> => {
    archiving.value = true;
    archiveError.value = null;

    try {
      await httpClient.post(`central/tenants/${id}/archive`);
      await fetchTenants(true);
      return true;
    } catch (e: any) {
      archiveError.value = e.response?.data?.message ?? 'No se pudo archivar el tenant.';
      return false;
    } finally {
      archiving.value = false;
    }
  };

  const restoreTenant = async (id: string): Promise<boolean> => {
    archiving.value = true;
    archiveError.value = null;

    try {
      await httpClient.post(`central/tenants/${id}/restore`);
      await fetchTenants(true);
      return true;
    } catch (e: any) {
      archiveError.value = e.response?.data?.message ?? 'No se pudo restaurar el tenant.';
      return false;
    } finally {
      archiving.value = false;
    }
  };

  // Eliminación real — deliberadamente estrecha (TenantAdminController::destroy(), ver
  // TenantProvisioningService::eliminarSiVacio()): el backend rechaza con 422 si el
  // tenant ya tiene Company/SunatConfig/clientes/productos/ventas — pensado solo para
  // "me equivoqué al crearlo", nunca como alternativa a archivar.
  const deleting = ref(false);
  const deleteError = ref<string | null>(null);

  const deleteTenant = async (id: string): Promise<boolean> => {
    deleting.value = true;
    deleteError.value = null;

    try {
      await httpClient.delete(`central/tenants/${id}`);
      await fetchTenants(true);
      return true;
    } catch (e: any) {
      deleteError.value = e.response?.data?.message ?? 'No se pudo eliminar el tenant.';
      return false;
    } finally {
      deleting.value = false;
    }
  };

  // ── Fase D, Paso 2 — estado/acciones de la vista de detalle de tenant ──────────
  // Cada grupo (subscription/backups/sunatConfig/certificado/testEmission) tiene su
  // propio loading/error — fetches independientes, decisión ya tomada (no un
  // Promise.all combinado). El overview (GET tenants/{id}) y Company (GET/POST
  // tenants/{id}/company) NO viven acá — fetch propio del componente, mismo
  // criterio ya decidido para no mezclar el store del listado con datos de detalle
  // que no todos los tabs necesitan.
  //
  // Todas las acciones de escritura de un mismo grupo comparten un solo
  // actionLoading/actionError (ej. suspender/reactivar/generar invoice/marcar
  // pagado/subir-verificar-rechazar voucher, todos bajo `subscription`) — este panel
  // lo opera un solo admin a la vez, clickeando de a un botón por vez; separar un
  // loading/error por cada una de las 8 acciones sería sobre-diseño sin beneficio
  // real hoy.

  const subscription = reactive({
    data: null as Subscription | null,
    invoices: [] as Invoice[],
    loading: false,
    error: null as string | null,
    actionLoading: false,
    actionError: null as string | null,
  });

  const fetchSubscription = async (tenantId: string) => {
    subscription.loading = true;
    subscription.error = null;

    try {
      const { data } = await httpClient.get(`central/tenants/${tenantId}/subscription`);
      subscription.data = data.subscription;
      subscription.invoices = data.invoices;
    } catch (e: any) {
      subscription.error = e.response?.data?.message ?? 'No se pudo cargar la suscripción.';
    } finally {
      subscription.loading = false;
    }
  };

  // suspender/reactivar devuelven el Tenant actualizado — el componente lo emite
  // hacia TenantDetailView para refrescar el encabezado (overview vive ahí, no acá).
  const suspendTenant = async (tenantId: string, motivo: string): Promise<TenantOverview | null> => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      const { data } = await httpClient.post(`central/tenants/${tenantId}/suspend`, { motivo });
      return data.tenant as TenantOverview;
    } catch (e: any) {
      subscription.actionError = e.response?.data?.message ?? 'No se pudo suspender el tenant.';
      return null;
    } finally {
      subscription.actionLoading = false;
    }
  };

  const reactivateTenant = async (tenantId: string): Promise<TenantOverview | null> => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      const { data } = await httpClient.post(`central/tenants/${tenantId}/reactivate`);
      return data.tenant as TenantOverview;
    } catch (e: any) {
      subscription.actionError = e.response?.data?.message ?? 'No se pudo reactivar el tenant.';
      return null;
    } finally {
      subscription.actionLoading = false;
    }
  };

  // Fase B.2.6 — crear la suscripción de un tenant que todavía no tiene ninguna (asignarle
  // un plan por primera vez). Hasta esta fase no había forma de hacer esto vía UI/API.
  const createSubscription = async (tenantId: string, payload: SubscriptionPayload): Promise<boolean> => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      await httpClient.post(`central/tenants/${tenantId}/subscription`, payload);
      await fetchSubscription(tenantId);
      return true;
    } catch (e: any) {
      subscription.actionError = e.response?.data?.message ?? 'No se pudo crear la suscripción.';
      return false;
    } finally {
      subscription.actionLoading = false;
    }
  };

  // Cambiar el plan (u otros datos) de la suscripción activa ya existente.
  const updateSubscription = async (tenantId: string, payload: SubscriptionPayload): Promise<boolean> => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      await httpClient.put(`central/tenants/${tenantId}/subscription`, payload);
      await fetchSubscription(tenantId);
      return true;
    } catch (e: any) {
      subscription.actionError = e.response?.data?.message ?? 'No se pudo actualizar la suscripción.';
      return false;
    } finally {
      subscription.actionLoading = false;
    }
  };

  const toggleAutomaticBilling = async (tenantId: string) => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      await httpClient.post(`central/tenants/${tenantId}/subscription/toggle-automatic`);
      await fetchSubscription(tenantId);
    } catch (e: any) {
      subscription.actionError =
        e.response?.data?.message ?? 'No se pudo cambiar la facturación automática.';
    } finally {
      subscription.actionLoading = false;
    }
  };

  const generateInvoice = async (tenantId: string, periodo?: string) => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      await httpClient.post(`central/tenants/${tenantId}/invoices`, periodo ? { periodo } : {});
      await fetchSubscription(tenantId);
    } catch (e: any) {
      subscription.actionError = e.response?.data?.message ?? 'No se pudo generar el invoice.';
    } finally {
      subscription.actionLoading = false;
    }
  };

  const markInvoicePaid = async (tenantId: string, invoiceId: number) => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      await httpClient.post(`central/tenants/${tenantId}/invoices/${invoiceId}/mark-paid`);
      await fetchSubscription(tenantId);
    } catch (e: any) {
      subscription.actionError =
        e.response?.data?.message ?? 'No se pudo marcar el invoice como pagado.';
    } finally {
      subscription.actionLoading = false;
    }
  };

  const uploadVoucher = async (tenantId: string, invoiceId: number, file: File) => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      const formData = new FormData();
      formData.append('voucher', file);
      await httpClient.post(`central/tenants/${tenantId}/invoices/${invoiceId}/vouchers`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      await fetchSubscription(tenantId);
    } catch (e: any) {
      subscription.actionError = e.response?.data?.message ?? 'No se pudo subir el voucher.';
    } finally {
      subscription.actionLoading = false;
    }
  };

  // Re-fetch completo tras verificar: verificarVoucher() dispara marcarPagado() en el
  // invoice del lado del backend, pero la respuesta de este endpoint solo trae el
  // voucher — el invoice.estado actualizado solo se ve refrescando la suscripción
  // entera (ver auditoría de esta fase, plan-panel-superadmin.md).
  const verifyVoucher = async (tenantId: string, invoiceId: number, voucherId: number) => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      await httpClient.post(
        `central/tenants/${tenantId}/invoices/${invoiceId}/vouchers/${voucherId}/verify`,
      );
      await fetchSubscription(tenantId);
    } catch (e: any) {
      subscription.actionError = e.response?.data?.message ?? 'No se pudo verificar el voucher.';
    } finally {
      subscription.actionLoading = false;
    }
  };

  const rejectVoucher = async (
    tenantId: string,
    invoiceId: number,
    voucherId: number,
    motivo: string,
  ) => {
    subscription.actionLoading = true;
    subscription.actionError = null;

    try {
      await httpClient.post(
        `central/tenants/${tenantId}/invoices/${invoiceId}/vouchers/${voucherId}/reject`,
        { motivo },
      );
      await fetchSubscription(tenantId);
    } catch (e: any) {
      subscription.actionError = e.response?.data?.message ?? 'No se pudo rechazar el voucher.';
    } finally {
      subscription.actionLoading = false;
    }
  };

  const backups = reactive({
    page: null as BackupsPage | null,
    loading: false,
    error: null as string | null,
    actionLoading: false,
    actionError: null as string | null,
  });

  const fetchBackups = async (tenantId: string, page = 1) => {
    backups.loading = true;
    backups.error = null;

    try {
      const { data } = await httpClient.get(`central/tenants/${tenantId}/backups`, {
        params: { page },
      });
      backups.page = data as BackupsPage;
    } catch (e: any) {
      backups.error = e.response?.data?.message ?? 'No se pudo cargar el listado de backups.';
    } finally {
      backups.loading = false;
    }
  };

  const createBackup = async (tenantId: string) => {
    backups.actionLoading = true;
    backups.actionError = null;

    try {
      await httpClient.post(`central/tenants/${tenantId}/backups`);
      await fetchBackups(tenantId, backups.page?.current_page ?? 1);
    } catch (e: any) {
      backups.actionError = e.response?.data?.message ?? 'No se pudo crear el backup.';
    } finally {
      backups.actionLoading = false;
    }
  };

  const verifyBackup = async (tenantId: string, backupId: number) => {
    backups.actionLoading = true;
    backups.actionError = null;

    try {
      await httpClient.post(`central/tenants/${tenantId}/backups/${backupId}/verify`);
      await fetchBackups(tenantId, backups.page?.current_page ?? 1);
    } catch (e: any) {
      backups.actionError = e.response?.data?.message ?? 'No se pudo re-verificar el backup.';
    } finally {
      backups.actionLoading = false;
    }
  };

  // Devuelve el preview al componente (no se guarda en el store) — el componente
  // arma el modal de confirmación con el confirm_token/expiración/resumen del backup.
  const previewRestore = async (
    tenantId: string,
    backupId: number,
  ): Promise<RestorePreview | null> => {
    backups.actionLoading = true;
    backups.actionError = null;

    try {
      const { data } = await httpClient.post(
        `central/tenants/${tenantId}/backups/${backupId}/restore-preview`,
      );
      return data as RestorePreview;
    } catch (e: any) {
      backups.actionError = e.response?.data?.message ?? 'No se pudo iniciar la restauración.';
      return null;
    } finally {
      backups.actionLoading = false;
    }
  };

  const confirmRestore = async (
    tenantId: string,
    confirmToken: string,
  ): Promise<RestoreRecord | null> => {
    backups.actionLoading = true;
    backups.actionError = null;

    try {
      const { data } = await httpClient.post(
        `central/tenants/${tenantId}/restores/${confirmToken}/confirm`,
      );
      await fetchBackups(tenantId, backups.page?.current_page ?? 1);
      return data.restore as RestoreRecord;
    } catch (e: any) {
      backups.actionError = e.response?.data?.message ?? 'La restauración falló.';
      return null;
    } finally {
      backups.actionLoading = false;
    }
  };

  const sunatConfig = reactive({
    data: null as SunatConfig | null,
    loading: false,
    error: null as string | null,
    saving: false,
    saveError: null as string | null,
  });

  const fetchSunatConfig = async (tenantId: string) => {
    sunatConfig.loading = true;
    sunatConfig.error = null;

    try {
      const { data } = await httpClient.get(`central/tenants/${tenantId}/sunat-config`);
      sunatConfig.data = data.sunat_config;
    } catch (e: any) {
      sunatConfig.error =
        e.response?.data?.message ?? 'No se pudo cargar la configuración SUNAT.';
    } finally {
      sunatConfig.loading = false;
    }
  };

  const saveSunatConfig = async (tenantId: string, payload: SunatConfigPayload) => {
    sunatConfig.saving = true;
    sunatConfig.saveError = null;

    try {
      const { data } = await httpClient.post(`central/tenants/${tenantId}/sunat-config`, payload);
      sunatConfig.data = data.sunat_config;
      return true;
    } catch (e: any) {
      sunatConfig.saveError =
        e.response?.data?.message ?? 'No se pudo guardar la configuración SUNAT.';
      return false;
    } finally {
      sunatConfig.saving = false;
    }
  };

  const certificado = reactive({
    uploading: false,
    error: null as string | null,
  });

  // Actualiza sunatConfig.data con la respuesta — mismo slice que consume el tab
  // SunatConfig (certificado_cargado/certificado_valido/certificado_fecha_vencimiento),
  // sin duplicar el fetch.
  const uploadCertificado = async (tenantId: string, file: File, password: string) => {
    certificado.uploading = true;
    certificado.error = null;

    try {
      const formData = new FormData();
      formData.append('certificado', file);
      formData.append('certificado_password', password);
      const { data } = await httpClient.post(
        `central/tenants/${tenantId}/sunat-config/certificado`,
        formData,
        { headers: { 'Content-Type': 'multipart/form-data' } },
      );
      sunatConfig.data = data.sunat_config;
      return true;
    } catch (e: any) {
      certificado.error = e.response?.data?.message ?? 'No se pudo subir el certificado.';
      return false;
    } finally {
      certificado.uploading = false;
    }
  };

  const testEmission = reactive({
    result: null as TestEmissionResult | null,
    loading: false,
    error: null as string | null,
  });

  const runTestEmission = async (tenantId: string) => {
    testEmission.loading = true;
    testEmission.error = null;
    testEmission.result = null;

    try {
      const { data } = await httpClient.post(`central/tenants/${tenantId}/test-emission`);
      testEmission.result = data.test_emission;
    } catch (e: any) {
      testEmission.error = e.response?.data?.message ?? 'No se pudo probar la emisión.';
    } finally {
      testEmission.loading = false;
    }
  };

  return {
    tenants,
    total,
    loading,
    error,
    fetchTenants,
    creating,
    createError,
    createTenant,
    archiving,
    archiveError,
    archiveTenant,
    restoreTenant,
    deleting,
    deleteError,
    deleteTenant,

    subscription,
    fetchSubscription,
    createSubscription,
    updateSubscription,
    suspendTenant,
    reactivateTenant,
    toggleAutomaticBilling,
    generateInvoice,
    markInvoicePaid,
    uploadVoucher,
    verifyVoucher,
    rejectVoucher,

    backups,
    fetchBackups,
    createBackup,
    verifyBackup,
    previewRestore,
    confirmRestore,

    sunatConfig,
    fetchSunatConfig,
    saveSunatConfig,

    certificado,
    uploadCertificado,

    testEmission,
    runTestEmission,
  };
});

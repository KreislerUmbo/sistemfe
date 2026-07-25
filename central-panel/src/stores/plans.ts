import { defineStore } from 'pinia';
import { ref } from 'vue';
import httpClient from '@/services/httpClient';
import type { Plan } from '@/types/tenant-detail';

// CRUD del catálogo de planes de suscripción (tenant_plans, conexión 'central') — Fase
// B.2.6 (plan-panel-superadmin.md). Payload de POST/PUT central/tenant-plans, mismos
// campos que valida TenantPlanController::validated().
export interface PlanPayload {
  nombre: string;
  limite_usuarios: number | null;
  limite_comprobantes_mes: number | null;
  limite_storage_mb: number | null;
  precio_mensual: number;
  activo: boolean;
}

export const usePlansStore = defineStore('plans', () => {
  const plans = ref<Plan[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  let fetched = false;

  const fetchPlans = async (force = false) => {
    if (fetched && !force) return;

    loading.value = true;
    error.value = null;

    try {
      const { data } = await httpClient.get('central/tenant-plans');
      plans.value = data.tenant_plans;
      fetched = true;
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'No se pudo cargar el catálogo de planes.';
    } finally {
      loading.value = false;
    }
  };

  const saving = ref(false);
  const saveError = ref<string | null>(null);

  const createPlan = async (payload: PlanPayload): Promise<boolean> => {
    saving.value = true;
    saveError.value = null;

    try {
      await httpClient.post('central/tenant-plans', payload);
      await fetchPlans(true);
      return true;
    } catch (e: any) {
      saveError.value = e.response?.data?.message ?? 'No se pudo crear el plan.';
      return false;
    } finally {
      saving.value = false;
    }
  };

  const updatePlan = async (id: number, payload: PlanPayload): Promise<boolean> => {
    saving.value = true;
    saveError.value = null;

    try {
      await httpClient.put(`central/tenant-plans/${id}`, payload);
      await fetchPlans(true);
      return true;
    } catch (e: any) {
      saveError.value = e.response?.data?.message ?? 'No se pudo actualizar el plan.';
      return false;
    } finally {
      saving.value = false;
    }
  };

  // No es borrado real — el backend solo pone activo=false (ver TenantPlanController).
  const deactivating = ref(false);
  const deactivateError = ref<string | null>(null);

  const deactivatePlan = async (id: number): Promise<boolean> => {
    deactivating.value = true;
    deactivateError.value = null;

    try {
      await httpClient.delete(`central/tenant-plans/${id}`);
      await fetchPlans(true);
      return true;
    } catch (e: any) {
      deactivateError.value = e.response?.data?.message ?? 'No se pudo desactivar el plan.';
      return false;
    } finally {
      deactivating.value = false;
    }
  };

  return {
    plans,
    loading,
    error,
    fetchPlans,
    saving,
    saveError,
    createPlan,
    updatePlan,
    deactivating,
    deactivateError,
    deactivatePlan,
  };
});

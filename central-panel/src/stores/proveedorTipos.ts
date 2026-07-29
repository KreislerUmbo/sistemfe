import { defineStore } from 'pinia';
import { ref } from 'vue';
import httpClient from '@/services/httpClient';
import type { ProveedorTipo } from '@/types/proveedor-tipo';

// CRUD del catálogo central de tipos de proveedor (vertical Agencia de Viajes) — mismo
// patrón que stores/plans.ts. Payload de POST/PUT central/proveedor-tipos: solo `nombre`
// y `activo` — el slug lo deriva el backend, nunca se manda desde acá.
export interface ProveedorTipoPayload {
  nombre: string;
  activo: boolean;
}

export const useProveedorTiposStore = defineStore('proveedorTipos', () => {
  const tipos = ref<ProveedorTipo[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  let fetched = false;

  const fetchTipos = async (force = false) => {
    if (fetched && !force) return;

    loading.value = true;
    error.value = null;

    try {
      const { data } = await httpClient.get('central/proveedor-tipos');
      tipos.value = data.proveedor_tipos;
      fetched = true;
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'No se pudo cargar el catálogo de tipos de proveedor.';
    } finally {
      loading.value = false;
    }
  };

  const saving = ref(false);
  const saveError = ref<string | null>(null);

  const createTipo = async (payload: ProveedorTipoPayload): Promise<boolean> => {
    saving.value = true;
    saveError.value = null;

    try {
      await httpClient.post('central/proveedor-tipos', payload);
      await fetchTipos(true);
      return true;
    } catch (e: any) {
      saveError.value = e.response?.data?.message ?? 'No se pudo crear el tipo de proveedor.';
      return false;
    } finally {
      saving.value = false;
    }
  };

  const updateTipo = async (id: number, payload: ProveedorTipoPayload): Promise<boolean> => {
    saving.value = true;
    saveError.value = null;

    try {
      await httpClient.put(`central/proveedor-tipos/${id}`, payload);
      await fetchTipos(true);
      return true;
    } catch (e: any) {
      saveError.value = e.response?.data?.message ?? 'No se pudo actualizar el tipo de proveedor.';
      return false;
    } finally {
      saving.value = false;
    }
  };

  // No es borrado real — el backend solo pone activo=false (ver ProveedorTipoController).
  const deactivating = ref(false);
  const deactivateError = ref<string | null>(null);

  const deactivateTipo = async (id: number): Promise<boolean> => {
    deactivating.value = true;
    deactivateError.value = null;

    try {
      await httpClient.delete(`central/proveedor-tipos/${id}`);
      await fetchTipos(true);
      return true;
    } catch (e: any) {
      deactivateError.value = e.response?.data?.message ?? 'No se pudo desactivar el tipo de proveedor.';
      return false;
    } finally {
      deactivating.value = false;
    }
  };

  return {
    tipos,
    loading,
    error,
    fetchTipos,
    saving,
    saveError,
    createTipo,
    updateTipo,
    deactivating,
    deactivateError,
    deactivateTipo,
  };
});

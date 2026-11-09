import { defineStore } from 'pinia';
import { ref } from 'vue';
import httpClient from '@/services/httpClient';
import type { Aerolinea } from '@/types/aerolinea';

// Catálogo central de aerolíneas (T-015) — mismo patrón que stores/proveedorTipos.ts. No hay
// alta desde el panel: las agrega cada agencia; acá solo se editan, desactivan y unen.
export interface AerolineaPayload {
  nombre: string;
  codigo_iata: string | null;
  activa: boolean;
}

export const useAerolineasStore = defineStore('aerolineas', () => {
  const aerolineas = ref<Aerolinea[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  const fetchAerolineas = async () => {
    loading.value = true;
    error.value = null;

    try {
      const { data } = await httpClient.get('central/aerolineas');
      aerolineas.value = data.aerolineas;
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'No se pudo cargar el catálogo de aerolíneas.';
    } finally {
      loading.value = false;
    }
  };

  // Guardar (editar), desactivar y unir comparten estado de "trabajando" y de error.
  const working = ref(false);
  const actionError = ref<string | null>(null);

  const run = async (fn: () => Promise<unknown>, fallback: string): Promise<boolean> => {
    working.value = true;
    actionError.value = null;

    try {
      await fn();
      await fetchAerolineas();
      return true;
    } catch (e: any) {
      const errores = e.response?.data?.errors;
      actionError.value =
        (errores && (Object.values(errores)[0] as string[])?.[0]) ?? e.response?.data?.message ?? fallback;
      return false;
    } finally {
      working.value = false;
    }
  };

  const updateAerolinea = (id: number, payload: AerolineaPayload) =>
    run(() => httpClient.put(`central/aerolineas/${id}`, payload), 'No se pudo actualizar la aerolínea.');

  // No es borrado real — el backend solo pone activa=false.
  const deactivateAerolinea = (id: number) =>
    run(() => httpClient.delete(`central/aerolineas/${id}`), 'No se pudo desactivar la aerolínea.');

  const uneAerolinea = (id: number, destinoId: number) =>
    run(
      () => httpClient.post(`central/aerolineas/${id}/unir`, { destino_id: destinoId }),
      'No se pudo unir la aerolínea.',
    );

  return {
    aerolineas,
    loading,
    error,
    fetchAerolineas,
    working,
    actionError,
    updateAerolinea,
    deactivateAerolinea,
    uneAerolinea,
  };
});

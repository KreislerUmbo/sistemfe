// Cambiar la afectación de una línea desde la columna «Afectación» de los
// modales "Facturar" y "Facturación especial" (T-091, D-AGE-037). Sin motivo
// ni destino: el backend deriva el destino del código. Después se vuelve a
// pedir la vista previa (alGuardar) para refrescar los totales sin cerrar el
// modal. Lo reusará Venta directa (T-048).
import { ref } from 'vue';
import { useToast } from '@/composables/useToast';
import { reservaService } from '@/services/admin/reservaService';
import type { ReservaDetalleResponse } from '@/types/agencia-viajes';

export function useAfectacionFactura(
    reservaId: () => number,
    // Con la reserva actualizada si se guardó; null si falló.
    alGuardar: (respuesta: ReservaDetalleResponse | null) => Promise<void> | void,
) {
    const toast = useToast();
    const guardando = ref(false);
    // Sube con cada intento: la columna se vuelve a dibujar con lo que de
    // verdad quedó guardado (si falla, la lista no se queda con lo elegido).
    const version = ref(0);

    const cambiar = async (reservaItemIds: number[], tipAfeIgv: '10' | '20' | '30') => {
        if (guardando.value) return;
        guardando.value = true;
        let respuesta: ReservaDetalleResponse | null = null;
        try {
            respuesta = await reservaService.overrideTratamientoTributario(reservaId(), {
                reserva_item_ids: reservaItemIds,
                tip_afe_igv: tipAfeIgv,
            });
        } catch (error: any) {
            toast.error(error.response?.data?.message ?? 'No se pudo cambiar la afectación');
        }
        try {
            await alGuardar(respuesta);
        } finally {
            version.value++;
            guardando.value = false;
        }
    };

    return { guardando, version, cambiar };
}

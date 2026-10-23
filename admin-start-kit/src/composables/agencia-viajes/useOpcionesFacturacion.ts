// Sucursal y moneda del comprobante al facturar una reserva (modales
// "Facturar" y "Facturación especial"). Salió de reservas/detalle.vue
// (auditoría reserva + facturación, punto 5, 23-oct-2026): antes los dos
// modales compartían un solo juego de variables y una función adivinaba cuál
// estaba abierto para refrescar su vista previa. Ahora cada modal crea el
// suyo y pasa qué hacer cuando algo cambia (alCambiar).
//
// - Sucursal (2026-09-24): solo con permiso can_switch_branch, igual que
//   sale/register.vue; la serie la resuelve el backend por
//   sucursal + tipo + moneda (SerieComprobanteService).
// - Moneda (2026-09-24): por defecto la de la cotización; otra moneda exige
//   tipo de cambio y motivo (ReservaFacturacionController::
//   resolverMonedaFacturacion()). El tipo de cambio sugerido de SUNAT se
//   propone, nunca se aplica sin que el vendedor lo vea.
import { computed, reactive, ref, watch, type Ref } from 'vue';
import httpClient from '@/helpers/http-client';
import { useAuthStore } from '@/stores/auth';
import type { Branch } from '@/types/cash-session';

type Moneda = 'PEN' | 'USD';

export function useOpcionesFacturacion(monedaCotizacion: Ref<Moneda>, alCambiar: () => void) {
    const authStore = useAuthStore();
    const puedeCambiarSucursal = computed(() => authStore.isPermitedRoute('can_switch_branch'));
    const sucursales = ref<Branch[]>([]);
    const branchId = ref<number | undefined>(undefined);

    const moneda = ref<Moneda>(monedaCotizacion.value);
    const tipoCambio = ref<number | null>(null);
    const motivo = ref('');
    const requiereOverride = computed(() => moneda.value !== monedaCotizacion.value);

    const cargarSucursales = async () => {
        if (!puedeCambiarSucursal.value || sucursales.value.length > 0) return;
        try {
            const res = await httpClient.get<{ branches: Branch[] }>('branches?active=1');
            sucursales.value = res.data.branches;
            branchId.value = sucursales.value[0]?.id;
        } catch {
            // Sin sucursales el selector queda vacío y el backend usa la
            // sucursal propia del usuario (branch_id no viaja).
        }
    };

    const resetear = () => {
        moneda.value = monedaCotizacion.value;
        tipoCambio.value = null;
        motivo.value = '';
    };

    // Prellena el tipo de cambio con el sugerido de SUNAT, solo si el vendedor
    // todavía no escribió uno.
    const proponerTipoCambio = (sugerido: number | null | undefined) => {
        if (requiereOverride.value && tipoCambio.value === null && sugerido) {
            tipoCambio.value = sugerido;
        }
    };

    // Mensaje de error, o null si se puede emitir.
    const validar = (): string | null =>
        requiereOverride.value && (!tipoCambio.value || !motivo.value.trim())
            ? 'Indica el tipo de cambio y el motivo para facturar en una moneda distinta a la de la cotización.'
            : null;

    // Para prepararFactura().
    const opcionesPreview = (tipoComprobanteCodigo: '01' | '03') => ({
        monedaFacturacion: moneda.value,
        tipoCambioConversion: tipoCambio.value,
        branchId: branchId.value,
        tipoComprobanteCodigo,
    });

    // Para facturar(): solo manda lo que cambia respecto de lo normal.
    const payload = () => ({
        branch_id: puedeCambiarSucursal.value ? branchId.value : undefined,
        moneda_facturacion: requiereOverride.value ? moneda.value : undefined,
        tipo_cambio_conversion: requiereOverride.value ? tipoCambio.value : undefined,
        motivo_cambio_moneda: requiereOverride.value ? motivo.value.trim() : undefined,
    });

    // Moneda y sucursal son una sola acción: refrescan al toque. El tipo de
    // cambio es un input que cambia con cada tecla: espera 500 ms (bug real:
    // "me refresca cada vez que escribo un número").
    watch([moneda, branchId], alCambiar);
    let espera: ReturnType<typeof setTimeout> | undefined;
    watch(tipoCambio, () => {
        clearTimeout(espera);
        espera = setTimeout(alCambiar, 500);
    });

    // reactive(): en la plantilla y en el script se usa opciones.moneda, sin .value.
    return reactive({
        puedeCambiarSucursal, sucursales, branchId, moneda, tipoCambio, motivo, requiereOverride,
        cargarSucursales, resetear, proponerTipoCambio, validar, opcionesPreview, payload,
    });
}

export type OpcionesFacturacion = ReturnType<typeof useOpcionesFacturacion>;

import { computed, ref, type ComputedRef } from 'vue';
import type { AlternativaItem } from '@/types/agencia-viajes';

/**
 * Días del lienzo (dentro del destino activo): pestañas, día activo e ítems por día.
 *
 * diaActivo = 0 es «Sin día»: ítems con dia_referencial = null. No es un día real y nunca va al
 * backend (reasignarDia y desde-plantilla exigen min:1); para agregar se usa diaActivoParaAgregar.
 */
export function useDiasLienzo(destinos: { itemsDelDestinoActivo: ComputedRef<AlternativaItem[]> }) {
    const { itemsDelDestinoActivo } = destinos;

    const diaActivo = ref(1);
    const diasCreados = ref<number[]>([1]);

    // Días que vinieron de un combo cargado desde plantilla: si alguno queda sin ítems (tour-hijo
    // sin Incluye) se avisa distinto que un día agregado a mano.
    const diasDesdeCombo = ref<Set<number>>(new Set());

    // dia_referencial se numera por destino: solo cuentan los ítems del destino activo.
    const inicializarDias = () => {
        const items = itemsDelDestinoActivo.value;
        const maxDia = items.reduce((max, it) => Math.max(max, it.dia_referencial ?? 0), 1);
        diasCreados.value = Array.from({ length: maxDia }, (_, i) => i + 1);
        if (!diasCreados.value.includes(diaActivo.value) && diaActivo.value !== 0) {
            diaActivo.value = diasCreados.value[0] ?? 1;
        }
    };

    const diaSiguiente = computed(() => (diasCreados.value.length ? Math.max(...diasCreados.value) : 0) + 1);
    const diaActivoParaAgregar = computed(() => (diaActivo.value === 0 ? (diasCreados.value[0] ?? 1) : diaActivo.value));

    // diaSiguiente se lee una sola vez: tras el push ya vale el día de después.
    const agregarDia = () => {
        const nuevoDia = diaSiguiente.value;
        diasCreados.value.push(nuevoDia);
        diaActivo.value = nuevoDia;
    };

    const itemsSinDia = computed(() => itemsDelDestinoActivo.value.filter((i) => i.dia_referencial == null));

    // Con un solo día y sin ítems «sin día», las pestañas no aportan nada y se ocultan.
    const mostrarTabsDia = computed(() => diasCreados.value.length > 1 || itemsSinDia.value.length > 0);

    const itemsDelDiaActivo = computed(() => {
        const items = itemsDelDestinoActivo.value;
        return diaActivo.value === 0
            ? items.filter((i) => i.dia_referencial == null)
            : items.filter((i) => i.dia_referencial === diaActivo.value);
    });

    // Sin pestañas, el lienzo muestra todos los ítems del destino.
    const itemsVisiblesLienzo = computed(() => (mostrarTabsDia.value ? itemsDelDiaActivo.value : itemsDelDestinoActivo.value));

    return {
        diaActivo,
        diasCreados,
        diasDesdeCombo,
        inicializarDias,
        diaSiguiente,
        diaActivoParaAgregar,
        agregarDia,
        itemsSinDia,
        mostrarTabsDia,
        itemsDelDiaActivo,
        itemsVisiblesLienzo,
    };
}

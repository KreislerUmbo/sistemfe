<template>
    <div class="d-flex flex-wrap align-items-center gap-2 small">
        <span class="text-secondary fw-semibold">{{ etiqueta }}:</span>
        <template v-if="precio === null">
            <span class="text-muted fst-italic">sin precio</span>
        </template>
        <template v-else-if="costo === null">
            <span class="text-muted fst-italic">sin costo estimado — falta completar el Incluye o escribir el costo</span>
        </template>
        <template v-else>
            <span class="badge" :class="claseBadge">
                Margen {{ moneda }} {{ margen!.toFixed(2) }}
                <template v-if="margenPct !== null"> · {{ margenPct.toFixed(1) }}%</template>
            </span>
            <span class="text-muted">precio {{ moneda }} {{ precio.toFixed(2) }} − costo {{ moneda }} {{ costo.toFixed(2) }}</span>
            <span v-if="piso !== null" class="text-muted">
                · mínimo {{ piso.toFixed(1) }}% ({{ margenMinimoTour !== null ? 'del tour' : 'de la agencia' }})
                <i v-if="bajoMinimo" class="fas fa-exclamation-triangle text-danger ms-1" title="Debajo del margen mínimo"></i>
            </span>
        </template>
    </div>
</template>

<script setup lang="ts">
// Fila 13, fase 1a (plan-tours-venta-simple-costo-real.md §3.1, §3.9b):
// margen estimado del tour en vivo, S/ y % SOBRE EL COSTO (mismo criterio que
// ComboValidationService::validarMargenMinimo() y el ejemplo del plan: 150
// contra 120 = 25 %). El piso es el margen mínimo del tour o, si no tiene,
// el de la agencia (configuracion_agencia.margen_minimo_aceptable_pct).
import { computed } from 'vue';

const props = defineProps<{
    precio: number | null;
    costo: number | null;
    moneda: string;
    margenMinimoTour: number | null;
    margenMinimoAgencia: number | null;
    etiqueta: string;
}>();

const margen = computed(() => (props.precio !== null && props.costo !== null ? props.precio - props.costo : null));
const margenPct = computed(() => (margen.value !== null && props.costo ? (margen.value / props.costo) * 100 : null));
const piso = computed(() => props.margenMinimoTour ?? props.margenMinimoAgencia ?? null);
const bajoMinimo = computed(() => margenPct.value !== null && piso.value !== null && margenPct.value < piso.value - 0.005);
const claseBadge = computed(() => {
    if (margen.value !== null && margen.value < 0) return 'bg-danger-subtle text-danger border border-danger-subtle';
    if (bajoMinimo.value) return 'bg-warning-subtle text-warning border border-warning-subtle';
    return 'bg-success-subtle text-success border border-success-subtle';
});
</script>

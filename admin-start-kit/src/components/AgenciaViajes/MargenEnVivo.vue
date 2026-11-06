<template>
    <div class="d-flex flex-wrap align-items-center gap-2 small">
        <span v-if="etiqueta" class="text-secondary fw-semibold">{{ etiqueta }}:</span>
        <template v-if="precio === null">
            <span class="text-muted fst-italic">sin precio</span>
        </template>
        <template v-else-if="costo === null">
            <span class="text-muted fst-italic">sin costo estimado — falta completar el Incluye o escribir el costo</span>
        </template>
        <template v-else>
            <span class="badge" :class="claseBadge">
                Markup {{ formatPct(markup) }} · Margen {{ formatPct(margenSobreVenta) }} · Ganancia {{ moneda }} {{ formatMonto(gan) }}
            </span>
            <span v-if="detalle" class="text-muted">precio {{ moneda }} {{ precio.toFixed(2) }} − costo {{ moneda }} {{ costo.toFixed(2) }}</span>
            <span v-if="piso !== null && verMinimo" class="text-muted">
                · markup mínimo {{ piso.toFixed(1) }}% ({{ textoOrigenMinimo }})
                <i v-if="bajoMinimo" class="fas fa-exclamation-triangle text-danger ms-1" title="Debajo del markup mínimo"></i>
            </span>
            <!-- Modo tabla (verMinimo=false): sin el texto del mínimo en cada fila, solo el ícono con el dato en el title. -->
            <i v-else-if="bajoMinimo" class="fas fa-exclamation-triangle text-danger"
                :title="`Debajo del markup mínimo (${piso!.toFixed(1)}% ${textoOrigenMinimo})`"></i>
        </template>
    </div>
</template>

<script setup lang="ts">
// T-087: muestra siempre Markup · Margen · Ganancia (helper utils/margenes.ts).
// Markup = sobre el costo; Margen = sobre la venta. El aviso de "debajo del
// mínimo" compara contra el MARKUP (el mínimo configurado es un markup). El piso
// es el mínimo del tour o, si no tiene, el de la agencia
// (configuracion_agencia.margen_minimo_aceptable_pct); sin piso no hay aviso.
import { computed } from 'vue';
import { formatMonto, formatPct, ganancia, margenPct, markupPct } from '@/utils/margenes';

const props = withDefaults(defineProps<{
    precio: number | null;
    costo: number | null;
    moneda: string;
    margenMinimoTour?: number | null;
    margenMinimoAgencia?: number | null;
    etiqueta?: string;
    detalle?: boolean;
    // false = no escribir "· markup mínimo X %" (tabla); el aviso queda como ícono.
    verMinimo?: boolean;
    // Cómo se nombra el mínimo propio: "del tour" o "del combo".
    origenMinimo?: string;
}>(), { margenMinimoTour: null, margenMinimoAgencia: null, etiqueta: '', detalle: true, verMinimo: true, origenMinimo: 'del tour' });

const textoOrigenMinimo = computed(() => (props.margenMinimoTour !== null ? props.origenMinimo : 'de la agencia'));

const gan = computed(() => ganancia(props.costo, props.precio));
const markup = computed(() => markupPct(props.costo, props.precio));
const margenSobreVenta = computed(() => margenPct(props.costo, props.precio));
const piso = computed(() => props.margenMinimoTour ?? props.margenMinimoAgencia ?? null);
const bajoMinimo = computed(() => markup.value !== null && piso.value !== null && markup.value < piso.value - 0.005);
const claseBadge = computed(() => {
    if (gan.value !== null && gan.value < 0) return 'bg-danger-subtle text-danger border border-danger-subtle';
    if (bajoMinimo.value) return 'bg-warning-subtle text-warning border border-warning-subtle';
    return 'bg-success-subtle text-success border border-success-subtle';
});
</script>

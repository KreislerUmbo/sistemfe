<template>
    <div class="d-flex flex-column gap-2">
        <div class="d-flex flex-column gap-1 small">
            <div v-if="filas.length === 0" class="text-muted">Sin productos</div>
            <div v-for="f in filas" :key="f.etiqueta" class="d-flex justify-content-between" :class="f.clase">
                <span :class="f.clase ? '' : 'text-muted'">{{ f.etiqueta }}</span>
                <span class="text-nowrap">{{ f.valor }}</span>
            </div>
        </div>

        <button v-if="puedeDescuentoGlobal && !verDescuento && !(descuentoGlobal > 0)" type="button" class="btn btn-link btn-sm p-0 align-self-start"
            @click="verDescuento = true">+ Descuento global</button>
        <div v-else-if="puedeDescuentoGlobal">
            <label class="form-label small fw-semibold text-secondary mb-1" for="tv-desc">Descuento global (sobre el total)</label>
            <div class="input-group input-group-sm" style="max-width:200px">
                <span class="input-group-text">{{ simbolo }}</span>
                <input id="tv-desc" type="number" class="form-control" min="0" step="0.10" placeholder="0.00"
                    :value="descuentoGlobal || ''" @change="cambiar($event)" />
            </div>
        </div>

        <div class="total-box d-flex justify-content-between align-items-baseline rounded px-3 py-2">
            <span class="small text-uppercase opacity-75">{{ etiquetaTotal }}</span>
            <b class="fs-2">{{ simbolo }} {{ total.toFixed(2) }}</b>
        </div>
    </div>
</template>

<script setup lang="ts">
// Totales de la venta (T-106): solo las filas que aplican, descuento global y el total grande.
// Recibe los números ya calculados por register.vue; no calcula nada.
import { ref } from 'vue';

withDefaults(defineProps<{
    filas: { etiqueta: string; valor: string; clase?: string }[];
    total: number;
    simbolo: string;
    etiquetaTotal?: string;
    /** Permiso cambiar_precio_venta (T-109): sin él no hay descuento global; el backend también lo rechaza. */
    puedeDescuentoGlobal?: boolean;
}>(), { puedeDescuentoGlobal: true });

const descuentoGlobal = defineModel<number>('descuentoGlobal', { default: 0 });
const verDescuento = ref(false);
const cambiar = (e: Event) => {
    descuentoGlobal.value = Math.max(0, Number((e.target as HTMLInputElement).value) || 0);
};
</script>

<style scoped>
.total-box { background: #0f2a1d; color: #fff; }
</style>

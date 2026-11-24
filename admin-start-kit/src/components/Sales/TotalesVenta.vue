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
                <input id="tv-desc" type="number" class="form-control" min="0" :max="maximo" step="0.10" placeholder="0.00"
                    :class="{ 'is-invalid': excede }" :value="descuentoGlobal || ''" @change="cambiar($event)" />
            </div>
            <div v-if="aviso || excede" class="small text-danger mt-1">
                {{ aviso || `El descuento no puede ser mayor que el subtotal (${simbolo} ${(maximo ?? 0).toFixed(2)}).` }}
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
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{
    filas: { etiqueta: string; valor: string; clase?: string }[];
    total: number;
    simbolo: string;
    etiquetaTotal?: string;
    /** Permiso cambiar_precio_venta (T-109): sin él no hay descuento global; el backend también lo rechaza. */
    puedeDescuentoGlobal?: boolean;
    /** Subtotal de las líneas (base de cobro): el descuento global no puede pasarlo; el backend también lo rechaza (T-120). */
    maximo?: number;
}>(), { puedeDescuentoGlobal: true });

const descuentoGlobal = defineModel<number>('descuentoGlobal', { default: 0 });
const verDescuento = ref(false);
const aviso = ref('');
// También cubre quitar líneas después de escribir el descuento.
const excede = computed(() => props.maximo !== undefined && descuentoGlobal.value > props.maximo + 0.005);
const cambiar = (e: Event) => {
    const input = e.target as HTMLInputElement;
    let valor = Math.max(0, Number(input.value) || 0);
    aviso.value = '';
    if (props.maximo !== undefined && valor > props.maximo + 0.005) {
        const tope = Math.max(0, Math.floor(props.maximo * 100) / 100);
        aviso.value = `El descuento no puede ser mayor que el subtotal (${props.simbolo} ${tope.toFixed(2)}): se ajustó.`;
        valor = tope;
        input.value = valor ? String(valor) : '';
    }
    descuentoGlobal.value = valor;
};
</script>

<style scoped>
.total-box { background: #0f2a1d; color: #fff; }
</style>

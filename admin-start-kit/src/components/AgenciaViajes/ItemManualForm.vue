<template>
    <div class="item-manual-form">
        <div class="mb-2">
            <label class="form-label mb-1 small fw-semibold text-secondary">Descripción</label>
            <input type="text" class="form-control form-control-sm" v-model="descripcion"
                placeholder="Ej. Cobro de última hora, proveedor ocasional...">
        </div>
        <div class="row g-2 mb-2">
            <div class="col-8">
                <label class="form-label mb-1 small fw-semibold text-secondary">Precio</label>
                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="precio">
            </div>
            <div class="col-4">
                <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                <select class="form-select form-select-sm" v-model="moneda">
                    <option value="PEN">PEN</option>
                    <option value="USD">USD</option>
                </select>
            </div>
        </div>
        <small class="text-muted d-block mb-2">
            <i class="fas fa-info-circle me-1"></i>Sin validación de piso — no hay tarifa de proveedor de la que derivarlo.
        </small>
        <button class="btn btn-primary btn-sm w-100" @click="agregar" :disabled="!descripcion.trim() || !precio">
            <i class="fas fa-plus me-1"></i>Agregar ítem manual
        </button>
    </div>
</template>

<script setup lang="ts">
// Ítem sin proveedor registrado — Sesión 11b, plan-modulo-cotizaciones-reservas.md
// §3. Sin restricción de rol, sin piso de descuento.
import { ref } from 'vue';
import { alternativaItemService } from '@/services/admin/alternativaItemService';

const props = defineProps<{ alternativaId: number; diaActivo: number }>();
const emit = defineEmits<{ (e: 'agregado', payload: any): void }>();

const descripcion = ref('');
const precio = ref<number | null>(null);
const moneda = ref<'PEN' | 'USD'>('PEN');

const agregar = async () => {
    try {
        const res = await alternativaItemService.agregarManual(props.alternativaId, {
            descripcion_manual: descripcion.value,
            precio_venta_snapshot: precio.value,
            moneda_costo: moneda.value,
            dia_referencial: props.diaActivo,
        });
        emit('agregado', res.alternativa_item);
        descripcion.value = '';
        precio.value = null;
    } catch (error) {
        console.log(error);
    }
};
</script>

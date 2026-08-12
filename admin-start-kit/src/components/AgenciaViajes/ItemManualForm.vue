<template>
    <div class="item-manual-form">
        <div class="mb-2">
            <label class="form-label mb-1 small fw-semibold text-secondary">Descripción</label>
            <input type="text" class="form-control form-control-sm" v-model="descripcion"
                placeholder="Ej. Cobro de última hora, proveedor ocasional...">
        </div>
        <div class="mb-2">
            <label class="form-label mb-1 small fw-semibold text-secondary">Proveedor / contacto <span class="text-muted fw-normal">(opcional)</span></label>
            <input type="text" class="form-control form-control-sm" v-model="proveedorSugerido"
                placeholder="Nombre del proveedor u operador">
        </div>

        <div v-if="pasajeros && pasajeros.length" class="mb-2">
            <span class="small text-secondary d-block mb-1">¿A quiénes aplica?</span>
            <div class="form-check form-check-inline small" v-for="p in pasajeros" :key="p.id">
                <input class="form-check-input" type="checkbox" :id="`item-manual-pax-${p.id}`" :value="p.id" v-model="paxSeleccionados">
                <label class="form-check-label" :for="`item-manual-pax-${p.id}`">{{ p.tipo_pax }} ({{ p.edad }} años)</label>
            </div>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">Costo</label>
                <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="costo">
            </div>
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">Cantidad</label>
                <input type="number" min="1" class="form-control form-control-sm" v-model.number="cantidad">
            </div>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-8">
                <label class="form-label mb-1 small fw-semibold text-secondary">Precio unitario</label>
                <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="precio">
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
        <button class="btn btn-primary btn-sm w-100" @click="agregar" :disabled="!puedeGuardar || guardando">
            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
            <i v-else class="fas fa-plus me-1"></i>{{ props.itemExistente ? 'Guardar cambios' : 'Agregar ítem manual' }}
        </button>
    </div>
</template>

<script setup lang="ts">
// Ítem sin proveedor registrado — Sesión 11b, plan-modulo-cotizaciones-reservas.md
// §3. Sesión 11q: costo/cantidad/pax_incluidos dejaron de ser sentinels sin
// efecto (ver AlternativaItem::getTotalAttribute() en el backend) y se
// agregó edición estructural completa (itemExistente) + "promover a
// proveedor" (ver PromoverProveedorModal.vue, separado de este form).
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import type { AlternativaItem, CotizacionPasajero } from '@/types/agencia-viajes';

const props = defineProps<{
    alternativaId: number;
    diaActivo: number;
    pasajeros?: CotizacionPasajero[];
    itemExistente?: AlternativaItem | null;
}>();
const emit = defineEmits<{
    (e: 'agregado', payload: any): void;
    (e: 'actualizado', payload: any): void;
}>();

const descripcion = ref('');
const proveedorSugerido = ref('');
const costo = ref<number | null>(null);
const precio = ref<number | null>(null);
const moneda = ref<'PEN' | 'USD'>('PEN');
const cantidad = ref<number>(1);
const paxSeleccionados = ref<number[]>([]);
const guardando = ref(false);

// Sesión 11q — cantidad se autocompleta con la cantidad de pax tildados,
// tanto al armar un ítem nuevo como al editar uno existente; el vendedor
// puede pisarla a mano después de tildar (el watcher solo la sugiere, no la
// bloquea). flush:'sync' es necesario para que resetearCampos() pueda
// sobreescribir cantidad DESPUÉS de fijar paxSeleccionados en modo edición
// (ver más abajo) sin que este watcher la vuelva a pisar de forma asíncrona.
watch(paxSeleccionados, () => {
    cantidad.value = paxSeleccionados.value.length || 1;
}, { flush: 'sync' });

const resetearCampos = () => {
    const item = props.itemExistente;

    if (item) {
        descripcion.value = item.descripcion_manual ?? '';
        proveedorSugerido.value = item.proveedor_sugerido_manual ?? '';
        costo.value = Number(item.costo_snapshot ?? 0);
        precio.value = Number(item.precio_venta_snapshot);
        moneda.value = item.moneda_costo;
        paxSeleccionados.value = item.pax_incluidos && item.pax_incluidos.length
            ? [...item.pax_incluidos]
            : (props.pasajeros ?? []).map((p) => p.id);
        cantidad.value = item.cantidad; // pisa lo que el watcher de arriba ya calculó
    } else {
        descripcion.value = '';
        proveedorSugerido.value = '';
        costo.value = null;
        precio.value = null;
        moneda.value = 'PEN';
        paxSeleccionados.value = (props.pasajeros ?? []).map((p) => p.id);
        cantidad.value = paxSeleccionados.value.length || 1;
    }
};

watch(() => props.itemExistente, resetearCampos, { immediate: true });

const puedeGuardar = computed(() => !!descripcion.value.trim() && costo.value !== null && !!precio.value && cantidad.value > 0);

const agregar = async () => {
    if (!puedeGuardar.value) return;
    guardando.value = true;
    try {
        const totalPax = props.pasajeros?.length ?? 0;
        const payload = {
            descripcion_manual: descripcion.value,
            proveedor_sugerido_manual: proveedorSugerido.value.trim() || undefined,
            costo_snapshot: costo.value,
            precio_venta_snapshot: precio.value,
            moneda_costo: moneda.value,
            cantidad: cantidad.value,
            pax_incluidos: paxSeleccionados.value.length && paxSeleccionados.value.length < totalPax ? paxSeleccionados.value : null,
            dia_referencial: props.diaActivo,
        };

        if (props.itemExistente) {
            const res = await alternativaItemService.actualizarManual(props.itemExistente.id, payload);
            emit('actualizado', res.alternativa_item);
        } else {
            const res = await alternativaItemService.agregarManual(props.alternativaId, payload);
            emit('agregado', res.alternativa_item);
            resetearCampos();
        }
    } catch (error: any) {
        Swal.fire('Error', error.response?.data?.message ?? 'No se pudo guardar el ítem manual', 'error');
    } finally {
        guardando.value = false;
    }
};
</script>

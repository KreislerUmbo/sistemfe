<template>
    <div class="pasaje-aereo-form">
        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">Aerolínea</label>
                <input type="text" class="form-control form-control-sm" v-model="form.aerolinea" placeholder="Ej. LATAM">
            </div>
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                <select class="form-select form-select-sm" v-model="form.moneda">
                    <option value="PEN">Soles</option>
                    <option value="USD">Dólares</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label mb-1 small fw-semibold text-secondary">Itinerario</label>
                <textarea class="form-control form-control-sm" rows="2" v-model="form.itinerario"
                    placeholder="Tramos ida/vuelta, fechas, horas, equipaje..."></textarea>
            </div>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-4">
                <label class="form-label mb-1 small fw-semibold text-secondary">Tarifa base adulto</label>
                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="form.tarifa_base_adulto">
            </div>
            <div class="col-4">
                <label class="form-label mb-1 small fw-semibold text-secondary">Tarifa base niño</label>
                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="form.tarifa_base_nino">
            </div>
            <div class="col-4">
                <label class="form-label mb-1 small fw-semibold text-secondary">Tarifa base infante</label>
                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="form.tarifa_base_infante">
            </div>
        </div>

        <label class="form-label mb-1 small fw-semibold text-secondary d-block">
            Cargos (impuestos, tasas, TUA...) <span class="text-muted fw-normal">— monto ya totalizado para el grupo</span>
        </label>
        <div v-for="(cargo, idx) in form.cargos" :key="idx" class="row g-1 mb-1 align-items-center">
            <div class="col-5">
                <input type="text" class="form-control form-control-sm" v-model="cargo.nombre" placeholder="Nombre (ej. TUA internacional)">
            </div>
            <div class="col-3">
                <select class="form-select form-select-sm" v-model="cargo.tipo">
                    <option value="impuesto">Impuesto</option>
                    <option value="tasa_aeropuerto">Tasa aeropuerto</option>
                    <option value="fee_agencia">Fee agencia</option>
                </select>
            </div>
            <div class="col-3">
                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="cargo.monto">
            </div>
            <div class="col-1">
                <button class="btn btn-sm btn-outline-danger" type="button" @click="quitarCargo(idx)"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <button class="btn btn-sm btn-outline-secondary mb-2" type="button" @click="agregarCargo">
            <i class="fas fa-plus me-1"></i>Agregar cargo
        </button>

        <div class="row g-2 mb-2 align-items-end">
            <div class="col-6">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="tua-incluida" v-model="form.tua_incluida_en_tarifa">
                    <label class="form-check-label small" for="tua-incluida">TUA incluida en la tarifa</label>
                </div>
            </div>
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">Fee de agencia</label>
                <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="form.fee_agencia_monto">
            </div>
        </div>

        <div class="card bg-light border-0 p-2 mb-2">
            <div class="d-flex justify-content-between small">
                <span class="text-muted">Costo total</span>
                <span v-if="calculando" class="spinner-border spinner-border-sm"></span>
                <strong v-else>{{ form.moneda }} {{ (preview?.costo_total ?? 0).toFixed(2) }}</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-muted small">Precio de venta</span>
                <strong>{{ form.moneda }} {{ (preview?.venta_total ?? 0).toFixed(2) }}</strong>
            </div>
        </div>

        <button class="btn btn-primary btn-sm w-100" @click="agregar" :disabled="!form.aerolinea || !form.tarifa_base_adulto">
            <i class="fas fa-plus me-1"></i>Agregar pasaje aéreo
        </button>
    </div>
</template>

<script setup lang="ts">
// Ítem de pasaje aéreo suelto — Sesión 11b, plan-modulo-cotizaciones-reservas.md
// §2.5. El total NUNCA se calcula acá — se le pide al backend
// (previewPasajeAereo, mismo PriceEngineService que la creación real) cada
// vez que cambia un campo relevante, con debounce.
import { ref, reactive, watch } from 'vue';
import { alternativaItemService } from '@/services/admin/alternativaItemService';

const props = defineProps<{ alternativaId: number; paxIncluidos?: number[] | null; diaActivo: number }>();
const emit = defineEmits<{ (e: 'agregado', payload: any): void }>();

const form = reactive({
    aerolinea: '',
    itinerario: '',
    moneda: 'PEN' as 'PEN' | 'USD',
    tarifa_base_adulto: 0,
    tarifa_base_nino: 0,
    tarifa_base_infante: 0,
    cargos: [] as Array<{ nombre: string; monto: number; tipo: string }>,
    tua_incluida_en_tarifa: false,
    fee_agencia_monto: 0,
});

const preview = ref<{ costo_total: number; venta_total: number } | null>(null);
const calculando = ref(false);
let debounceTimeout: any = null;

const agregarCargo = () => {
    form.cargos.push({ nombre: '', monto: 0, tipo: 'impuesto' });
};

const quitarCargo = (idx: number) => {
    form.cargos.splice(idx, 1);
};

const recalcular = async () => {
    calculando.value = true;
    try {
        const res = await alternativaItemService.previewPasajeAereo(props.alternativaId, {
            ...form,
            pax_incluidos: props.paxIncluidos ?? null,
        });
        preview.value = res.resultado;
    } catch (error) {
        console.log(error);
    } finally {
        calculando.value = false;
    }
};

watch(form, () => {
    clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(recalcular, 400);
}, { deep: true });

const agregar = async () => {
    try {
        const res = await alternativaItemService.agregarPasajeAereo(props.alternativaId, {
            ...form,
            pax_incluidos: props.paxIncluidos ?? null,
            dia_referencial: props.diaActivo,
        });
        emit('agregado', res.alternativa_item);
    } catch (error: any) {
        console.log(error);
    }
};
</script>

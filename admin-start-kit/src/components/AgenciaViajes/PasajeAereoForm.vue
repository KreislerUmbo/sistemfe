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

        <!-- ¿A quiénes aplica? — antes este form siempre mandaba pax_incluidos
             null (todos los pasajeros de la cotización, sin excepción), sin
             ninguna forma de excluir a alguien. Mismo patrón que ya usa
             ItemManualForm.vue: si quedan TODOS tildados se manda null
             (semánticamente "todos"), si es un subconjunto se manda la lista. -->
        <div v-if="pasajeros && pasajeros.length" class="mb-2">
            <span class="small text-secondary d-block mb-1">¿A quiénes aplica?</span>
            <div class="form-check form-check-inline small" v-for="p in pasajeros" :key="p.id">
                <input class="form-check-input" type="checkbox" :id="`pasaje-aereo-pax-${p.id}`" :value="p.id" v-model="paxSeleccionados">
                <label class="form-check-label" :for="`pasaje-aereo-pax-${p.id}`">{{ p.tipo_pax }} ({{ p.edad }} años)</label>
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

        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">Tratamiento tributario (fee de agencia)</label>
                <select class="form-select form-select-sm" v-model="form.tip_afe_igv">
                    <option value="10">Gravado</option>
                    <option value="20">Exonerado</option>
                    <option value="30">Inafecto</option>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                <select class="form-select form-select-sm" v-model="form.destino_tributario">
                    <option value="amazonia">Amazonía</option>
                    <option value="nacional">Nacional</option>
                    <option value="extranjero">Extranjero</option>
                </select>
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
            <!-- Análisis de impuestos (28-ago-2026) — el precio de venta de
                 arriba sigue siendo el precio FINAL (no cambia al elegir el
                 tratamiento); esto solo desglosa base/IGV, misma fórmula que
                 la facturación. -->
            <div v-if="preview" class="border-top mt-1 pt-1 small">
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Base</span>
                    <span>{{ form.moneda }} {{ desglose.base.toFixed(2) }}</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">IGV ({{ desglose.porcentaje }}%)</span>
                    <span>{{ form.moneda }} {{ desglose.igv.toFixed(2) }}</span>
                </div>
            </div>
        </div>

        <button class="btn btn-primary btn-sm w-100" @click="agregar" :disabled="!form.aerolinea || !form.tarifa_base_adulto || guardando">
            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
            <i v-else class="fas fa-plus me-1"></i>{{ props.itemExistente ? 'Guardar cambios' : 'Agregar pasaje aéreo' }}
        </button>
    </div>
</template>

<script setup lang="ts">
// Ítem de pasaje aéreo suelto — Sesión 11b, plan-modulo-cotizaciones-reservas.md
// §2.5. El total NUNCA se calcula acá — se le pide al backend
// (previewPasajeAereo, mismo PriceEngineService que la creación real) cada
// vez que cambia un campo relevante, con debounce.
//
// Auditoría del módulo Reservas/Cotizador (2026-08-27): agrega el selector
// de pasajeros (antes SIEMPRE mandaba pax_incluidos null, sin forma de
// excluir a alguien — hallazgo real sobre la cotización CDKM-0826-0000002,
// que tenía 2 niños y no había forma de cobrar el vuelo solo para 1) y
// edición estructural completa (itemExistente, mismo patrón que
// ItemManualForm.vue) — antes solo existía el alta.
import { ref, reactive, computed, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import { desglosarPrecioFinal } from '@/utils/desglosarPrecioFinal';
import type { AlternativaItem, CotizacionPasajero, TipAfeIgv, DestinoTributario } from '@/types/agencia-viajes';

const props = defineProps<{
    alternativaId: number;
    diaActivo: number;
    pasajeros?: CotizacionPasajero[];
    itemExistente?: AlternativaItem | null;
    // Análisis de impuestos (28-ago-2026) — default de configuracion_agencia,
    // solo para prellenar; editable antes de guardar.
    tipAfeIgvDefault?: TipAfeIgv;
    destinoTributarioDefault?: DestinoTributario;
}>();
const emit = defineEmits<{
    (e: 'agregado', payload: any): void;
    (e: 'actualizado', payload: any): void;
}>();

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
    tip_afe_igv: '10' as TipAfeIgv,
    destino_tributario: 'nacional' as DestinoTributario,
});
const paxSeleccionados = ref<number[]>([]);
const guardando = ref(false);

const preview = ref<{ costo_total: number; venta_total: number } | null>(null);
const calculando = ref(false);
const desglose = computed(() => desglosarPrecioFinal(preview.value?.venta_total ?? 0, form.tip_afe_igv));
let debounceTimeout: any = null;

const agregarCargo = () => {
    form.cargos.push({ nombre: '', monto: 0, tipo: 'impuesto' });
};

const quitarCargo = (idx: number) => {
    form.cargos.splice(idx, 1);
};

// Mismo criterio que ItemManualForm.vue: todos tildados = null (semántica
// "aplica a todos", no una lista redundante que además desincroniza si
// después se agrega un pasajero nuevo a la cotización).
const paxIncluidosParaEnviar = () => {
    const total = props.pasajeros?.length ?? 0;
    return paxSeleccionados.value.length && paxSeleccionados.value.length < total ? paxSeleccionados.value : null;
};

const resetearCampos = () => {
    const item = props.itemExistente;
    const cpa = item?.cotizacion_pasaje_aereo;

    if (item && cpa) {
        form.aerolinea = cpa.aerolinea;
        form.itinerario = cpa.itinerario ?? '';
        form.moneda = cpa.moneda;
        form.tarifa_base_adulto = Number(cpa.tarifa_base_adulto);
        form.tarifa_base_nino = Number(cpa.tarifa_base_nino ?? 0);
        form.tarifa_base_infante = Number(cpa.tarifa_base_infante ?? 0);
        form.cargos = (cpa.cargos ?? []).map((c) => ({ nombre: c.nombre, monto: Number(c.monto), tipo: c.tipo ?? 'impuesto' }));
        form.tua_incluida_en_tarifa = cpa.tua_incluida_en_tarifa;
        form.fee_agencia_monto = Number(cpa.fee_agencia_monto);
        form.tip_afe_igv = (cpa.tip_afe_igv as TipAfeIgv | null) ?? props.tipAfeIgvDefault ?? '10';
        form.destino_tributario = item.destino_tributario ?? props.destinoTributarioDefault ?? 'nacional';
        paxSeleccionados.value = item.pax_incluidos && item.pax_incluidos.length
            ? [...item.pax_incluidos]
            : (props.pasajeros ?? []).map((p) => p.id);
    } else {
        form.aerolinea = '';
        form.itinerario = '';
        form.moneda = 'PEN';
        form.tarifa_base_adulto = 0;
        form.tarifa_base_nino = 0;
        form.tarifa_base_infante = 0;
        form.cargos = [];
        form.tua_incluida_en_tarifa = false;
        form.fee_agencia_monto = 0;
        form.tip_afe_igv = props.tipAfeIgvDefault ?? '10';
        form.destino_tributario = props.destinoTributarioDefault ?? 'nacional';
        paxSeleccionados.value = (props.pasajeros ?? []).map((p) => p.id);
    }
};

watch(() => props.itemExistente, resetearCampos, { immediate: true });

const recalcular = async () => {
    calculando.value = true;
    try {
        const res = await alternativaItemService.previewPasajeAereo(props.alternativaId, {
            ...form,
            pax_incluidos: paxIncluidosParaEnviar(),
        });
        preview.value = res.resultado;
    } catch (error) {
        console.log(error);
    } finally {
        calculando.value = false;
    }
};

watch([form, paxSeleccionados], () => {
    clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(recalcular, 400);
}, { deep: true });

const agregar = async () => {
    guardando.value = true;
    try {
        const payload = {
            ...form,
            pax_incluidos: paxIncluidosParaEnviar(),
            dia_referencial: props.diaActivo,
        };

        if (props.itemExistente) {
            const res = await alternativaItemService.actualizarPasajeAereo(props.itemExistente.id, payload);
            emit('actualizado', res.alternativa_item);
        } else {
            const res = await alternativaItemService.agregarPasajeAereo(props.alternativaId, payload);
            emit('agregado', res.alternativa_item);
            resetearCampos();
        }
    } catch (error: any) {
        Swal.fire('Error', error.response?.data?.message ?? 'No se pudo guardar el pasaje aéreo', 'error');
    } finally {
        guardando.value = false;
    }
};
</script>

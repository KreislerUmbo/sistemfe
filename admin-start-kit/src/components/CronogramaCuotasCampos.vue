<template>
    <!-- Modo (solo donde se permite una cuota: facturación de reservas) -->
    <div v-if="permitirUnaCuota" class="col-12">
        <div class="btn-group btn-group-sm" role="group" aria-label="Cuotas">
            <button type="button" class="btn" :class="modo === 'una' ? 'btn-primary' : 'btn-outline-primary'" @click="cambiarModo('una')">
                Una cuota
            </button>
            <button type="button" class="btn" :class="modo === 'varias' ? 'btn-primary' : 'btn-outline-primary'" @click="cambiarModo('varias')">
                Varias cuotas
            </button>
        </div>
    </div>

    <!-- Una cuota: todo el saldo, con la fecha que se elija en el calendario. -->
    <template v-if="modo === 'una'">
        <div class="col-6 col-md-4">
            <label class="form-label mb-1 small fw-semibold text-secondary">Vence el</label>
            <CampoFecha v-model="fechaUnaCuota" :min="fechaBase" :estatico="dentroDeModal" etiqueta="vencimiento de la cuota" />
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label mb-1 small fw-semibold text-secondary">Monto</label>
            <input type="text" class="form-control form-control-sm" disabled :value="`${moneda} ${montoAFinanciar.toFixed(2)}`">
        </div>
    </template>

    <!-- Varias cuotas: sugerido por número y periodicidad, editable. -->
    <template v-else>
        <div class="col-6 col-md-2">
            <label class="form-label mb-1 small fw-semibold text-secondary">N° de cuotas</label>
            <input type="number" class="form-control form-control-sm" v-model.number="numCuotas" min="1" max="60">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label mb-1 small fw-semibold text-secondary">Periodicidad</label>
            <select v-model="periodicidad" class="form-select form-select-sm">
                <option value="mensual">Mensual</option>
                <option value="quincenal">Quincenal</option>
                <option value="semanal">Semanal</option>
                <option value="personalizada">Personalizada (fechas manuales)</option>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <button type="button" class="btn btn-sm w-100 fw-semibold" :class="desactualizado ? 'btn-primary' : 'btn-outline-primary'"
                :disabled="generando" @click="generarSugerido()">
                <span v-if="generando" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="fas fa-magic me-1"></i>{{ cronograma.length ? 'Regenerar cronograma' : 'Generar cronograma sugerido' }}
            </button>
        </div>
        <!-- T-116 (C): cambió el N° de cuotas o la periodicidad y la tabla sigue con las cuotas anteriores. -->
        <div class="col-12" v-if="desactualizado">
            <div class="small text-primary"><i class="fas fa-sync-alt me-1"></i>Cambiaste el N° de cuotas o la periodicidad: pulsa «Regenerar cronograma» para actualizar las cuotas.</div>
        </div>

        <div class="col-12" v-if="cronograma.length > 0">
            <p class="small fw-semibold text-secondary mb-1">
                <i class="fas fa-calendar-check me-1"></i>Cronograma de cuotas
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr class="small text-secondary text-uppercase">
                            <th>#</th>
                            <th>Monto</th>
                            <th>Fecha de vencimiento</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(c, idx) in cronograma" :key="idx">
                            <td class="small text-muted">{{ c.numero_cuota }}</td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">{{ moneda }}</span>
                                    <input type="number" class="form-control form-control-sm" v-model.number="c.monto_programado" step="0.01">
                                </div>
                            </td>
                            <td>
                                <CampoFecha v-model="c.fecha_vencimiento" :estatico="dentroDeModal" :invalido="!c.fecha_vencimiento"
                                    :etiqueta="`vencimiento de la cuota ${c.numero_cuota}`" />
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-link btn-sm text-danger p-0" :aria-label="`Quitar la cuota ${c.numero_cuota}`"
                                    title="Quitar esta cuota" @click="quitarCuota(idx)">
                                    <i class="fas fa-trash-alt small"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="faltanFechas" class="small text-danger mt-1">Elige la fecha de vencimiento de cada cuota.</div>
        </div>
    </template>

    <!-- T-116 (B): si la suma no calza se dice cuánto falta o sobra y cómo arreglarlo. -->
    <div class="col-12" v-if="cronograma.length > 0">
        <div v-if="calza" class="small text-success">
            <i class="fas fa-check-circle me-1"></i>Las cuotas suman {{ moneda }} {{ suma.toFixed(2) }}, igual al monto a financiar.
        </div>
        <div v-else class="rounded px-2 py-2 small bg-danger-subtle text-danger">
            <div class="fw-semibold">
                {{ diferencia > 0 ? 'Faltan' : 'Sobran' }} {{ moneda }} {{ Math.abs(diferencia).toFixed(2) }}
                <span class="fw-normal">— las cuotas suman {{ moneda }} {{ suma.toFixed(2) }} y el monto a financiar es {{ moneda }} {{ montoAFinanciar.toFixed(2) }}.</span>
            </div>
            <div v-if="modo === 'varias'" class="d-flex flex-wrap gap-2 mt-2">
                <button type="button" class="btn btn-danger btn-sm py-0" :disabled="generando" @click="generarSugerido()">
                    <i class="fas fa-magic me-1"></i>Regenerar
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm py-0" @click="ajustarUltimaCuota()">
                    Ajustar última cuota
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Cronograma de cuotas de una venta a crédito (Módulo Amortizaciones). Salió
// de sale/register.vue y sale/edit.vue, que lo tenían copiado igual
// (24-oct-2026, fila F5 de Agencia), y lo usan también los modales de
// facturar una reserva. El backend valida lo mismo en
// App\Services\CronogramaCreditoValidator: la suma tiene que calzar al
// céntimo con el monto a financiar.
// - "Varias cuotas": número + periodicidad → "Generar cronograma sugerido"
//   (POST installments/schedule-preview), tabla editable.
// - "Una cuota" (permitirUnaCuota): todo el saldo con la fecha que se elija;
//   el uso típico de la agencia cuando el monto no es alto.
import { computed, ref, watch } from 'vue';
import httpClient from '@/helpers/http-client';
import CampoFecha from '@/components/CampoFecha.vue';
import { useToast } from '@/composables/useToast';
import { redondear2 } from '@/helpers/ventaRetail';

export type CuotaCronograma = { numero_cuota: number; monto_programado: number; fecha_vencimiento: string };

const props = withDefaults(defineProps<{
    montoAFinanciar: number;
    moneda: string;
    // Desde cuándo se cuentan las cuotas (fecha de emisión, YYYY-MM-DD).
    fechaBase: string;
    permitirUnaCuota?: boolean;
    // Dentro de un modal, el calendario se dibuja junto al campo.
    dentroDeModal?: boolean;
}>(), { permitirUnaCuota: false, dentroDeModal: false });

const cronograma = defineModel<CuotaCronograma[]>({ required: true });
const numCuotas = defineModel<number>('numCuotas', { default: 3 });
const periodicidad = defineModel<string>('periodicidad', { default: 'mensual' });

const toast = useToast();
const generando = ref(false);
const modo = ref<'una' | 'varias'>(props.permitirUnaCuota ? 'una' : 'varias');
const fechaUnaCuota = ref<string>('');

const suma = computed(() => cronograma.value.reduce((s, c) => s + (Number(c.monto_programado) || 0), 0));
const calza = computed(() => Math.abs(suma.value - props.montoAFinanciar) < 0.01);
// Positiva: falta repartir; negativa: las cuotas pasan del monto a financiar.
const diferencia = computed(() => redondear2(props.montoAFinanciar - suma.value));
const faltanFechas = computed(() => cronograma.value.some((c) => !c.fecha_vencimiento));

// T-116 (C): con qué N° de cuotas y periodicidad se armó la tabla que se ve. Si el usuario los cambia
// después, la tabla queda «desactualizada» hasta que regenere. No se regenera solo: borraría las
// fechas o montos puestos a mano (y en la edición, el cronograma guardado).
const armadoCon = ref<{ n: number; p: string } | null>(null);
const marcarArmado = (n: number) => { armadoCon.value = { n, p: periodicidad.value }; };
// Un cronograma que llega de afuera (la edición lo abre con el guardado) cuenta como armado con lo que trae.
watch(() => cronograma.value.length, (n) => {
    if (n === 0) armadoCon.value = null;
    else if (!armadoCon.value) marcarArmado(n);
}, { immediate: true });
const desactualizado = computed(() => modo.value === 'varias' && cronograma.value.length > 0 && !!armadoCon.value
    && (armadoCon.value.n !== numCuotas.value || armadoCon.value.p !== periodicidad.value));

// T-116 (B): la diferencia va a la última cuota (si no la deja en cero o negativa).
const ajustarUltimaCuota = () => {
    const ultima = cronograma.value[cronograma.value.length - 1];
    if (!ultima) return;
    const nuevo = redondear2((Number(ultima.monto_programado) || 0) + diferencia.value);
    if (nuevo <= 0) {
        toast.error('La última cuota quedaría en cero o negativa: regenera el cronograma.');
        return;
    }
    ultima.monto_programado = nuevo;
};

// Quitar una cuota (p. ej. una fecha equivocada): se renumeran las demás y el N° de cuotas baja.
// Lo que quede sin repartir se ve en el aviso de la suma (Regenerar / Ajustar última cuota).
const quitarCuota = (idx: number) => {
    const quedan = cronograma.value.filter((_, i) => i !== idx).map((c, i) => ({ ...c, numero_cuota: i + 1 }));
    if (quedan.length) {
        numCuotas.value = quedan.length;
        marcarArmado(quedan.length);
    }
    cronograma.value = quedan;
};

// Una cuota: el cronograma es siempre una sola fila con todo el saldo.
const armarUnaCuota = () => {
    cronograma.value = fechaUnaCuota.value
        ? [{ numero_cuota: 1, monto_programado: Math.round(props.montoAFinanciar * 100) / 100, fecha_vencimiento: fechaUnaCuota.value }]
        : [];
};
watch([fechaUnaCuota, () => props.montoAFinanciar], () => {
    if (modo.value === 'una') armarUnaCuota();
});

const cambiarModo = (nuevo: 'una' | 'varias') => {
    modo.value = nuevo;
    cronograma.value = [];
    if (nuevo === 'una') armarUnaCuota();
};

const generarSugerido = async () => {
    if (props.montoAFinanciar <= 0) {
        toast.error('No hay monto a financiar (el total ya está cubierto por adelantos o pagos).');
        return;
    }
    generando.value = true;
    try {
        const res = await httpClient.post('installments/schedule-preview', {
            monto_total: props.montoAFinanciar,
            num_cuotas: numCuotas.value,
            periodicidad: periodicidad.value,
            fecha_anchor: props.fechaBase,
        });
        cronograma.value = (res.data as { cronograma: CuotaCronograma[] }).cronograma;
        marcarArmado(numCuotas.value);
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'No se pudo generar el cronograma.');
    } finally {
        generando.value = false;
    }
};
</script>

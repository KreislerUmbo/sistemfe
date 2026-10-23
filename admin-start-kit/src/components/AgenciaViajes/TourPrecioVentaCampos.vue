<template>
    <div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <span class="small text-muted">¿Este tour se vende o solo sirve de itinerario?</span>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn" :class="modelo.uso === 'venta' ? 'btn-primary' : 'btn-outline-secondary'" @click="modelo.uso = 'venta'">
                    <i class="fas fa-tag me-1"></i>Para vender
                </button>
                <button type="button" class="btn" :class="modelo.uso === 'solo_itinerario' ? 'btn-primary' : 'btn-outline-secondary'" @click="modelo.uso = 'solo_itinerario'">
                    <i class="fas fa-route me-1"></i>Solo itinerario
                </button>
            </div>
        </div>

        <div v-if="modelo.uso === 'solo_itinerario'" class="small text-muted">
            <i class="fas fa-info-circle me-1"></i>
            Este tour solo presta su itinerario y fotos a los paquetes de mayorista. No lleva precio, costo ni IGV, y no se carga como línea en el cotizador.
        </div>
        <template v-else>
            <div v-if="numero(modelo.precio_venta_final) === null && !esNuevo" class="alert alert-warning small py-2">
                <i class="fas fa-triangle-exclamation me-1"></i>
                Este tour todavía no tiene precio por persona. Cárgalo para poder venderlo con el precio del tour.
            </div>
            <div class="row g-3">
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                    <select class="form-select form-select-sm" v-model="modelo.moneda">
                        <option value="PEN">S/ Soles</option>
                        <option value="USD">US$ Dólares</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Modalidad</label>
                    <select class="form-select form-select-sm" v-model="modelo.modalidad">
                        <option value="compartido">Compartido</option>
                        <option value="privado">Privado</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Mínimo para salir</label>
                    <input type="number" min="1" class="form-control form-control-sm" v-model.number="modelo.pax_minimo_salida" placeholder="pax">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Precio adulto *</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="modelo.precio_venta_final">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Precio niño</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="modelo.precio_venta_nino" placeholder="Vacío = adulto">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Costo estimado por persona</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="modelo.costo_estimado_persona"
                        :disabled="costoDesdeIncluye" :placeholder="costoDesdeIncluye ? '' : 'A mano'">
                    <small v-if="costoDesdeIncluye" class="text-muted">Suma del Incluye: {{ simbolo }} {{ fmt(costoResuelto) }}</small>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Margen mínimo del tour (%)</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="modelo.margen_minimo_pct"
                        :placeholder="margenMinimoAgencia !== null ? `Agencia: ${margenMinimoAgencia}%` : 'Opcional'">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">IGV por defecto</label>
                    <select class="form-select form-select-sm" v-model="modelo.tip_afe_igv" @change="alCambiarIgv">
                        <option :value="null">El de la agencia{{ tratamientoDefault ? ` (${etiquetaIgv(tratamientoDefault.tip_afe_igv)})` : '' }}</option>
                        <option value="10">Gravado</option>
                        <option value="20">Exonerado</option>
                        <option value="30">Inafecto</option>
                        <option value="40">Exportación</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Destino tributario</label>
                    <select class="form-select form-select-sm" v-model="modelo.destino_tributario" :disabled="!modelo.tip_afe_igv || modelo.tip_afe_igv === IGV_EXONERADO">
                        <option :value="null" disabled>—</option>
                        <option value="amazonia">Amazonía</option>
                        <option value="nacional">Nacional</option>
                        <option value="extranjero">Extranjero</option>
                    </select>
                </div>

                <div class="col-12">
                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="verExtranjero = !verExtranjero">
                        <i class="fas me-1" :class="verExtranjero ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                        Precio para extranjeros (opcional)
                    </button>
                </div>
                <template v-if="verExtranjero">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Adulto extranjero</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="modelo.precio_venta_adulto_ext" placeholder="Vacío = nacional">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Niño extranjero</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="modelo.precio_venta_nino_ext" placeholder="Vacío = nacional">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Costo extranjero por persona</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="modelo.costo_estimado_persona_ext"
                            :disabled="costoDesdeIncluye" :placeholder="costoDesdeIncluye ? '' : 'Vacío = nacional'">
                    </div>
                </template>
            </div>

            <div class="d-flex flex-column gap-1 mt-3">
                <MargenEnVivo :moneda="simbolo" :precio="numero(modelo.precio_venta_final)" :costo="costoResuelto"
                    :margen-minimo-tour="numero(modelo.margen_minimo_pct)" :margen-minimo-agencia="margenMinimoAgencia" etiqueta="Adulto nacional" />
                <MargenEnVivo v-if="verExtranjero && numero(modelo.precio_venta_adulto_ext) !== null" :moneda="simbolo"
                    :precio="numero(modelo.precio_venta_adulto_ext)" :costo="costoResueltoExt"
                    :margen-minimo-tour="numero(modelo.margen_minimo_pct)" :margen-minimo-agencia="margenMinimoAgencia" etiqueta="Adulto extranjero" />
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import { destinoParaIgvAgencia, IGV_EXONERADO } from '@/helpers/tratamientoTributarioAgencia';
// Fila 13, fase 1a (plan-tours-venta-simple-costo-real.md §3.1, N1/N2/N5/N15):
// precio por persona, costo, IGV por defecto, modalidad, mínimo y uso del
// tour simple. Lo comparten paquetes/form.vue (alta) y la edición en línea
// del tab Datos de paquetes/detalle.vue — muta los campos del objeto que
// recibe por v-model.
import { computed, ref } from 'vue';
import MargenEnVivo from '@/components/AgenciaViajes/MargenEnVivo.vue';
import type { CamposPrecioTour, TourCostoEstimado } from '@/types/agencia-viajes';

const modelo = defineModel<CamposPrecioTour>({ required: true });

const props = defineProps<{
    costoEstimado: TourCostoEstimado | null;
    margenMinimoAgencia: number | null;
    tratamientoDefault: { tip_afe_igv: string; destino_tributario: string } | null;
    esNuevo?: boolean;
}>();

const numero = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : Number(v));
const fmt = (v: number | null) => (v === null ? '—' : v.toFixed(2));
const simbolo = computed(() => (modelo.value.moneda === 'USD' ? 'US$' : 'S/'));
const etiquetaIgv = (codigo: string) => ({ '10': 'Gravado', '20': 'Exonerado', '30': 'Inafecto', '40': 'Exportación' } as Record<string, string>)[codigo] ?? codigo;

const verExtranjero = ref<boolean>(
    [modelo.value.precio_venta_adulto_ext, modelo.value.precio_venta_nino_ext, modelo.value.costo_estimado_persona_ext].some((v) => numero(v) !== null)
);

const alCambiarIgv = () => {
    if (!modelo.value.tip_afe_igv) {
        modelo.value.destino_tributario = null;
    } else {
        // Exonerado en Agencia = Amazonía (Ley 27037); el resto, nacional si no tenía.
        modelo.value.destino_tributario = destinoParaIgvAgencia(modelo.value.tip_afe_igv, modelo.value.destino_tributario ?? 'nacional');
    }
};

// Si todo el Incluye tiene costo, el costo del tour es esa suma (se edita en
// el tab "Incluye y costos"); si no, el escrito a mano.
const costoDesdeIncluye = computed(() => props.costoEstimado?.origen === 'incluye');
const costoResuelto = computed(() => (costoDesdeIncluye.value ? props.costoEstimado!.costo : numero(modelo.value.costo_estimado_persona)));
const costoResueltoExt = computed(() => (costoDesdeIncluye.value
    ? props.costoEstimado!.costo_ext
    : numero(modelo.value.costo_estimado_persona_ext) ?? numero(modelo.value.costo_estimado_persona)));
</script>

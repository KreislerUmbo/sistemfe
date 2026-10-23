<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-right-left me-2 text-primary"></i>
                    Convertir tours al modelo nuevo
                </h5>
                <small class="text-muted">
                    Revisa la propuesta de cada tour (precio, costo e IGV por servicio) y confírmala. Nada se guarda hasta que confirmes.
                </small>
            </div>
            <router-link to="/agencia-viajes/paquetes" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Volver a Paquetes / Tours
            </router-link>
        </div>

        <div class="row g-3">
            <!-- Lista de tours -->
            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between">
                        <span class="fw-semibold small">Tours para vender</span>
                        <span class="badge bg-light text-dark border">{{ confirmados }}/{{ tours.length }} confirmados</span>
                    </div>
                    <div class="card-body p-0">
                        <div v-if="cargandoLista" class="text-center text-muted small py-4">
                            <span class="spinner-border spinner-border-sm me-1"></span>Cargando…
                        </div>
                        <div v-else-if="tours.length === 0" class="text-center text-muted small py-4">No hay tours para convertir.</div>
                        <div v-else class="list-group list-group-flush">
                            <button v-for="t in tours" :key="t.id" type="button"
                                class="list-group-item list-group-item-action py-2"
                                :class="{ active: t.id === seleccionadoId }" @click="seleccionar(t.id)">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="small">
                                        <div class="fw-semibold">{{ t.nombre }}</div>
                                        <div :class="t.id === seleccionadoId ? 'text-white-50' : 'text-muted'" style="font-size:.75rem">
                                            {{ t.codigo ?? 'sin código' }} · {{ t.servicios }} servicio(s)
                                            <span v-if="t.igv_mezclado"> · IGV mezclado</span>
                                        </div>
                                    </div>
                                    <span v-if="t.conversion_confirmada_en" class="badge bg-success-subtle text-success-emphasis">Confirmado</span>
                                    <span v-else class="badge bg-warning-subtle text-warning-emphasis">Pendiente</span>
                                </div>
                            </button>
                        </div>
                    </div>
                    <div v-if="fuera.combos + fuera.solo_itinerario > 0" class="card-footer bg-white small text-muted">
                        Fuera de la conversión: {{ fuera.combos }} combo(s) (se arman con sus tours) y
                        {{ fuera.solo_itinerario }} tour(s) solo itinerario (de mayorista).
                    </div>
                </div>
            </div>

            <!-- Propuesta del tour elegido -->
            <div class="col-12 col-lg-8">
                <div v-if="!seleccionadoId" class="card border-0 shadow-sm">
                    <div class="card-body text-center text-muted small py-5">Elige un tour de la lista para ver su propuesta.</div>
                </div>
                <div v-else-if="cargandoPropuesta || !propuesta" class="card border-0 shadow-sm">
                    <div class="card-body text-center text-muted small py-5">
                        <span class="spinner-border spinner-border-sm me-1"></span>Cargando propuesta…
                    </div>
                </div>
                <div v-else class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <span class="fw-semibold">{{ propuesta.nombre }}</span>
                            <span class="text-muted small ms-2">{{ propuesta.codigo }}</span>
                        </div>
                        <span v-if="propuesta.conversion_confirmada_en" class="small text-success">
                            <i class="fas fa-check me-1"></i>Confirmado el {{ formatFechaHora(propuesta.conversion_confirmada_en) }}
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Precio por persona (adulto) — {{ propuesta.moneda }}</label>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="precio">
                                <div class="text-muted" style="font-size:.72rem">
                                    <template v-if="propuesta.precio_origen === 'cargado'">Es el precio que ya tenía el tour.</template>
                                    <template v-else-if="propuesta.precio_origen === 'tarifas'">
                                        Propuesto: suma de ventas de sus tarifas ({{ dinero(propuesta.suma_venta_tarifas) }})<span v-if="propuesta.ajuste_redondeo !== null"> + ajuste {{ dinero(propuesta.ajuste_redondeo) }}</span>.
                                    </template>
                                    <template v-else>Sin precio ni tarifas: escríbelo.</template>
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Costo estimado por persona</label>
                                <input v-if="!costoDesdeIncluye" type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="costoTour" placeholder="Opcional">
                                <div v-else class="form-control form-control-sm bg-light">{{ dinero(sumaCostos) }}</div>
                                <div class="text-muted" style="font-size:.72rem">
                                    {{ costoDesdeIncluye ? 'Suma del costo de los servicios.' : 'Escrito a mano (falta el costo de algún servicio).' }}
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">IGV del tour (servicio sin IGV propio)</label>
                                <div class="form-control form-control-sm bg-light">{{ etiquetaIgv(propuesta.igv_tour.tip_afe_igv) }}</div>
                                <div v-if="propuesta.igv_tour_es_default" class="text-muted" style="font-size:.72rem">Es el default de la agencia.</div>
                            </div>
                        </div>

                        <div v-if="filas.length > 0" class="table-responsive mb-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small">
                                        <th>Servicio del Incluye</th>
                                        <th style="width:190px">Costo por persona</th>
                                        <th style="width:200px">IGV</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="fila in filas" :key="fila.item.id" class="small">
                                        <td>
                                            <div class="fw-semibold">{{ fila.item.servicio }}</div>
                                            <div class="text-muted" style="font-size:.72rem">{{ fila.item.proveedor ?? 'Sin proveedor' }}</div>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="fila.costo" placeholder="Sin costo">
                                            <div v-if="fila.item.costo_sugerido !== null && fila.item.costo_sugerido !== fila.costo" style="font-size:.72rem" class="text-info-emphasis">
                                                Calculadora: {{ dinero(fila.item.costo_sugerido) }}
                                                <button type="button" class="btn btn-link p-0 align-baseline" style="font-size:.72rem" @click="fila.costo = fila.item.costo_sugerido">usar</button>
                                            </div>
                                            <div v-else-if="fila.item.motivo_sin_sugerencia && fila.costo === null" class="text-muted" style="font-size:.72rem">
                                                {{ fila.item.motivo_sin_sugerencia }}
                                            </div>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm" v-model="fila.igv">
                                                <option :value="null">Del tour ({{ etiquetaIgv(propuesta.igv_tour.tip_afe_igv) }})</option>
                                                <option value="10|nacional">Gravado</option>
                                                <option value="20|amazonia">Exonerado (Amazonía)</option>
                                                <option value="30|nacional">Inafecto</option>
                                                <option value="40|extranjero">Exportación</option>
                                            </select>
                                            <div v-if="fila.item.igv_tarifa && claveIgv(fila.item.igv_tarifa) !== fila.igv" class="text-warning-emphasis" style="font-size:.72rem">
                                                La tarifa es {{ etiquetaIgv(fila.item.igv_tarifa.tip_afe_igv) }}
                                                <button type="button" class="btn btn-link p-0 align-baseline" style="font-size:.72rem" @click="fila.igv = claveIgv(fila.item.igv_tarifa)">usar</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="hayIgvMezclado" class="alert py-2 small mb-2" :class="faltaCostoConMezcla ? 'alert-danger' : 'alert-info'">
                            <i class="fas fa-circle-info me-1"></i>
                            El tour mezcla tipos de IGV: irá en la cotización como una línea por tipo de IGV.
                            <strong v-if="faltaCostoConMezcla">Todos los servicios necesitan costo para repartir el precio.</strong>
                        </div>
                        <ul v-if="propuesta.avisos.length > 0" class="small text-muted mb-3 ps-3">
                            <li v-for="(aviso, k) in propuesta.avisos" :key="k">{{ aviso }}</li>
                        </ul>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="seleccionar(propuesta.id)">
                                <i class="fas fa-undo me-1"></i>Volver a la propuesta
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="guardando || !puedeConfirmar" @click="confirmar">
                                <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-check me-1"></i>
                                {{ propuesta.conversion_confirmada_en ? 'Guardar de nuevo' : 'Confirmar conversión' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
// Fila 13, fase 1c — conversión de los tours existentes al modelo nuevo
// (plan-tours-venta-simple-costo-real.md §3.11). Tour por tour: el backend
// propone (precio, costo de la calculadora, IGV de la tarifa) y el usuario
// corrige y confirma. Nada se guarda hasta "Confirmar conversión".
import { computed, onMounted, ref } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { formatFechaHora } from '@/helpers/fecha';
import {
    conversionToursService,
    type IgvServicio,
    type TourConversionFila,
    type TourConversionItem,
    type TourConversionPropuesta,
} from '@/services/admin/paquetePlantillaService';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;
type Fila = { item: TourConversionItem; costo: number | null; igv: string | null };

const tours = ref<TourConversionFila[]>([]);
const fuera = ref({ combos: 0, solo_itinerario: 0 });
const cargandoLista = ref(false);
const seleccionadoId = ref<number | null>(null);
const propuesta = ref<TourConversionPropuesta | null>(null);
const cargandoPropuesta = ref(false);
const guardando = ref(false);

const precio = ref<number | null>(null);
const costoTour = ref<number | null>(null);
const filas = ref<Fila[]>([]);

const confirmados = computed(() => tours.value.filter((t) => t.conversion_confirmada_en).length);

const etiquetaIgv = (codigo: string | null | undefined) =>
    ({ '10': 'Gravado', '17': 'IVAP', '20': 'Exonerado', '30': 'Inafecto', '40': 'Exportación' } as Record<string, string>)[codigo ?? ''] ?? '—';
const claveIgv = (igv: IgvServicio | null) => (igv ? `${igv.tip_afe_igv}|${igv.destino_tributario}` : null);
const dinero = (n: number | null) => (n === null ? '—' : `${propuesta.value?.moneda === 'USD' ? 'US$' : 'S/'} ${n.toFixed(2)}`);
const numero = (v: unknown) => (v === '' || v === null || v === undefined || Number.isNaN(Number(v)) ? null : Number(v));

// Código SUNAT efectivo de cada servicio: el suyo, o el del tour si no tiene.
// La mezcla se mide por código (22-oct): el destino no parte la línea.
const igvEfectivo = (f: Fila) => (f.igv ?? claveIgv(propuesta.value?.igv_tour ?? null))?.split('|')[0] ?? null;
const hayIgvMezclado = computed(() => new Set(filas.value.map(igvEfectivo)).size > 1);
const faltaCostoConMezcla = computed(() => hayIgvMezclado.value && filas.value.some((f) => numero(f.costo) === null));
const costoDesdeIncluye = computed(() => filas.value.length > 0 && filas.value.every((f) => numero(f.costo) !== null));
const sumaCostos = computed(() => filas.value.reduce((s, f) => s + (numero(f.costo) ?? 0), 0));
const puedeConfirmar = computed(() => (numero(precio.value) ?? 0) > 0 && !faltaCostoConMezcla.value);

const cargarLista = async () => {
    cargandoLista.value = true;
    try {
        const res = await conversionToursService.listar();
        tours.value = res.tours;
        fuera.value = res.fuera;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar la lista de tours', 'error');
    } finally {
        cargandoLista.value = false;
    }
};

const aplicarPropuesta = (p: TourConversionPropuesta) => {
    propuesta.value = p;
    precio.value = p.precio_propuesto;
    costoTour.value = p.costo_tour_actual;
    filas.value = p.items.map((item) => ({ item, costo: item.costo_propuesto, igv: claveIgv(item.igv_propuesto) }));
};

const seleccionar = async (id: number) => {
    seleccionadoId.value = id;
    cargandoPropuesta.value = true;
    try {
        aplicarPropuesta(await conversionToursService.propuesta(id));
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cargar la propuesta', 'error');
        seleccionadoId.value = null;
    } finally {
        cargandoPropuesta.value = false;
    }
};

const confirmar = async () => {
    if (!propuesta.value) return;
    const respuesta = await (Swal as TVueSwalInstance).fire({
        title: '¿Confirmar la conversión?',
        text: `Se guardarán el precio, el costo y el IGV de cada servicio de «${propuesta.value.nombre}».`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, confirmar',
        cancelButtonText: 'Cancelar',
    });
    if (!respuesta.isConfirmed) return;

    guardando.value = true;
    try {
        const res = await conversionToursService.confirmar(propuesta.value.id, {
            precio_venta_final: numero(precio.value) ?? 0,
            costo_estimado_persona: costoDesdeIncluye.value ? undefined : numero(costoTour.value),
            items: filas.value.map((f) => {
                const [tip, destino] = f.igv ? f.igv.split('|') : [null, null];
                return { id: f.item.id, costo_estimado_persona: numero(f.costo), tip_afe_igv: tip, destino_tributario: destino };
            }),
        });
        aplicarPropuesta(res.propuesta);
        const fila = tours.value.find((t) => t.id === res.propuesta.id);
        if (fila) fila.conversion_confirmada_en = res.propuesta.conversion_confirmada_en;
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar la conversión', 'error');
    } finally {
        guardando.value = false;
    }
};

onMounted(cargarLista);
</script>

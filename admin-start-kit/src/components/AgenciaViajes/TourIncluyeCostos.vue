<template>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="fw-semibold text-dark small"><i class="fas fa-list-check text-primary me-1"></i>Incluye y costos por persona</span>
            <div class="d-flex align-items-center gap-2">
                <select class="form-select form-select-sm" style="width:auto" v-model="temporadaCalculo" title="Calcular con las tarifas de una temporada">
                    <option :value="null">Tarifas regulares</option>
                    <option v-for="t in temporadas" :key="t.id" :value="t.id">Tarifas de {{ t.nombre }}</option>
                </select>
                <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" @click="calcular" :disabled="calculando || filas.length === 0">
                    <span v-if="calculando" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-calculator me-1"></i>Calcular desde tarifas
                </button>
            </div>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Costo estimado <strong>por persona</strong> de cada servicio. La calculadora solo sugiere: por persona usa el costo de la tarifa;
                por vehículo o grupo lo divide entre el mínimo para salir{{ paquete.pax_minimo_salida ? ` (${paquete.pax_minimo_salida})` : '' }};
                el guía se multiplica por los días del tour{{ calculo ? ` (${calculo.dias})` : '' }}. El IGV va por servicio, según dónde se presta.
            </p>

            <div v-if="filas.length === 0" class="text-muted fst-italic text-center py-3 small">
                El tour todavía no tiene servicios en el Incluye.
            </div>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr class="small text-secondary">
                            <th>Servicio</th>
                            <th style="width:120px">Costo x pers.</th>
                            <th v-if="conExtranjero" style="width:120px">Costo extr.</th>
                            <th style="width:150px">IGV</th>
                            <th style="width:190px">Sugerido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="fila in filas" :key="fila.item.id" class="small">
                            <td>
                                <div class="fw-semibold text-dark">{{ titulo(fila.item) }}</div>
                                <div class="text-muted">
                                    <!-- Fila 13, fase 1b: la venta de la tarifa queda solo como
                                         referencia (la usa el modo clásico de la cotización). -->
                                    <template v-if="fila.item.proveedor_tarifa">
                                        {{ proveedorDe(fila.item) }} · tarifa {{ fila.item.proveedor_tarifa.modalidad }}
                                        costo {{ fila.item.proveedor_tarifa.moneda }} {{ Number(fila.item.proveedor_tarifa.precio_costo).toFixed(2) }}
                                        <span title="Precio de la tarifa: solo lo usa el modo clásico de la cotización"> · venta {{ Number(fila.item.proveedor_tarifa.precio_venta_adulto).toFixed(2) }}</span>
                                    </template>
                                    <template v-else-if="fila.item.guia_tarifa">
                                        Guía · {{ fila.item.guia_tarifa.moneda }} {{ Number(fila.item.guia_tarifa.costo_diario).toFixed(2) }} por día
                                    </template>
                                    <template v-else>
                                        <span class="badge bg-light text-secondary border">Sin tarifa</span>
                                        <span v-if="fila.item.proveedor"> · {{ fila.item.proveedor.nombre_comercial ?? fila.item.proveedor.razon_social }}</span>
                                    </template>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2" @click="quitar(fila.item)" :disabled="quitandoId === fila.item.id" title="Quitar del Incluye">
                                        <span v-if="quitandoId === fila.item.id" class="spinner-border spinner-border-sm"></span>
                                        <i v-else class="fas fa-trash-can"></i>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="fila.costo" placeholder="—">
                            </td>
                            <td v-if="conExtranjero">
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="fila.costoExt" placeholder="= nacional">
                            </td>
                            <td>
                                <select class="form-select form-select-sm" v-model="fila.igv">
                                    <option :value="null">Del tour ({{ etiquetaIgv(igvTour) }})</option>
                                    <option value="10|nacional">Gravado</option>
                                    <option value="20|amazonia">Exonerado (Amazonía)</option>
                                    <option value="30|nacional">Inafecto</option>
                                    <option value="40|extranjero">Exportación</option>
                                </select>
                                <!-- Referencia: el IGV de la tarifa vinculada, si difiere del que se aplicaría -->
                                <div v-if="igvTarifa(fila.item) && igvTarifa(fila.item) !== igvEfectivo(fila)" class="text-warning-emphasis" style="font-size:.72rem">
                                    La tarifa es {{ etiquetaIgv(igvTarifa(fila.item)) }}
                                    <button type="button" class="btn btn-link p-0 align-baseline" style="font-size:.72rem" @click="fila.igv = `${igvTarifa(fila.item)}|${fila.item.proveedor_tarifa?.destino_tributario ?? 'nacional'}`">usar</button>
                                </div>
                            </td>
                            <td>
                                <template v-if="sugerencia(fila.item.id)">
                                    <div v-if="sugerencia(fila.item.id)!.costo_sugerido !== null" class="d-flex align-items-center gap-2">
                                        <span class="fw-semibold">{{ Number(sugerencia(fila.item.id)!.costo_sugerido).toFixed(2) }}</span>
                                        <button type="button" class="btn btn-sm btn-link p-0" @click="fila.costo = sugerencia(fila.item.id)!.costo_sugerido">usar</button>
                                    </div>
                                    <div class="text-muted" :class="{ 'text-warning-emphasis': sugerencia(fila.item.id)!.motivo }" style="font-size:.75rem">
                                        {{ sugerencia(fila.item.id)!.detalle ?? sugerencia(fila.item.id)!.motivo }}
                                        <span v-if="sugerencia(fila.item.id)!.de_temporada" class="badge bg-info-subtle text-info border ms-1">temporada</span>
                                    </div>
                                </template>
                                <span v-else class="text-muted">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                <div class="d-flex gap-2">
                    <button v-if="calculo && calculo.items.some((s) => s.costo_sugerido !== null)" type="button" class="btn btn-sm btn-outline-secondary" @click="usarTodas">
                        Usar todas las sugerencias
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="mostrarAgregar = !mostrarAgregar">
                        <i class="fas fa-plus me-1"></i>Servicio sin tarifa
                    </button>
                </div>
                <button type="button" class="btn btn-sm btn-primary" @click="guardar" :disabled="guardando || filasModificadas.length === 0">
                    <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-check me-1"></i>Guardar costos{{ filasModificadas.length ? ` (${filasModificadas.length})` : '' }}
                </button>
            </div>

            <!-- Servicio sin tarifa: costo escrito a mano (§3.2) -->
            <div v-if="mostrarAgregar" class="border rounded p-2 mt-3 bg-light">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                        <DestinoTreeSelect v-model="destinoAgregar" nivel-max="lugar" />
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Servicio</label>
                        <select class="form-select form-select-sm" v-model="destinoServicioAgregar">
                            <option :value="null">— Elige un servicio —</option>
                            <option v-for="ds in serviciosDestino" :key="ds.id" :value="ds.id">{{ ds.servicio?.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Costo x pers.</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="costoAgregar">
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="button" class="btn btn-sm btn-primary w-100" @click="agregarSinTarifa" :disabled="!destinoServicioAgregar || agregando">
                            <span v-if="agregando" class="spinner-border spinner-border-sm me-1"></span>Agregar
                        </button>
                    </div>
                </div>
                <small v-if="destinoAgregar && serviciosDestino.length === 0" class="text-muted">Este destino no tiene servicios asociados (se asocian en Destinos).</small>
            </div>

            <!-- Costo del tour + margen -->
            <div class="border-top mt-3 pt-3">
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Costo del tour por persona</span>
                    <span class="fw-semibold">
                        <template v-if="costoEstimado?.origen === 'incluye'">{{ simbolo }} {{ Number(costoEstimado.costo).toFixed(2) }} <span class="text-muted fw-normal">(suma del Incluye)</span></template>
                        <template v-else-if="costoEstimado?.origen === 'manual'">{{ simbolo }} {{ Number(costoEstimado.costo).toFixed(2) }} <span class="text-muted fw-normal">(escrito a mano — {{ costoEstimado.items_sin_costo }} servicio(s) sin costo)</span></template>
                        <template v-else>— <span class="text-muted fw-normal">(faltan costos en el Incluye y no hay uno escrito a mano)</span></template>
                    </span>
                </div>
                <div v-if="hayIgvMezclado" class="small text-info-emphasis mb-1">
                    <i class="fas fa-circle-info me-1"></i>El Incluye mezcla tipos de IGV: en la cotización el tour irá en una línea por tipo de IGV, con el precio repartido según el costo de cada grupo.
                    <span v-if="costoEstimado?.origen !== 'incluye'"> Para eso todos los servicios necesitan su costo.</span>
                </div>
                <MargenEnVivo :moneda="simbolo" :precio="numero(paquete.precio_venta_final)" :costo="costoEstimado?.costo ?? null"
                    :margen-minimo-tour="numero(paquete.margen_minimo_pct)" :margen-minimo-agencia="margenMinimoAgencia" etiqueta="Adulto nacional" />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Fila 13, fase 1a — pestaña "Incluye y costos" del tour simple
// (plan-tours-venta-simple-costo-real.md §3.2, §3.9b, N3/N4/N5/N14): costo
// estimado por persona y IGV por servicio del Incluye, calculadora desde
// tarifas (solo sugiere; se guarda con "Guardar costos") y alta de un
// servicio sin tarifa con el costo escrito a mano.
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import MargenEnVivo from '@/components/AgenciaViajes/MargenEnVivo.vue';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import { destinoAtractivoService } from '@/services/admin/destinoAtractivoService';
import { temporadaService } from '@/services/admin/temporadaService';
import type { CalculadoraCostos, DestinoServicio, PaquetePlantilla, PaquetePlantillaItem, TourCostoEstimado } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{
    paquete: PaquetePlantilla;
    items: PaquetePlantillaItem[];
    costoEstimado: TourCostoEstimado | null;
    margenMinimoAgencia: number | null;
    tratamientoDefault: { tip_afe_igv: string; destino_tributario: string } | null;
}>();
const emit = defineEmits<{ (e: 'actualizado'): void }>();

type Fila = { item: PaquetePlantillaItem; costo: number | null; costoExt: number | null; igv: string | null; original: string };

const numero = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : Number(v));
const simbolo = computed(() => (props.paquete.moneda === 'USD' ? 'US$' : 'S/'));
const etiquetaIgv = (codigo: string | null | undefined) => ({ '10': 'Gravado', '17': 'IVAP', '20': 'Exonerado', '30': 'Inafecto', '40': 'Exportación' } as Record<string, string>)[codigo ?? ''] ?? '—';
const igvTour = computed(() => props.paquete.tip_afe_igv ?? props.tratamientoDefault?.tip_afe_igv ?? null);
const conExtranjero = computed(() => numero(props.paquete.precio_venta_adulto_ext) !== null || numero(props.paquete.precio_venta_nino_ext) !== null
    || props.items.some((i) => numero(i.costo_estimado_persona_ext) !== null));

const firma = (f: Pick<Fila, 'costo' | 'costoExt' | 'igv'>) => JSON.stringify([f.costo, f.costoExt, f.igv]);

// Solo los servicios propios del tour (en un tour simple no hay tours-hijo).
const filas = ref<Fila[]>([]);
watch(() => props.items, (items) => {
    filas.value = items.filter((i) => i.paquete_plantilla_hijo_id == null).map((item) => {
        const fila = {
            item,
            costo: numero(item.costo_estimado_persona),
            costoExt: numero(item.costo_estimado_persona_ext),
            igv: item.tip_afe_igv ? `${item.tip_afe_igv}|${item.destino_tributario ?? 'nacional'}` : null,
        };
        return { ...fila, original: firma(fila) };
    });
}, { immediate: true });

const filasModificadas = computed(() => filas.value.filter((f) => firma(f) !== f.original));

const igvEfectivo = (f: Pick<Fila, 'igv'>) => (f.igv ? f.igv.split('|')[0] : igvTour.value);
const igvTarifa = (item: PaquetePlantillaItem) => item.proveedor_tarifa?.tip_afe_igv ?? null;
const hayIgvMezclado = computed(() => new Set(filas.value.map((f) => igvEfectivo(f))).size > 1);

const titulo = (item: PaquetePlantillaItem) => {
    if (item.guia_tarifa) return `Guía: ${item.guia_tarifa.guia?.nombre ?? '—'}`;
    return item.proveedor_tarifa?.proveedor_servicio?.destino_servicio?.servicio?.nombre
        ?? item.destino_servicio?.servicio?.nombre ?? 'Servicio';
};
const proveedorDe = (item: PaquetePlantillaItem) => item.proveedor_tarifa?.proveedor_servicio?.proveedor?.nombre_comercial
    ?? item.proveedor_tarifa?.proveedor_servicio?.proveedor?.razon_social ?? '';

// ── Calculadora ───────────────────────────────────────────────────────
const temporadas = ref<{ id: number; nombre: string }[]>([]);
temporadaService.listar().then((res) => { temporadas.value = res.temporadas ?? []; }).catch(() => { temporadas.value = []; });
const temporadaCalculo = ref<number | null>(null);
const calculo = ref<CalculadoraCostos | null>(null);
const calculando = ref(false);
const sugerencia = (itemId: number) => calculo.value?.items.find((s) => s.item_id === itemId) ?? null;

const calcular = async () => {
    calculando.value = true;
    try {
        calculo.value = await paquetePlantillaService.calculadoraCostos(props.paquete.id, temporadaCalculo.value);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo calcular', 'error');
    } finally {
        calculando.value = false;
    }
};

const usarTodas = () => {
    for (const fila of filas.value) {
        const s = sugerencia(fila.item.id);
        if (s?.costo_sugerido != null) fila.costo = s.costo_sugerido;
    }
};

// ── Guardar ───────────────────────────────────────────────────────────
const guardando = ref(false);
const guardar = async () => {
    guardando.value = true;
    try {
        await Promise.all(filasModificadas.value.map((f) => {
            const [tip, destino] = f.igv ? f.igv.split('|') : [null, null];
            return paquetePlantillaService.actualizarItem(f.item.id, {
                costo_estimado_persona: f.costo,
                costo_estimado_persona_ext: f.costoExt,
                tip_afe_igv: tip,
                destino_tributario: destino,
            });
        }));
        emit('actualizado');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudieron guardar los costos', 'error');
    } finally {
        guardando.value = false;
    }
};

// ── Servicio sin tarifa ──────────────────────────────────────────────
// Los servicios CON tarifa se agregan y quitan en el tab "Agregar Costos"
// (biblioteca de proveedores/guías), como siempre; acá solo los sin tarifa.
const quitandoId = ref<number | null>(null);
const quitar = async (item: PaquetePlantillaItem) => {
    quitandoId.value = item.id;
    try {
        await paquetePlantillaService.quitarItem(item.id);
        emit('actualizado');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo quitar', 'error');
    } finally {
        quitandoId.value = null;
    }
};

const mostrarAgregar = ref(false);
const destinoAgregar = ref<number | null>(props.paquete.destino_atractivo_id ?? null);
const serviciosDestino = ref<DestinoServicio[]>([]);
const destinoServicioAgregar = ref<number | null>(null);
const costoAgregar = ref<number | null>(null);
const agregando = ref(false);

watch(destinoAgregar, async (id) => {
    destinoServicioAgregar.value = null;
    serviciosDestino.value = [];
    if (!id) return;
    try {
        serviciosDestino.value = (await destinoAtractivoService.listarServicios(id)).destino_servicios ?? [];
    } catch {
        serviciosDestino.value = [];
    }
}, { immediate: true });

const agregarSinTarifa = async () => {
    if (!destinoServicioAgregar.value) return;
    agregando.value = true;
    try {
        await paquetePlantillaService.agregarItem(props.paquete.id, {
            destino_servicio_id: destinoServicioAgregar.value,
            costo_estimado_persona: costoAgregar.value ?? undefined,
        });
        destinoServicioAgregar.value = null;
        costoAgregar.value = null;
        mostrarAgregar.value = false;
        emit('actualizado');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo agregar', 'error');
    } finally {
        agregando.value = false;
    }
};
</script>

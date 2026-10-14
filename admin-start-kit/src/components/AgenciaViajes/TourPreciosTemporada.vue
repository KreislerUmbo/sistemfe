<template>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <span class="fw-semibold text-dark small"><i class="fas fa-calendar-days text-primary me-1"></i>Precios por temporada</span>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Precio base: adulto {{ simbolo }} {{ fmt(paquete.precio_venta_final) }}{{ numero(paquete.precio_venta_nino) !== null ? ` · niño ${simbolo} ${fmt(paquete.precio_venta_nino)}` : '' }}.
                Si la fecha del tour cae en una temporada con precio propio, se usa ese; si dos temporadas se superponen, gana la más corta.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr class="small text-secondary">
                            <th>Temporada</th>
                            <th style="width:105px">Adulto *</th>
                            <th style="width:105px">Niño</th>
                            <th style="width:105px">Adulto extr.</th>
                            <th style="width:105px">Niño extr.</th>
                            <th style="width:105px">Costo x pers.</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in precios" :key="p.id" class="small">
                            <template v-if="editandoId === p.id">
                                <td>{{ p.temporada?.nombre ?? `#${p.temporada_id}` }}</td>
                                <td v-for="campo in camposPrecio" :key="campo"><input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="edicion[campo]"></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-link p-0 me-2" @click="guardar(p.id)" :disabled="guardando" title="Guardar"><i class="fas fa-check"></i></button>
                                    <button type="button" class="btn btn-sm btn-link text-secondary p-0" @click="editandoId = null" title="Cancelar"><i class="fas fa-xmark"></i></button>
                                </td>
                            </template>
                            <template v-else>
                                <td class="fw-semibold">{{ p.temporada?.nombre ?? `#${p.temporada_id}` }}</td>
                                <td>{{ fmt(p.precio_venta_adulto) }}</td>
                                <td>{{ fmt(p.precio_venta_nino) }}</td>
                                <td>{{ fmt(p.precio_venta_adulto_ext) }}</td>
                                <td>{{ fmt(p.precio_venta_nino_ext) }}</td>
                                <td>{{ fmt(p.costo_estimado_persona) }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-link p-0 me-2" @click="editar(p)" title="Editar"><i class="fas fa-pen"></i></button>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="quitar(p)" title="Quitar"><i class="fas fa-trash-can"></i></button>
                                </td>
                            </template>
                        </tr>
                        <tr class="small">
                            <td>
                                <select class="form-select form-select-sm" v-model="nueva.temporada_id">
                                    <option :value="null">+ Agregar temporada…</option>
                                    <option v-for="t in temporadasDisponibles" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                                </select>
                            </td>
                            <td v-for="campo in camposPrecio" :key="campo">
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="nueva[campo]" :disabled="!nueva.temporada_id">
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-primary" @click="guardar(null)" :disabled="!nueva.temporada_id || guardando">
                                    <span v-if="guardando && editandoId === null" class="spinner-border spinner-border-sm"></span>
                                    <i v-else class="fas fa-plus"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="temporadas.length === 0" class="small text-muted mt-2">No hay temporadas cargadas (se crean en Configuración → Temporadas).</div>

            <!-- Vista previa: qué precio tomaría el tour en una fecha -->
            <div class="d-flex align-items-center flex-wrap gap-2 border-top mt-3 pt-3 small">
                <span class="text-muted">Probar una fecha:</span>
                <div style="width:160px"><CampoFecha v-model="fechaPrueba" /></div>
                <template v-if="resultadoPrueba">
                    <span v-if="resultadoPrueba.temporada" class="badge bg-info-subtle text-info border border-info-subtle">
                        Precio {{ resultadoPrueba.temporada.nombre }}: adulto {{ simbolo }} {{ fmt(resultadoPrueba.precio_temporada?.precio_venta_adulto) }}
                    </span>
                    <span v-else class="badge bg-light text-dark border">Precio base: adulto {{ simbolo }} {{ fmt(paquete.precio_venta_final) }}</span>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Fila 13, fase 1a — precios del tour por temporada
// (plan-tours-venta-simple-costo-real.md §3.12, N16). Agregar, editar y
// quitar; "Probar una fecha" usa la misma resolución que la sesión 1b
// aplicará al cargar el tour en la cotización (TemporadaResolverService).
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import CampoFecha from '@/components/CampoFecha.vue';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import { temporadaService } from '@/services/admin/temporadaService';
import type { PaquetePlantilla, PaquetePlantillaPrecio } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{ paquete: PaquetePlantilla }>();
const emit = defineEmits<{ (e: 'actualizado'): void }>();

const camposPrecio = ['precio_venta_adulto', 'precio_venta_nino', 'precio_venta_adulto_ext', 'precio_venta_nino_ext', 'costo_estimado_persona'] as const;
type CampoPrecio = typeof camposPrecio[number];
type FilaPrecio = Record<CampoPrecio, number | null>;

const numero = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : Number(v));
const fmt = (v: unknown) => (numero(v) === null ? '—' : Number(v).toFixed(2));
const simbolo = computed(() => (props.paquete.moneda === 'USD' ? 'US$' : 'S/'));

const precios = computed<PaquetePlantillaPrecio[]>(() => props.paquete.precios ?? []);
const temporadas = ref<{ id: number; nombre: string }[]>([]);
temporadaService.listar().then((res) => { temporadas.value = res.temporadas ?? []; }).catch(() => { temporadas.value = []; });
const temporadasDisponibles = computed(() => temporadas.value.filter((t) => !precios.value.some((p) => p.temporada_id === t.id)));

const vacia = (): FilaPrecio & { temporada_id: number | null } => ({
    temporada_id: null, precio_venta_adulto: null, precio_venta_nino: null, precio_venta_adulto_ext: null, precio_venta_nino_ext: null, costo_estimado_persona: null,
});
const nueva = ref(vacia());
const editandoId = ref<number | null>(null);
const edicion = ref<FilaPrecio>(vacia());
const guardando = ref(false);

const editar = (p: PaquetePlantillaPrecio) => {
    editandoId.value = p.id;
    edicion.value = Object.fromEntries(camposPrecio.map((c) => [c, numero(p[c])])) as FilaPrecio;
};

const guardar = async (precioId: number | null) => {
    const actual = precioId ? precios.value.find((p) => p.id === precioId)! : null;
    const datos = precioId ? { ...edicion.value, temporada_id: actual!.temporada_id } : nueva.value;
    if (numero(datos.precio_venta_adulto) === null) {
        (Swal as TVueSwalInstance).fire('Error', 'El precio adulto de la temporada es obligatorio.', 'error');
        return;
    }
    guardando.value = true;
    try {
        await paquetePlantillaService.guardarPrecio(props.paquete.id, datos as Partial<PaquetePlantillaPrecio>, precioId);
        nueva.value = vacia();
        editandoId.value = null;
        emit('actualizado');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};

const quitar = async (p: PaquetePlantillaPrecio) => {
    const r = await (Swal as TVueSwalInstance).fire({
        title: 'Quitar precio de temporada',
        text: `Se quita el precio de "${p.temporada?.nombre ?? p.temporada_id}". En esas fechas se usará el precio base.`,
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, quitar', confirmButtonColor: '#d33',
    });
    if (!(r as any).isConfirmed) return;
    try {
        await paquetePlantillaService.eliminarPrecio(props.paquete.id, p.id);
        emit('actualizado');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo quitar', 'error');
    }
};

// ── Probar una fecha ──────────────────────────────────────────────────
const fechaPrueba = ref<string | null>(null);
const resultadoPrueba = ref<Awaited<ReturnType<typeof paquetePlantillaService.precioParaFecha>> | null>(null);
watch([fechaPrueba, precios], async () => {
    resultadoPrueba.value = null;
    if (!fechaPrueba.value) return;
    try {
        resultadoPrueba.value = await paquetePlantillaService.precioParaFecha(props.paquete.id, fechaPrueba.value);
    } catch {
        resultadoPrueba.value = null;
    }
});
</script>

<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-route me-2 text-primary"></i>
                    Salidas Operativas
                </h5>
                <small class="text-muted">{{ total }} salida(s) encontrada(s)</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Desde</label>
                        <CampoFecha v-model="fechaDesde" @update:model-value="list" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hasta</label>
                        <CampoFecha v-model="fechaHasta" @update:model-value="list" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Estado</label>
                        <select class="form-select form-select-sm" v-model="estado" @change="list">
                            <option value="">Todos</option>
                            <option v-for="(etiqueta, valor) in ETIQUETA_ESTADO" :key="valor" :value="valor">{{ etiqueta }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <button class="btn btn-outline-secondary btn-sm w-100" @click="reset">
                            <i class="fas fa-undo me-1"></i>Limpiar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Fecha / Hora</th>
                                <th>Tour</th>
                                <th style="min-width:180px">Guía</th>
                                <th style="min-width:200px">Operación</th>
                                <th class="text-center">Pax</th>
                                <th class="text-center">Reservas</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                                </td>
                            </tr>
                            <tr v-else-if="salidas.length === 0">
                                <td colspan="8" class="text-center py-5 text-muted fst-italic">
                                    <i class="fas fa-inbox opacity-50 fs-4 mb-2 d-block"></i>
                                    Sin salidas registradas en este rango.
                                </td>
                            </tr>
                            <tr v-for="s in salidas" :key="s.id" :class="{ 'table-warning': faltaGuia(s) }">
                                <td class="ps-3">
                                    <div class="fw-semibold">{{ formatFecha(s.fecha) }}</div>
                                    <small class="text-muted" v-if="s.hora">{{ s.hora }}</small>
                                </td>
                                <td>
                                    {{ s.tour_nombre ?? '—' }}
                                    <span v-if="s.privada" class="badge bg-info-subtle text-info-emphasis ms-1">Privada</span>
                                </td>
                                <td>
                                    <div v-if="guiaEditando !== s.id" class="d-flex align-items-center justify-content-between border rounded px-2 py-1 bg-white"
                                        style="cursor:pointer;min-height:31px" @click="guiaEditando = s.id">
                                        <span class="small" :class="{ 'text-muted fst-italic': !s.guia }">{{ s.guia?.nombre ?? 'Sin asignar' }}</span>
                                        <i class="fas fa-pen text-muted small"></i>
                                    </div>
                                    <select v-else class="form-select form-select-sm" :value="s.guia_id ?? ''" autofocus
                                        @change="guardarGuia(s, ($event.target as HTMLSelectElement).value)" @blur="guiaEditando = null">
                                        <option value="">Sin asignar</option>
                                        <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}{{ g.es_referencial ? ' (Referencial)' : '' }}</option>
                                    </select>
                                    <div v-if="faltaGuia(s)" class="small text-warning-emphasis mt-1">
                                        <i class="fas fa-triangle-exclamation me-1"></i>Falta guía
                                    </div>
                                </td>
                                <!-- Fila 13, fase 2b: resumen de servicios y vehículos; se editan en el detalle. -->
                                <td class="small">
                                    <div>
                                        <i class="fas fa-van-shuttle text-muted me-1"></i>
                                        <template v-if="s.vehiculos_total">
                                            {{ s.vehiculos_total }} vehículo(s){{ s.capacidad_total ? ` · ${s.capacidad_total} asientos` : '' }}
                                        </template>
                                        <span v-else class="text-muted fst-italic">Sin vehículo</span>
                                    </div>
                                    <div v-if="s.servicios_total">
                                        <i class="fas fa-list-check text-muted me-1"></i>{{ s.servicios_total - (s.servicios_por_reservar ?? 0) }}/{{ s.servicios_total }} servicios reservados
                                    </div>
                                    <div v-if="s.servicios_sin_proveedor" class="text-warning-emphasis">
                                        <i class="fas fa-triangle-exclamation me-1"></i>{{ s.servicios_sin_proveedor }} servicio(s) sin proveedor
                                    </div>
                                    <div v-if="s.reservas_sin_vehiculo" class="text-warning-emphasis">
                                        <i class="fas fa-triangle-exclamation me-1"></i>{{ s.reservas_sin_vehiculo }} reserva(s) sin vehículo
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="fw-semibold">{{ s.total_pax ?? 0 }}</div>
                                    <!-- Mínimo para salir: cuentan adultos y niños (el infante no). -->
                                    <small v-if="s.pax_minimo" :class="s.bajo_minimo ? 'text-danger fw-semibold' : 'text-muted'">
                                        {{ s.pax_que_cuentan }} / {{ s.pax_minimo }} mín.
                                    </small>
                                </td>
                                <td class="text-center">{{ s.total_reservas ?? 0 }}</td>
                                <td class="text-center">
                                    <span class="badge" :class="CLASE_ESTADO[s.estado]">{{ ETIQUETA_ESTADO[s.estado] }}</span>
                                </td>
                                <td class="text-center pe-3 text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary me-1" title="Ver detalle" @click="abrirDetalle(s.id)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button v-if="siguienteEstado(s)" class="btn btn-sm btn-outline-success me-1"
                                        :title="siguienteEstado(s)!.texto" @click="avanzarEstado(s)">
                                        <i :class="siguienteEstado(s)!.icono"></i>
                                    </button>
                                    <button v-if="enCurso(s)" class="btn btn-sm btn-outline-danger" title="Cancelar salida" @click="confirmarCancelar(s)">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <nav v-if="total > perPageRows" class="mt-3 d-flex justify-content-end">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: currentPage === 1 }">
                    <button class="page-link" @click="currentPage > 1 && (currentPage--, list())">Anterior</button>
                </li>
                <li class="page-item disabled"><span class="page-link">Página {{ currentPage }}</span></li>
                <li class="page-item" :class="{ disabled: salidas.length < perPageRows }">
                    <button class="page-link" @click="currentPage++, list()">Siguiente</button>
                </li>
            </ul>
        </nav>

        <!-- Modal detalle -->
        <div class="modal fade" tabindex="-1" :class="{ show: !!detalle, 'd-block': !!detalle }"
            style="background:rgba(0,0,0,.5)" v-if="detalle">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">
                            Salida {{ formatFecha(detalle.fecha) }}{{ detalle.hora ? ' · ' + detalle.hora : '' }} — {{ detalle.tour_nombre ?? 'Sin tour' }}
                        </h6>
                        <button class="btn-close" @click="detalle = null"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3 small">
                            <div class="col-6"><strong>Guía:</strong> {{ detalle.guia?.nombre ?? 'Sin asignar' }}</div>
                            <div class="col-6"><strong>Estado:</strong> {{ ETIQUETA_ESTADO[detalle.estado] }}<span v-if="detalle.privada"> · privada</span></div>
                            <div class="col-6">
                                <strong>Vehículos:</strong> {{ detalle.vehiculos_total ?? 0 }}{{ detalle.capacidad_total ? ` (${detalle.capacidad_total} asientos)` : '' }}
                            </div>
                            <div class="col-6">
                                <strong>Total pax:</strong> {{ detalle.total_pax ?? 0 }}
                                <span v-if="detalle.pax" class="text-muted">
                                    ({{ detalle.pax.adultos }} adulto(s), {{ detalle.pax.ninos }} niño(s), {{ detalle.pax.infantes }} infante(s){{ detalle.pax.extranjeros ? `, ${detalle.pax.extranjeros} extranjero(s)` : '' }})
                                </span>
                            </div>
                            <div class="col-6">
                                <strong>Mínimo para salir:</strong>
                                <template v-if="detalle.pax_minimo">
                                    <span :class="detalle.bajo_minimo ? 'text-danger fw-semibold' : ''">{{ detalle.pax_que_cuentan }} / {{ detalle.pax_minimo }}</span>
                                    <span v-if="detalle.bajo_minimo" class="text-danger"> · bajo el mínimo</span>
                                </template>
                                <template v-else>— (el tour no lo define)</template>
                            </div>
                            <div class="col-6"><strong>Reservas enganchadas:</strong> {{ detalle.total_reservas ?? 0 }}</div>
                        </div>
                        <template v-if="(detalle.pasajeros ?? []).length > 0">
                            <h6 class="small fw-semibold text-secondary text-uppercase">Pasajeros</h6>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm align-middle mb-0 small">
                                    <thead class="table-light">
                                        <tr><th>Nombre</th><th>Tipo</th><th>Residencia</th><th>Alimentación</th><th>Reserva</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="p in detalle.pasajeros" :key="p.id">
                                            <td>{{ p.nombre ?? '(sin nombre todavía)' }}<div v-if="p.documento" class="text-muted">{{ p.documento }}</div></td>
                                            <td>{{ ETIQUETA_TIPO_PAX[p.tipo_pax ?? ''] ?? '—' }}</td>
                                            <td>{{ p.residencia === 'extranjero' ? 'Extranjero' : 'Nacional' }}</td>
                                            <td>{{ p.alimentacion_especial || '—' }}</td>
                                            <td>{{ p.reserva_codigo ?? ('#' + p.reserva_id) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <!-- Fila 13, fase 2b (D10-D13) -->
                        <SalidaServiciosVehiculos class="mb-3" :salida="detalle" :guias="guias" @cambio="recargarDetalle" />
                        <h6 class="small fw-semibold text-secondary text-uppercase">Reservas</h6>
                        <ul class="list-group">
                            <li v-for="r in detalle.reservas ?? []" :key="r.id" class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <div>
                                    <div class="fw-semibold small">{{ r.codigo ?? ('Reserva #' + r.id) }}</div>
                                    <small class="text-muted">{{ r.cliente ?? 'Sin cliente' }} · {{ r.total_pax }} pax</small>
                                </div>
                                <router-link :to="`/agencia-viajes/reservas/${r.id}`" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-arrow-right me-1"></i>Abrir
                                </router-link>
                            </li>
                            <li v-if="(detalle.reservas ?? []).length === 0" class="list-group-item text-muted small fst-italic text-center py-3">
                                Sin reservas enganchadas todavía.
                            </li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button v-if="estadoAnterior(detalle)" class="btn btn-outline-secondary btn-sm me-auto" @click="volverEstado(detalle)">
                            <i class="fas fa-rotate-left me-1"></i>Volver a {{ ETIQUETA_ESTADO[estadoAnterior(detalle)!].toLowerCase() }}
                        </button>
                        <button v-if="enCurso(detalle)" class="btn btn-outline-danger btn-sm" @click="confirmarCancelar(detalle)">
                            <i class="fas fa-ban me-1"></i>Cancelar salida
                        </button>
                        <button v-if="siguienteEstado(detalle)" class="btn btn-success btn-sm" @click="avanzarEstado(detalle)">
                            <i :class="siguienteEstado(detalle)!.icono" class="me-1"></i>{{ siguienteEstado(detalle)!.texto }}
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" @click="detalle = null">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import CampoFecha from '@/components/CampoFecha.vue';
import SalidaServiciosVehiculos from '@/components/AgenciaViajes/SalidaServiciosVehiculos.vue';
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { salidaOperativaService } from '@/services/admin/salidaOperativaService';
import { guiaService } from '@/services/admin/guiaService';
import { useToast } from '@/composables/useToast';
import { formatFecha, hoyPeru, sumarDiasISO } from '@/helpers/fecha';
import type { SalidaOperativa, Guia, EstadoSalidaOperativa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

// Fila 13, fase 2a (D9): programada → confirmada → realizada, o cancelada.
const ETIQUETA_ESTADO: Record<EstadoSalidaOperativa, string> = {
    programada: 'Programada',
    confirmada: 'Confirmada',
    realizada: 'Realizada',
    cancelada: 'Cancelada',
};
const CLASE_ESTADO: Record<EstadoSalidaOperativa, string> = {
    programada: 'bg-primary-subtle text-primary-emphasis',
    confirmada: 'bg-success',
    realizada: 'bg-secondary',
    cancelada: 'bg-danger-subtle text-danger-emphasis',
};
const ETIQUETA_TIPO_PAX: Record<string, string> = { adulto: 'Adulto', nino: 'Niño', infante: 'Infante' };

const enCurso = (s: SalidaOperativa) => s.estado === 'programada' || s.estado === 'confirmada';
const siguienteEstado = (s: SalidaOperativa): { estado: 'confirmada' | 'realizada'; texto: string; icono: string } | null => {
    if (s.estado === 'programada') return { estado: 'confirmada', texto: 'Confirmar salida', icono: 'fas fa-check' };
    // Realizada solo cuando ya llegó la fecha (el backend también lo exige).
    if (s.estado === 'confirmada' && s.fecha.slice(0, 10) <= hoyPeru()) return { estado: 'realizada', texto: 'Marcar realizada', icono: 'fas fa-flag-checkered' };
    return null;
};
const estadoAnterior = (s: SalidaOperativa): 'programada' | 'confirmada' | null =>
    s.estado === 'confirmada' ? 'programada' : s.estado === 'realizada' ? 'confirmada' : null;

const route = useRoute();
const toast = useToast();

const salidas = ref<SalidaOperativa[]>([]);
const guias = ref<Guia[]>([]);
const total = ref<number>(0);
const currentPage = ref<number>(1);
const perPageRows = ref<number>(20);
const loading = ref<boolean>(false);

const fechaDesde = ref<string>(hoyPeru());
const fechaHasta = ref<string>(sumarDiasISO(hoyPeru(), 7));
const estado = ref<'' | EstadoSalidaOperativa>('');

const guiaEditando = ref<number | null>(null);
const detalle = ref<SalidaOperativa | null>(null);

const faltaGuia = (s: SalidaOperativa) => enCurso(s) && !s.guia_id;

const cambiarEstado = async (s: SalidaOperativa, nuevo: 'programada' | 'confirmada' | 'realizada') => {
    try {
        const res = await salidaOperativaService.cambiarEstado(s.id, nuevo);
        toast.success(res.message);
        if (detalle.value?.id === s.id) detalle.value = res.salida;
        await list();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo cambiar el estado de la salida');
    }
};
const avanzarEstado = (s: SalidaOperativa) => {
    const siguiente = siguienteEstado(s);
    if (siguiente) cambiarEstado(s, siguiente.estado);
};
const volverEstado = (s: SalidaOperativa) => {
    const anterior = estadoAnterior(s);
    if (anterior) cambiarEstado(s, anterior);
};

const list = async () => {
    loading.value = true;
    try {
        const res = await salidaOperativaService.listar({
            fecha_desde: fechaDesde.value || undefined,
            fecha_hasta: fechaHasta.value || undefined,
            estado: estado.value || undefined,
        });
        salidas.value = res.salidas;
        total.value = res.total;
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudieron cargar las salidas');
    } finally {
        loading.value = false;
    }
};

const reset = () => {
    fechaDesde.value = hoyPeru();
    fechaHasta.value = sumarDiasISO(hoyPeru(), 7);
    estado.value = '';
    currentPage.value = 1;
    list();
};

const guardarGuia = async (s: SalidaOperativa, valor: string) => {
    const guiaId = valor ? Number(valor) : null;
    try {
        await salidaOperativaService.actualizar(s.id, { guia_id: guiaId });
        s.guia_id = guiaId;
        s.guia = guiaId ? guias.value.find((g) => g.id === guiaId) ?? null : null;
        toast.success('Guía actualizado');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo actualizar el guía');
    } finally {
        guiaEditando.value = null;
    }
};

// Fila 13, fase 2b: tras cambiar un servicio o vehículo, recarga el detalle
// (cantidades, asientos, avisos) y la fila del tablero.
const recargarDetalle = async () => {
    if (!detalle.value) return;
    const id = detalle.value.id;
    await abrirDetalle(id);
    const res = await salidaOperativaService.listar({
        fecha_desde: fechaDesde.value || undefined,
        fecha_hasta: fechaHasta.value || undefined,
        estado: estado.value || undefined,
    });
    salidas.value = res.salidas;
};

const abrirDetalle = async (id: number) => {
    try {
        const res = await salidaOperativaService.ver(id);
        detalle.value = res;
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo cargar el detalle de la salida');
    }
};

const confirmarCancelar = async (s: SalidaOperativa) => {
    const confirmacion = await (Swal as TVueSwalInstance).fire({
        title: '¿Cancelar esta salida?',
        text: 'Esto NO cancela las reservas enganchadas — cada una se resuelve a mano, es plata de un cliente. Solo queda marcada como aviso visual.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar salida',
        cancelButtonText: 'Volver',
    });
    if (!confirmacion.isConfirmed) return;

    try {
        const res = await salidaOperativaService.cancelar(s.id);
        toast.success(res.message);
        if (detalle.value?.id === s.id) detalle.value = null;
        await list();
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo cancelar la salida');
    }
};

onMounted(async () => {
    await list();
    const gs = await guiaService.listar({ page: 1 });
    guias.value = gs.guias ?? [];

    const idParam = route.params.id ? Number(route.params.id) : null;
    if (idParam) {
        await abrirDetalle(idParam);
    }
});
</script>

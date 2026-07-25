<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>Series de Comprobantes</b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3">
                        <b-col lg="3">
                            <b-form-select v-model="filtroBranchId" size="sm" @change="reset">
                                <option :value="null">— Todas las sucursales —</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </b-form-select>
                        </b-col>
                        <b-col lg="3">
                            <b-form-select v-model="filtroTipoCodigo" size="sm" @change="reset">
                                <option :value="null">— Todos los tipos —</option>
                                <option v-for="t in tiposComprobanteTodos" :key="t.codigo" :value="t.codigo">
                                    {{ t.codigo }} — {{ t.nombre }}
                                </option>
                            </b-form-select>
                        </b-col>
                        <b-col lg="3">
                            <b-button type="button" @click="reset" variant="dark">
                                <i class="fas fa-sync"></i>
                            </b-button>
                        </b-col>
                        <b-col lg="3">
                            <b-button type="button" variant="success" @click="openCreateModal">
                                <i class="far fa-plus-square ml-2"></i> Registrar
                            </b-button>
                        </b-col>
                    </b-row>
                </b-card-header>

                <b-card-body class="pt-0 mt-2">
                    <b-table-simple responsive class="mb-0 table-centered">
                        <b-thead class="table-light">
                            <b-tr>
                                <b-th>Sucursal</b-th>
                                <b-th>Tipo de Comprobante</b-th>
                                <b-th>Moneda</b-th>
                                <b-th>Serie</b-th>
                                <b-th>Correlativo actual</b-th>
                                <b-th>Estado</b-th>
                                <b-th class="text-end">Acción</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="item in series" :key="item.id">
                                <b-td>{{ item.branch?.name ?? item.branch_id }}</b-td>
                                <b-td>{{ nombreTipo(item.tipo_comprobante_codigo) }}</b-td>
                                <b-td>{{ item.moneda }}</b-td>
                                <b-td>{{ item.serie }}</b-td>
                                <b-td>{{ item.correlativo_actual }}</b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="item.activo">Activo</b-badge>
                                    <b-badge variant="danger" v-else>Inactivo</b-badge>
                                </b-td>
                                <b-td class="text-end">
                                    <a href="#" @click.prevent="editItem(item)"><i
                                            class="las la-pen text-secondary fs-22"></i></a>{{ " " }}
                                    <a href="#" @click.prevent="toggleActive(item)"
                                        :title="item.activo ? 'Desactivar' : 'Activar'"><i
                                            :class="item.activo ? 'las la-ban text-secondary fs-22' : 'las la-check-circle text-secondary fs-22'"></i></a>
                                </b-td>
                            </b-tr>
                        </b-tbody>
                    </b-table-simple>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Previous" next-text="Next" />
                </b-card-body>
            </b-col>
        </b-row>

        <b-modal v-model="ModalRegister" :title="`${itemSelected ? 'Edición' : 'Registro'} de Serie de Comprobante`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered size="lg">
            <b-row>
                <b-col lg="6">
                    <label class="col-form-label">Sucursal:</label>
                    <b-form-select v-model="branch_id" :disabled="yaTieneCorrelativos">
                        <option :value="null" disabled>Selecciona una sucursal</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </b-form-select>
                </b-col>
                <b-col lg="6">
                    <label class="col-form-label">Tipo de comprobante:</label>
                    <b-form-select v-model="tipo_comprobante_codigo" :disabled="yaTieneCorrelativos">
                        <option :value="null" disabled>Selecciona un tipo</option>
                        <option v-for="t in tiposComprobanteDisponibles" :key="t.codigo" :value="t.codigo">
                            {{ t.codigo }} — {{ t.nombre }}
                        </option>
                    </b-form-select>
                </b-col>
                <b-col lg="6" class="mt-3">
                    <label class="col-form-label">Moneda:</label>
                    <b-form-select v-model="moneda" :disabled="yaTieneCorrelativos">
                        <option value="PEN">Soles (PEN)</option>
                        <option value="USD">Dólares (USD)</option>
                    </b-form-select>
                </b-col>
                <b-col lg="6" class="mt-3">
                    <label class="col-form-label">Serie:</label>
                    <b-form-input type="text" v-model="serie" placeholder="Ej. F001, B001, NV001"
                        :disabled="yaTieneCorrelativos" />
                </b-col>
                <b-col lg="6" class="mt-3">
                    <label class="col-form-label">Correlativo inicial:</label>
                    <b-form-input type="number" min="1" v-model.number="correlativo_inicial"
                        :disabled="yaTieneCorrelativos" />
                </b-col>
                <b-col lg="6" class="mt-3">
                    <label class="col-form-label">Fecha de inicio:</label>
                    <b-form-input type="date" v-model="fecha_inicio" :disabled="yaTieneCorrelativos" />
                </b-col>
                <b-col lg="6" class="mt-3 d-flex align-items-end">
                    <b-form-checkbox v-model="activo" switch>Activo</b-form-checkbox>
                </b-col>

                <b-col lg="12" class="mt-2" v-if="yaTieneCorrelativos">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Esta serie ya tiene correlativos reservados — solo se puede activar/desactivar.
                    </small>
                </b-col>

                <b-col lg="12" class="mt-3">
                    <div class="modal-footer">
                        <b-button type="button" variant="secondary" @click="ModalRegister = false"
                            data-bs-dismiss="modal">
                            Cerrar
                        </b-button>
                        <b-button type="button" variant="primary" @click="store">
                            {{ itemSelected ? 'Actualizar' : 'Registrar' }}
                        </b-button>
                    </div>
                </b-col>
            </b-row>
        </b-modal>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import { ref, computed, onMounted, watch } from 'vue';

import Swal from "sweetalert2/dist/sweetalert2.js";
import type { Branch } from '@/types/cash-session';
import type {
    SerieComprobante,
    SeriesComprobante,
    SerieComprobanteResponse,
    TipoComprobante,
    TiposComprobanteResponse,
} from '@/types/series-comprobante';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const ModalRegister = ref<Boolean>(false);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(20);

const themeColor = ref<string>('primary');

const series = ref<SerieComprobante[]>([]);
const branches = ref<Branch[]>([]);
// Catálogo completo (para mostrar nombre en la tabla y el filtro) — distinto
// del catálogo filtrado que ofrece el formulario de creación (ver
// tiposComprobanteDisponibles), que solo lista lo que sí se puede emitir hoy.
const tiposComprobanteTodos = ref<TipoComprobante[]>([]);
const tiposComprobanteDisponibles = ref<TipoComprobante[]>([]);

const filtroBranchId = ref<number | null>(null);
const filtroTipoCodigo = ref<string | null>(null);

const branch_id = ref<number | null>(null);
const tipo_comprobante_codigo = ref<string | null>(null);
const moneda = ref<'PEN' | 'USD'>('PEN');
const serie = ref<string>('');
const correlativo_inicial = ref<number>(1);
const fecha_inicio = ref<string>(new Date().toISOString().slice(0, 10));
const activo = ref<boolean>(true);

const itemSelected = ref<SerieComprobante | undefined>(undefined);

// Una serie con correlativo_actual > 0 ya generó comprobantes reales — el
// backend bloquea cualquier cambio salvo activo/inactivo (misma regla en
// SerieComprobanteController::update()), el form solo refleja esa regla.
const yaTieneCorrelativos = computed(() => (itemSelected.value?.correlativo_actual ?? 0) > 0);

const nombreTipo = (codigo: string) => {
    const tipo = tiposComprobanteTodos.value.find((t) => t.codigo === codigo);
    return tipo ? `${tipo.codigo} — ${tipo.nombre}` : codigo;
};

const list = async () => {
    try {
        const params = new URLSearchParams();
        params.set('page', String(currentPage.value));
        if (filtroBranchId.value) params.set('branch_id', String(filtroBranchId.value));
        if (filtroTipoCodigo.value) params.set('tipo_comprobante_codigo', filtroTipoCodigo.value);

        const res: AxiosResponse<SeriesComprobante> = await httpClient.get(`series-comprobante?${params.toString()}`);

        series.value = res.data.series_comprobante;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;
    } catch (error) {
        console.log(error);
    }
};

const reset = () => {
    currentPage.value = 1;
    list();
};

const cargarCatalogos = async () => {
    try {
        const [resBranches, resTiposTodos, resTiposDisponibles] = await Promise.all([
            httpClient.get<{ branches: Branch[] }>('branches?active=1'),
            httpClient.get<TiposComprobanteResponse>('tipos-comprobante'),
            httpClient.get<TiposComprobanteResponse>('tipos-comprobante?disponibles_para_serie=1'),
        ]);

        branches.value = resBranches.data.branches;
        tiposComprobanteTodos.value = resTiposTodos.data.tipos_comprobante;
        tiposComprobanteDisponibles.value = resTiposDisponibles.data.tipos_comprobante;
    } catch (error) {
        console.log(error);
    }
};

const clearFields = () => {
    branch_id.value = null;
    tipo_comprobante_codigo.value = null;
    moneda.value = 'PEN';
    serie.value = '';
    correlativo_inicial.value = 1;
    fecha_inicio.value = new Date().toISOString().slice(0, 10);
    activo.value = true;
};

const openCreateModal = () => {
    itemSelected.value = undefined;
    clearFields();
    ModalRegister.value = true;
};

const store = async () => {
    try {
        if (!yaTieneCorrelativos.value) {
            if (!branch_id.value || !tipo_comprobante_codigo.value || serie.value.trim() === '') {
                (Swal as TVueSwalInstance).fire('Error', 'Sucursal, tipo de comprobante y serie son obligatorios.', 'error');
                return;
            }
        }

        const payload = yaTieneCorrelativos.value
            ? { activo: activo.value }
            : {
                branch_id: branch_id.value,
                tipo_comprobante_codigo: tipo_comprobante_codigo.value,
                moneda: moneda.value,
                serie: serie.value,
                correlativo_inicial: correlativo_inicial.value,
                fecha_inicio: fecha_inicio.value,
                activo: activo.value,
            };

        const res: AxiosResponse<SerieComprobanteResponse> =
            !itemSelected.value
                ? await httpClient.post("series-comprobante", payload)
                : await httpClient.put("series-comprobante/" + itemSelected.value.id, payload);

        ModalRegister.value = false;
        (Swal as TVueSwalInstance).fire("Felicitaciones!", res.data.message, "success");
        reset();
    } catch (error: any) {
        console.log(error);
        if (error.response?.data?.message) {
            (Swal as TVueSwalInstance).fire('Error', error.response.data.message, 'error');
        }
    }
};

const editItem = (item: SerieComprobante) => {
    itemSelected.value = item;
    branch_id.value = item.branch_id;
    tipo_comprobante_codigo.value = item.tipo_comprobante_codigo;
    moneda.value = item.moneda;
    serie.value = item.serie;
    correlativo_inicial.value = item.correlativo_inicial;
    fecha_inicio.value = item.fecha_inicio;
    activo.value = item.activo;
    ModalRegister.value = true;
};

const toggleActive = (item: SerieComprobante) => {
    (Swal as TVueSwalInstance)
        .fire({
            title: item.activo ? 'Confirmar desactivación' : 'Confirmar activación',
            text: item.activo
                ? `¿Desactivar la serie '${item.serie}'? Los comprobantes ya emitidos con ella no se ven afectados.`
                : `¿Activar la serie '${item.serie}'?`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            cancelButtonColor: "#d33",
            confirmButtonText: "Sí, continuar",
        })
        .then(async (result: any) => {
            if (!result.isConfirmed) return;

            try {
                if (item.activo) {
                    await httpClient.delete("series-comprobante/" + item.id);
                } else {
                    await httpClient.put("series-comprobante/" + item.id, { activo: true });
                }

                item.activo = !item.activo;
            } catch (error) {
                console.log(error);
            }
        });
};

onMounted(() => {
    cargarCatalogos();
    list();
});

watch(ModalRegister, (value) => {
    if (!value) {
        itemSelected.value = undefined;
        clearFields();
    }
});

watch(currentPage, () => list());
</script>

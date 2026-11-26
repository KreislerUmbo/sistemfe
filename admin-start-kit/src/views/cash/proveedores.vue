<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>Proveedores</b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3 g-2">
                        <b-col lg="5" class="text-center">
                            <b-form-input type="text" id="search" size="sm" v-model="search"
                                placeholder="Buscar por nombre o documento" @keyup.enter="buscar" />
                        </b-col>
                        <b-col lg="2" md="3">
                            <b-button type="button" size="sm" @click="buscar" variant="success">
                                <i class="fas fa-search"></i>
                            </b-button>
                            <b-button type="button" size="sm" @click="reset" variant="dark" class="mx-2">
                                <i class="fas fa-sync"></i>
                            </b-button>
                        </b-col>
                        <b-col lg="2">
                            <b-form-checkbox v-model="sinDocumento" switch @change="buscar">Sin documento</b-form-checkbox>
                        </b-col>
                        <b-col lg="3" class="text-lg-end">
                            <template v-if="puedeGestionar">
                                <b-button type="button" size="sm" variant="outline-primary" class="me-2"
                                    @click="altaRapida = true">
                                    <i class="fas fa-bolt me-1"></i>Alta rápida
                                </b-button>
                                <b-button type="button" size="sm" variant="success" @click="ModalRegister = true">
                                    <i class="far fa-plus-square me-1"></i>Registrar
                                </b-button>
                            </template>
                        </b-col>
                    </b-row>
                </b-card-header>

                <b-card-body class="pt-0 mt-2">
                    <b-table-simple responsive class="mb-0 table-centered">
                        <b-thead class="table-light">
                            <b-tr>
                                <b-td>Nro</b-td>
                                <b-th>Nombre</b-th>
                                <b-th>RUC/DNI</b-th>
                                <b-th>Teléfono</b-th>
                                <b-th>Estado</b-th>
                                <b-th>Fecha Registro</b-th>
                                <b-th v-if="puedeGestionar" class="text-end">Acción</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(item, index) in proveedores" :key="item.id">
                                <b-td>{{ (currentPage - 1) * perPageRows + index + 1 }}</b-td>
                                <b-td>
                                    {{ nombreConCiudad(item.razon_social, item.ciudad) }}
                                    <small v-if="item.nombre_comercial" class="text-muted d-block">{{ item.nombre_comercial }}</small>
                                    <b-badge v-if="item.es_no_domiciliado" variant="info" class="me-1">
                                        No domiciliado · {{ item.pais }}
                                    </b-badge>
                                    <b-badge v-if="item.datos_faltantes.length" variant="warning"
                                        :title="`Falta: ${item.datos_faltantes.join(', ')}`">
                                        Datos incompletos
                                    </b-badge>
                                </b-td>
                                <b-td>{{ item.numero_documento }}</b-td>
                                <b-td>
                                    <template v-if="item.datos_faltantes.includes('teléfono')">
                                        <span class="text-warning small">Sin teléfono</span>
                                        <a v-if="puedeGestionar" href="#" class="small d-block"
                                            @click.prevent="completarTelefono(item)">Agregar</a>
                                    </template>
                                    <template v-else>{{ item.telefono || item.celular }}</template>
                                </b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="item.estado">Activo</b-badge>
                                    <b-badge variant="danger" v-else>Inactivo</b-badge>
                                </b-td>
                                <b-td>{{ formatFechaHora(item.created_at) }}</b-td>
                                <b-td v-if="puedeGestionar" class="text-end">
                                    <a href="#" title="Editar" @click.prevent="editItem(item)"><i
                                            class="las la-pen text-secondary fs-22"></i></a>{{ " " }}
                                    <a href="#" @click.prevent="toggleActive(item)" :title="item.estado ? 'Desactivar' : 'Activar'"><i
                                            :class="item.estado ? 'las la-ban text-secondary fs-22' : 'las la-check-circle text-secondary fs-22'"></i></a>
                                </b-td>
                            </b-tr>
                            <b-tr v-if="proveedores.length === 0">
                                <b-td :colspan="puedeGestionar ? 7 : 6" class="text-center text-muted py-4">
                                    {{ sinDocumento ? 'No hay proveedores sin documento.' : 'No se encontraron proveedores.' }}
                                </b-td>
                            </b-tr>
                        </b-tbody>
                    </b-table-simple>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Previous" next-text="Next" />
                </b-card-body>
            </b-col>
        </b-row>

        <ProveedorAltaRapida v-model="altaRapida" @creado="proveedorCreado" @existente="abrirExistente" />

        <b-modal v-model="ModalRegister" :title="`${itemSelected ? 'Edición' : 'Registro'} de Proveedor`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered size="lg">
            <div class="row g-2">
                <div class="col-12">
                    <b-form-checkbox v-model="form.es_no_domiciliado" switch @change="onCambiarDomicilio">
                        No domiciliado (del extranjero)
                    </b-form-checkbox>
                </div>
                <div v-if="form.es_no_domiciliado" class="col-md-4">
                    <label for="proveedor-pais" class="form-label small mb-1">País *</label>
                    <input id="proveedor-pais" v-model="form.pais" type="text" class="form-control form-control-sm" maxlength="80">
                </div>
                <div class="col-md-4">
                    <label for="proveedor-tipo-documento" class="form-label small mb-1">Tipo de documento</label>
                    <input v-if="form.es_no_domiciliado" id="proveedor-tipo-documento" v-model="form.tipo_documento" type="text"
                        class="form-control form-control-sm" maxlength="20" placeholder="NIT, CNPJ, Tax ID…">
                    <select v-else id="proveedor-tipo-documento" v-model="form.tipo_documento" class="form-select form-select-sm">
                        <option value="">—</option>
                        <option value="RUC">RUC</option>
                        <option value="DNI">DNI</option>
                        <option value="CE">Carné Extranjería</option>
                        <option value="PASAPORTE">Pasaporte</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="proveedor-documento" class="form-label small mb-1">Número (opcional)</label>
                    <input id="proveedor-documento" v-model="form.numero_documento" type="text"
                        class="form-control form-control-sm" maxlength="30">
                </div>
                <div class="col-md-7">
                    <label for="proveedor-razon-social" class="form-label small mb-1">Nombre / Razón social *</label>
                    <input id="proveedor-razon-social" v-model="form.razon_social" type="text"
                        class="form-control form-control-sm" maxlength="250">
                </div>
                <div class="col-md-5">
                    <label for="proveedor-nombre-comercial" class="form-label small mb-1">Nombre comercial</label>
                    <input id="proveedor-nombre-comercial" v-model="form.nombre_comercial" type="text"
                        class="form-control form-control-sm" maxlength="250">
                </div>
                <div class="col-md-8">
                    <label for="proveedor-dir-fiscal" class="form-label small mb-1">Dirección fiscal</label>
                    <input id="proveedor-dir-fiscal" v-model="form.direccion_fiscal" type="text" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label for="proveedor-ciudad" class="form-label small mb-1">Ciudad</label>
                    <input id="proveedor-ciudad" v-model="form.ciudad" type="text" class="form-control form-control-sm" maxlength="120">
                </div>
                <div class="col-md-8">
                    <label for="proveedor-dir-local" class="form-label small mb-1">Dirección del local (si es distinta)</label>
                    <input id="proveedor-dir-local" v-model="form.direccion" type="text" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label for="proveedor-telefono" class="form-label small mb-1">Teléfono</label>
                    <input id="proveedor-telefono" v-model="form.telefono" type="text" class="form-control form-control-sm" maxlength="50">
                    <small v-if="!form.telefono.trim()" class="text-warning d-block">Sin teléfono: no habrá cómo escribirle.</small>
                </div>
                <div class="col-12">
                    <b-form-checkbox v-model="form.estado" switch>Activo</b-form-checkbox>
                </div>

                <div v-if="existentes.length" class="col-12">
                    <AvisoDocumentoRepetido :existentes="existentes" :guardando="guardando" @usar="abrirExistente"
                        @crear="store(true)" />
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                    <b-button type="button" size="sm" variant="secondary" @click="ModalRegister = false">Cerrar</b-button>
                    <b-button type="button" size="sm" variant="primary" :disabled="guardando || existentes.length > 0"
                        @click="store(false)">
                        {{ itemSelected ? 'Actualizar' : 'Registrar' }}
                    </b-button>
                </div>
            </div>
        </b-modal>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import AvisoDocumentoRepetido from '@/components/Proveedores/AvisoDocumentoRepetido.vue';
import ProveedorAltaRapida from '@/components/Proveedores/ProveedorAltaRapida.vue';
import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { formatFechaHora } from '@/helpers/fecha';
import { nombreConCiudad } from '@/helpers/proveedor';
import { useAuthStore } from '@/stores/auth';

import Swal from "sweetalert2/dist/sweetalert2.js";
import type { ProveedorBase, ProveedoresBase, ProveedorBaseResponse, ProveedorExistente } from '@/types/cash';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

// T-133 — catálogo de proveedores del sistema base (todos: turísticos y generales).
const authStore = useAuthStore();
const puedeGestionar = computed(() => authStore.isPermitedRoute('proveedores.gestionar'));

const ModalRegister = ref<boolean>(false);
const altaRapida = ref<boolean>(false);
const search = ref<string | null>(null);
const sinDocumento = ref<boolean>(false);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);

const themeColor = ref<string>('primary');

const proveedores = ref<ProveedorBase[]>([]);

const vacio = () => ({
    es_no_domiciliado: false,
    pais: '',
    tipo_documento: '',
    numero_documento: '',
    razon_social: '',
    nombre_comercial: '',
    direccion_fiscal: '',
    ciudad: '',
    direccion: '',
    telefono: '',
    estado: true,
});
const form = reactive(vacio());

const itemSelected = ref<ProveedorBase | undefined>(undefined);
const existentes = ref<ProveedorExistente[]>([]);
const guardando = ref(false);

const mostrarError = (error: any) => {
    console.log(error);
    const mensaje = error.response?.data?.message ?? 'No se pudo completar la operación.';
    (Swal as TVueSwalInstance).fire('Error', mensaje, 'error');
};

const list = async () => {
    try {
        const res: AxiosResponse<ProveedoresBase> = await httpClient.get('proveedores-base', {
            params: {
                page: currentPage.value,
                search: search.value || undefined,
                sin_documento: sinDocumento.value ? 1 : undefined,
            },
        });

        proveedores.value = res.data.proveedores;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;
    } catch (error) {
        mostrarError(error);
    }
};

// Cambiar un filtro vuelve a la primera página.
const buscar = () => {
    if (currentPage.value === 1) list();
    else currentPage.value = 1; // el watch recarga
};

const reset = () => {
    search.value = '';
    sinDocumento.value = false;
    buscar();
};

const store = async (confirmarDuplicado: boolean) => {
    if (form.razon_social.trim() === '') {
        (Swal as TVueSwalInstance).fire('Error', 'El nombre del proveedor es obligatorio.', 'error');
        return;
    }

    guardando.value = true;
    try {
        const payload = { ...form, confirmar_duplicado: confirmarDuplicado };

        const res: AxiosResponse<ProveedorBaseResponse> =
            !itemSelected.value
                ? await httpClient.post('proveedores-base', payload)
                : await httpClient.put('proveedores-base/' + itemSelected.value.id, payload);

        ModalRegister.value = false;
        (Swal as TVueSwalInstance).fire("Felicitaciones!", res.data.message, "success");
        reset();
    } catch (error: any) {
        if (error.response?.data?.duplicado) {
            existentes.value = error.response.data.existentes;
            return;
        }
        mostrarError(error);
    } finally {
        guardando.value = false;
    }
};

const editItem = (item: ProveedorBase) => {
    existentes.value = [];
    itemSelected.value = item;
    Object.assign(form, {
        es_no_domiciliado: item.es_no_domiciliado,
        pais: item.pais ?? '',
        tipo_documento: item.tipo_documento ?? '',
        numero_documento: item.numero_documento ?? '',
        razon_social: item.razon_social,
        nombre_comercial: item.nombre_comercial ?? '',
        direccion_fiscal: item.direccion_fiscal ?? '',
        ciudad: item.ciudad ?? '',
        direccion: item.direccion ?? '',
        telefono: item.telefono ?? '',
        estado: item.estado,
    });
    ModalRegister.value = true;
};

const abrirExistente = async (proveedor: ProveedorExistente) => {
    try {
        const { data } = await httpClient.get(`proveedores-base/${proveedor.id}`);
        editItem(data.proveedor);
    } catch (error) {
        mostrarError(error);
    }
};

const proveedorCreado = () => {
    (Swal as TVueSwalInstance).fire('Listo', 'Proveedor registrado correctamente', 'success');
    reset();
};

// El documento peruano y el del exterior no comparten tipo.
const onCambiarDomicilio = () => {
    form.tipo_documento = '';
    form.pais = '';
};

const completarTelefono = async (item: ProveedorBase) => {
    const { value } = await (Swal as TVueSwalInstance).fire({
        title: 'Teléfono',
        text: item.razon_social,
        input: 'tel',
        inputAttributes: { maxlength: '50' },
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
    });
    if (!value?.trim()) return;

    try {
        await httpClient.put('proveedores-base/' + item.id, { telefono: value.trim() });
        list();
    } catch (error) {
        mostrarError(error);
    }
};

const toggleActive = (item: ProveedorBase) => {
    (Swal as TVueSwalInstance)
        .fire({
            title: item.estado ? 'Confirmar desactivación' : 'Confirmar activación',
            text: item.estado
                ? `¿Desactivar al proveedor '${item.razon_social}'?`
                : `¿Activar al proveedor '${item.razon_social}'?`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            cancelButtonColor: "#d33",
            confirmButtonText: "Sí, continuar",
        })
        .then(async (result: any) => {
            if (!result.isConfirmed) return;

            try {
                await httpClient.put('proveedores-base/' + item.id, { estado: !item.estado });
                item.estado = !item.estado;
            } catch (error) {
                mostrarError(error);
            }
        });
};

onMounted(() => {
    list();
});

watch(ModalRegister, (value) => {
    if (!value) {
        itemSelected.value = undefined;
        existentes.value = [];
        Object.assign(form, vacio());
    }
});

// El aviso era del documento anterior.
watch(() => [form.numero_documento, form.es_no_domiciliado, form.pais], () => (existentes.value = []));

watch(currentPage, () => list());
</script>

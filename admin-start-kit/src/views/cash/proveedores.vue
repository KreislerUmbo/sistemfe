<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>Proveedores</b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3">
                        <b-col lg="7" class="text-center">
                            <b-form-input type="text" id="search" size="sm" v-model="search"
                                placeholder="Buscar por nombre o documento" @keyup.enter="list" />
                        </b-col>
                        <b-col lg="3" md="3">
                            <b-button type="button" size="sm" @click="list" variant="success">
                                <i class="fas fa-search"></i>
                            </b-button>
                            <b-button type="button" size="sm" @click="reset" variant="dark" class="mx-2">
                                <i class="fas fa-sync"></i>
                            </b-button>
                        </b-col>
                        <b-col lg="2">
                            <b-button v-if="puedeGestionar" type="button" size="sm" variant="success"
                                @click="ModalRegister = !ModalRegister">
                                <i class="far fa-plus-square ml-2"></i> Registrar
                            </b-button>
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
                                <b-td>{{ item.razon_social }}</b-td>
                                <b-td>{{ item.numero_documento }}</b-td>
                                <b-td>{{ item.telefono }}</b-td>
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
                        </b-tbody>
                    </b-table-simple>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Previous" next-text="Next" />
                </b-card-body>
            </b-col>
        </b-row>

        <b-modal v-model="ModalRegister" :title="`${itemSelected ? 'Edición' : 'Registro'} de Proveedor`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered size="lg">
            <b-row>
                <b-col lg="6">
                    <label for="proveedor-razon-social" class="col-form-label text-lg-end">Nombre / Razón social: </label>
                    <b-form-input type="text" id="proveedor-razon-social" size="sm" v-model="razonSocial" />
                </b-col>
                <b-col lg="6">
                    <label for="proveedor-documento" class="col-form-label text-lg-end">RUC/DNI (opcional): </label>
                    <b-form-input type="text" id="proveedor-documento" size="sm" maxlength="30" v-model="numeroDocumento" />
                </b-col>
                <b-col lg="6" class="mt-3">
                    <label for="proveedor-telefono" class="col-form-label text-lg-end">Teléfono (opcional): </label>
                    <b-form-input type="text" id="proveedor-telefono" size="sm" maxlength="50" v-model="telefono" />
                </b-col>
                <b-col lg="6" class="mt-3 d-flex align-items-end">
                    <b-form-checkbox v-model="estado" switch>Activo</b-form-checkbox>
                </b-col>

                <b-col lg="12" class="mt-3">
                    <div class="modal-footer">
                        <b-button type="button" variant="secondary"
                            @click="ModalRegister = !ModalRegister" data-bs-dismiss="modal">
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
import { formatFechaHora } from '@/helpers/fecha';
import { useAuthStore } from '@/stores/auth';

import Swal from "sweetalert2/dist/sweetalert2.js";
import type { ProveedorBase, ProveedoresBase, ProveedorBaseResponse } from '@/types/cash';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

// T-133 — catálogo de proveedores del sistema base (todos: turísticos y generales).
const authStore = useAuthStore();
const puedeGestionar = computed(() => authStore.isPermitedRoute('proveedores.gestionar'));

const ModalRegister = ref<Boolean>(false);
const search = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);

const themeColor = ref<string>('primary');

const proveedores = ref<ProveedorBase[]>([]);

const razonSocial = ref<string>("");
const numeroDocumento = ref<string>("");
const telefono = ref<string>("");
const estado = ref<boolean>(true);

const itemSelected = ref<ProveedorBase | undefined>(undefined);

const mostrarError = (error: any) => {
    console.log(error);
    const mensaje = error.response?.data?.message ?? 'No se pudo completar la operación.';
    (Swal as TVueSwalInstance).fire('Error', mensaje, 'error');
};

const list = async () => {
    try {
        const res: AxiosResponse<ProveedoresBase> = await httpClient.get('proveedores-base', {
            params: { page: currentPage.value, search: search.value || undefined },
        });

        proveedores.value = res.data.proveedores;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;
    } catch (error) {
        mostrarError(error);
    }
};

const reset = () => {
    search.value = '';
    currentPage.value = 1;
    list();
};

const clearFields = () => {
    razonSocial.value = "";
    numeroDocumento.value = "";
    telefono.value = "";
    estado.value = true;
};

const store = async () => {
    try {
        if (razonSocial.value.trim() === '') {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El nombre del proveedor es obligatorio.', 'error');
            return;
        }

        const payload = {
            razon_social: razonSocial.value,
            numero_documento: numeroDocumento.value || null,
            telefono: telefono.value || null,
            estado: estado.value,
        };

        const res: AxiosResponse<ProveedorBaseResponse> =
            !itemSelected.value
                ? await httpClient.post('proveedores-base', payload)
                : await httpClient.put('proveedores-base/' + itemSelected.value.id, payload);

        ModalRegister.value = false;
        (Swal as TVueSwalInstance).fire("Felicitaciones!", res.data.message, "success");
        reset();
    } catch (error) {
        mostrarError(error);
    }
};

const editItem = (item: ProveedorBase) => {
    ModalRegister.value = true;
    itemSelected.value = item;
    razonSocial.value = item.razon_social;
    numeroDocumento.value = item.numero_documento ?? "";
    telefono.value = item.telefono ?? "";
    estado.value = item.estado;
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
        clearFields();
    }
});

watch(currentPage, () => list());
</script>

<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>Cajas</b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3">
                        <b-col lg="7" class="text-center">
                            <b-form-input type="text" id="search" v-model="search"
                                placeholder="Buscar por nombre" @keyup.enter="list" />
                        </b-col>
                        <b-col lg="3" md="3">
                            <b-button type="button" @click="list" variant="success">
                                <i class="fas fa-search"></i>
                            </b-button>
                            <b-button type="button" @click="reset" variant="dark" class="mx-2">
                                <i class="fas fa-sync"></i>
                            </b-button>
                        </b-col>
                        <b-col lg="2">
                            <b-button type="button" variant="success" @click="abrirModalRegistro">
                                <i class="far fa-plus-square ml-2"></i> Registrar
                            </b-button>
                        </b-col>
                    </b-row>
                </b-card-header>

                <b-card-body class="pt-0 mt-2">
                    <b-alert :model-value="branches.length === 0" variant="warning" class="mb-3">
                        No hay ninguna sucursal activa todavía — registra una en
                        <router-link :to="{ name: 'branches.index' }">Configuraciones &gt; Sucursales</router-link>
                        antes de poder crear una caja.
                    </b-alert>
                    <b-table-simple responsive class="mb-0 table-centered">
                        <b-thead class="table-light">
                            <b-tr>
                                <b-td>Nro</b-td>
                                <b-th>Nombre</b-th>
                                <b-th>Sucursal</b-th>
                                <b-th>Código</b-th>
                                <b-th>Tipo</b-th>
                                <b-th>Fondo por defecto</b-th>
                                <b-th>Estado</b-th>
                                <b-th class="text-end">Acción</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(item, index) in registers" :key="index">
                                <b-td>{{ registers.length - index }}</b-td>
                                <b-td>{{ item.name }}</b-td>
                                <b-td>{{ item.branch?.name ?? '—' }}</b-td>
                                <b-td>{{ item.code }}</b-td>
                                <b-td>{{ item.type === 'mobile' ? 'Móvil' : 'Fija' }}</b-td>
                                <b-td>{{ item.default_opening_amount }}</b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="item.is_active">Activo</b-badge>
                                    <b-badge variant="danger" v-else>Inactivo</b-badge>
                                </b-td>
                                <b-td class="text-end">
                                    <a href="#" @click="editItem(item)"><i
                                            class="las la-pen text-secondary fs-22"></i></a>{{ " " }}
                                    <a href="#" @click="toggleActive(item)" :title="item.is_active ? 'Desactivar' : 'Activar'"><i
                                            :class="item.is_active ? 'las la-ban text-secondary fs-22' : 'las la-check-circle text-secondary fs-22'"></i></a>
                                </b-td>
                            </b-tr>
                        </b-tbody>
                    </b-table-simple>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Previous" next-text="Next" />
                </b-card-body>
            </b-col>
        </b-row>

        <b-modal v-model="ModalRegister" :title="`${itemSelected ? 'Edición' : 'Registro'} de Caja`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered size="lg">
            <b-row>
                <b-col lg="6">
                    <label for="branch-cash-register" class="col-form-label text-lg-end">Sucursal: </label>
                    <b-form-select id="branch-cash-register" v-model="branch_id">
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </b-form-select>
                </b-col>
                <b-col lg="6">
                    <label for="name-cash-register" class="col-form-label text-lg-end">Nombre: </label>
                    <b-form-input type="text" id="name-cash-register" placeholder="Example: Caja 1" v-model="name" />
                </b-col>
                <b-col lg="4" class="mt-3">
                    <label for="code-cash-register" class="col-form-label text-lg-end">Código: </label>
                    <b-form-input type="text" id="code-cash-register" v-model="code" />
                </b-col>
                <b-col lg="4" class="mt-3">
                    <label for="type-cash-register" class="col-form-label text-lg-end">Tipo: </label>
                    <b-form-select id="type-cash-register" v-model="type">
                        <option value="fixed">Fija</option>
                        <option value="mobile">Móvil</option>
                    </b-form-select>
                </b-col>
                <b-col lg="4" class="mt-3">
                    <label for="opening-cash-register" class="col-form-label text-lg-end">Fondo por defecto: </label>
                    <b-form-input type="number" step="0.01" min="0" id="opening-cash-register"
                        v-model.number="default_opening_amount" />
                </b-col>
                <b-col lg="6" class="mt-3">
                    <label for="blind-close-cash-register" class="col-form-label text-lg-end">Cierre ciego: </label>
                    <b-form-select id="blind-close-cash-register" v-model="blind_close">
                        <option value="">Hereda de la empresa</option>
                        <option value="true">Ciego (Sí)</option>
                        <option value="false">No ciego</option>
                    </b-form-select>
                </b-col>
                <b-col lg="6" class="mt-3 d-flex align-items-end">
                    <b-form-checkbox v-model="is_active" switch>Activo</b-form-checkbox>
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
import { ref, onMounted, watch } from 'vue';

import Swal from "sweetalert2/dist/sweetalert2.js";
import type { CashRegister, CashRegisters, CashRegisterResponse, Branch } from '@/types/cash-session';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const ModalRegister = ref<Boolean>(false);
const search = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);

const themeColor = ref<string>('primary');

const registers = ref<CashRegister[]>([]);
const branches = ref<Branch[]>([]);

const branch_id = ref<number | null>(null);
const name = ref<string>("");
const code = ref<string>("");
const type = ref<'fixed' | 'mobile'>('fixed');
const default_opening_amount = ref<number>(0);
const blind_close = ref<'' | 'true' | 'false'>('');
const is_active = ref<boolean>(true);

const itemSelected = ref<CashRegister | undefined>(undefined);

const list = async () => {
    try {
        const res: AxiosResponse<CashRegisters> = await httpClient.get(
            `cash-registers?page=${currentPage.value}&search=${search.value ?? ''}`);

        registers.value = res.data.cash_registers;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;
    } catch (error) {
        console.log(error);
    }
};

const listBranches = async () => {
    try {
        const res: AxiosResponse<{ branches: Branch[] }> = await httpClient.get('branches?active=1');
        branches.value = res.data.branches;
    } catch (error) {
        console.log(error);
    }
};

const reset = () => {
    search.value = '';
    currentPage.value = 1;
    list();
};

const clearFields = () => {
    branch_id.value = branches.value[0]?.id ?? null;
    name.value = "";
    code.value = "";
    type.value = 'fixed';
    default_opening_amount.value = 0;
    blind_close.value = '';
    is_active.value = true;
};

const abrirModalRegistro = () => {
    if (branches.value.length === 0) {
        (Swal as TVueSwalInstance).fire('Upps!', 'No hay ninguna sucursal activa — registra una primero en Configuraciones > Sucursales.', 'warning');
        return;
    }
    ModalRegister.value = true;
};

const store = async () => {
    try {
        if (name.value.trim() === '') {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El nombre es obligatorio.', 'error');
            return;
        }
        if (!branch_id.value) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'La sucursal es obligatoria.', 'error');
            return;
        }

        const payload = {
            branch_id: branch_id.value,
            name: name.value,
            code: code.value,
            type: type.value,
            default_opening_amount: default_opening_amount.value,
            blind_close: blind_close.value === '' ? null : blind_close.value === 'true',
            is_active: is_active.value,
        };

        const res: AxiosResponse<CashRegisterResponse> =
            !itemSelected.value
                ? await httpClient.post("cash-registers", payload)
                : await httpClient.put("cash-registers/" + itemSelected.value?.id, payload);

        if (res.data.code == 405) {
            (Swal as TVueSwalInstance).fire("Upps!", res.data.message, "warning");
        } else {
            ModalRegister.value = false;

            if (!itemSelected.value) {
                if (res.data.cash_register) {
                    registers.value.unshift(res.data.cash_register);
                }
            } else {
                const INDEX = registers.value.findIndex((it) => it.id == itemSelected.value?.id);
                if (INDEX != -1 && res.data.cash_register) {
                    registers.value[INDEX] = res.data.cash_register;
                }
            }

            (Swal as TVueSwalInstance).fire("Felicitaciones!", res.data.message, "success");
            reset();
        }
    } catch (error: any) {
        console.log(error);
        if (error.response?.data?.message) {
            (Swal as TVueSwalInstance).fire('Error', error.response.data.message, 'error');
        }
    }
};

const editItem = (item: CashRegister) => {
    ModalRegister.value = true;
    itemSelected.value = item;
    branch_id.value = item.branch_id;
    name.value = item.name;
    code.value = item.code ?? '';
    type.value = item.type;
    default_opening_amount.value = Number(item.default_opening_amount);
    blind_close.value = item.blind_close === null ? '' : (item.blind_close ? 'true' : 'false');
    is_active.value = item.is_active;
};

const toggleActive = (item: CashRegister) => {
    (Swal as TVueSwalInstance)
        .fire({
            title: item.is_active ? 'Confirmar desactivación' : 'Confirmar activación',
            text: item.is_active
                ? `¿Desactivar la caja '${item.name}'? Las sesiones/movimientos históricos que ya la usaron no se ven afectados, solo deja de estar disponible para abrir un turno nuevo.`
                : `¿Activar la caja '${item.name}'?`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            cancelButtonColor: "#d33",
            confirmButtonText: "Sí, continuar",
        })
        .then(async (result: any) => {
            if (!result.isConfirmed) return;

            try {
                if (item.is_active) {
                    await httpClient.delete("cash-registers/" + item.id);
                } else {
                    await httpClient.put("cash-registers/" + item.id, {
                        branch_id: item.branch_id,
                        name: item.name,
                        code: item.code,
                        type: item.type,
                        default_opening_amount: item.default_opening_amount,
                        blind_close: item.blind_close,
                        is_active: true,
                    });
                }

                item.is_active = !item.is_active;
            } catch (error) {
                console.log(error);
            }
        });
};

onMounted(async () => {
    await listBranches();
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

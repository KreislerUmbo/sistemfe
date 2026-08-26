<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>Métodos de Pago</b-card-title>
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
                            <b-button type="button" variant="success"
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
                                <b-th>Código</b-th>
                                <b-th>Nombre</b-th>
                                <b-th>Orden</b-th>
                                <b-th>Estado</b-th>
                                <b-th>Fecha Registro</b-th>
                                <b-th class="text-end">Acción</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(item, index) in paymentMethods" :key="index">
                                <b-td>{{ paymentMethods.length - index }}</b-td>
                                <b-td>{{ item.code }}</b-td>
                                <b-td>{{ item.name }}</b-td>
                                <b-td>{{ item.sort_order }}</b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="item.is_active">Activo</b-badge>
                                    <b-badge variant="danger" v-else>Inactivo</b-badge>
                                </b-td>
                                <b-td>{{ formatFechaHora(item.created_at) }}</b-td>
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

        <b-modal v-model="ModalRegister" :title="`${itemSelected ? 'Edición' : 'Registro'} de Método de Pago`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered size="lg">
            <b-row>
                <b-col lg="6">
                    <label for="code-payment-method" class="col-form-label text-lg-end">Código: </label>
                    <b-form-input type="text" id="code-payment-method" placeholder="Example: YAPE" v-model="code" />
                </b-col>
                <b-col lg="6">
                    <label for="name-payment-method" class="col-form-label text-lg-end">Nombre visible: </label>
                    <b-form-input type="text" id="name-payment-method" placeholder="Example: 📱 Yape" v-model="name" />
                </b-col>
                <b-col lg="6" class="mt-3">
                    <label for="sort_order-payment-method" class="col-form-label text-lg-end">Orden: </label>
                    <b-form-input type="number" id="sort_order-payment-method" v-model.number="sort_order" />
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
import { formatFechaHora } from '@/helpers/fecha';

import Swal from "sweetalert2/dist/sweetalert2.js";
import type { PaymentMethod, PaymentMethods, PaymentMethodResponse } from '@/types/cash';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const ModalRegister = ref<Boolean>(false);
const search = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);

const themeColor = ref<string>('primary');

const paymentMethods = ref<PaymentMethod[]>([]);

const code = ref<string>("");
const name = ref<string>("");
const sort_order = ref<number>(0);
const is_active = ref<boolean>(true);

const itemSelected = ref<PaymentMethod | undefined>(undefined);

const list = async () => {
    try {
        const res: AxiosResponse<PaymentMethods> = await httpClient.get(
            `payment-methods?page=${currentPage.value}&search=${search.value ?? ''}`);

        paymentMethods.value = res.data.payment_methods;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;
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
    code.value = "";
    name.value = "";
    sort_order.value = 0;
    is_active.value = true;
};

const store = async () => {
    try {
        if (code.value.trim() === '' || name.value.trim() === '') {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'Código y nombre son obligatorios.', 'error');
            return;
        }

        const payload = {
            code: code.value,
            name: name.value,
            sort_order: sort_order.value,
            is_active: is_active.value,
        };

        const res: AxiosResponse<PaymentMethodResponse> =
            !itemSelected.value
                ? await httpClient.post("payment-methods", payload)
                : await httpClient.put("payment-methods/" + itemSelected.value?.id, payload);

        if (res.data.code == 405) {
            (Swal as TVueSwalInstance).fire("Upps!", res.data.message, "warning");
        } else {
            ModalRegister.value = false;

            if (!itemSelected.value) {
                if (res.data.payment_method) {
                    paymentMethods.value.unshift(res.data.payment_method);
                }
            } else {
                const INDEX = paymentMethods.value.findIndex((it) => it.id == itemSelected.value?.id);
                if (INDEX != -1 && res.data.payment_method) {
                    paymentMethods.value[INDEX] = res.data.payment_method;
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

const editItem = (item: PaymentMethod) => {
    ModalRegister.value = true;
    itemSelected.value = item;
    code.value = item.code;
    name.value = item.name;
    sort_order.value = item.sort_order;
    is_active.value = item.is_active;
};

const toggleActive = (item: PaymentMethod) => {
    (Swal as TVueSwalInstance)
        .fire({
            title: item.is_active ? 'Confirmar desactivación' : 'Confirmar activación',
            text: item.is_active
                ? `¿Desactivar el método '${item.name}'? Las ventas históricas que ya lo usaron no se ven afectadas.`
                : `¿Activar el método '${item.name}'?`,
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
                    await httpClient.delete("payment-methods/" + item.id);
                } else {
                    await httpClient.put("payment-methods/" + item.id, {
                        code: item.code,
                        name: item.name,
                        sort_order: item.sort_order,
                        is_active: true,
                    });
                }

                item.is_active = !item.is_active;
            } catch (error) {
                console.log(error);
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

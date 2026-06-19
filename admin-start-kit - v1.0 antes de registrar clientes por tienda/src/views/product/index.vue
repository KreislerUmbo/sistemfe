<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>🛍️ Listado de Productos</b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3">
                        <b-col lg="1" sm="1">
                            <label for="search">Buscar Por:</label>
                        </b-col>
                        <b-col lg="6" sm="2" class="text-center">
                            <b-form-input type="text" id="search" v-model="search"
                                placeholder="Buscar producto por nombre" @keyup.enter="list" />
                        </b-col>
                        <b-col lg="3" sm="3">
                            <b-button type="button" @click="list" variant="success">
                                <i class="fas fa-search"></i>
                            </b-button>
                            <b-button type="button" @click="reset" variant="dark" class="mx-2">
                                <i class="fas fa-sync"></i>
                            </b-button>
                        </b-col>
                        <b-col lang="2" sm="2">
                            <b-button type="button" variant="success" to="/product/register">
                                <i class="far fa-plus-square ml-2"></i> Registrar
                            </b-button>
                        </b-col>
                    </b-row>
                    <b-row class="align-items-center justify-content-between mt-1">
                        <b-col sm="1">
                            <label for="categorie_id">Filtrar Por:</label>
                        </b-col>
                        <b-col lg="2" md="1">
                            <b-form-select id="categorie_id" v-model="categorie_id">
                                <option value="">Selecc. Categoría</option>
                                <template v-for="(categorie, index) in categories" :key="index">
                                    <option :value="categorie.id">{{ categorie.title }} </option>
                                </template>
                            </b-form-select>
                        </b-col>
                        <b-col lg="2" md="1">
                            <b-form-select id="state" v-model="state">
                                <option value="">Selec. Estado</option>
                                <option value="1">Todos</option>
                                <option value="2">Activos</option>
                                <option value="3">Inactivos</option>
                            </b-form-select>
                        </b-col>
                        <b-col lg="2" md="1">
                            <b-form-select id="unidad_medida" v-model="unidad_medida">
                                <option value="">Selec. Unidad</option>
                                <template v-for="(unit, index) in units" :key="index">
                                    <option :value="unit.code">{{ unit.name }}</option>
                                </template>
                            </b-form-select>
                        </b-col>
                        <b-col sm="2"></b-col>
                        <b-col sm="2"></b-col>
                        <b-col sm="2"></b-col>
                    </b-row>
                </b-card-header>

                <b-card-body class="pt-0 mt-2">
                    <b-table-simple responsive striped class="mb-0 table-centered">
                        <b-thead class="table-light">
                            <b-tr>
                                <b-td>Nro</b-td>
                                <b-th>Imagen</b-th>
                                <b-th>Producto</b-th>
                                <b-th>SKU</b-th>
                                <b-th>Categoria</b-th>
                                <b-th>Precio</b-th>
                                <b-th>Unidad</b-th>
                                <b-th>Inc. Igv</b-th>
                                <b-th>Estado</b-th>
                                <b-th>Stock</b-th>
                                <b-th class="text-end">Action</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(product, index) in products" :key="index">
                                <b-td>
                                    {{ products.length - index }}
                                </b-td>
                                <b-td>
                                    <div><img :src="product.imagen" style="border-radius: 50%;"
                                            alt="Imagen del producto" width="40" height="40" /></div>
                                </b-td>
                                <b-td>
                                    {{ product.title }}
                                </b-td>
                                <b-td>
                                    {{ product.sku }}
                                </b-td>
                                <b-td>
                                    {{ product.categorie.title }}
                                </b-td>
                                <b-td>
                                    {{ product.price_general }}
                                </b-td>
                                <b-td>
                                    {{ product.unidad_medida }}
                                </b-td>
                                <b-td>
                                    <b-badge :variant="null" class="bg-transparent border border-purple text-purple"
                                        v-if="product.include_igv == 1">Con. Igv</b-badge>
                                    {{ " " }}
                                    <b-badge :variant="null" class="bg-transparent border border-warning text-warning"
                                        v-if="product.include_igv == 2">Sin. Igv</b-badge>
                                    {{ " " }}
                                </b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="product.state == 1">Activo</b-badge>
                                    <b-badge variant="danger" v-if="product.state == 2">Inactivo</b-badge>
                                </b-td>
                                <b-td>
                                    {{ product.stock }}
                                </b-td>
                                <b-td class="text-end">
                                    <button type="button" class="btn btn-link p-0 border-0"
                                        @click="editProduct(product)">
                                        <i class="las la-pen text-secondary fs-22"></i>
                                    </button>{{ " " }}
                                    <button type="button" class="btn btn-link p-0 border-0"
                                        @click="deleteProduct(product)">
                                        <i class="las la-trash-alt text-secondary fs-22"></i>
                                    </button>
                                </b-td>
                            </b-tr>
                        </b-tbody>
                    </b-table-simple>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Previous" next-text="Next" />
                </b-card-body>
            </b-col>
        </b-row>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import { ref, onMounted, watch } from 'vue';

import Swal from "sweetalert2/dist/sweetalert2.js";
import { UNITS, type Product, type ProductCategorie, type ProductConfigResponse, type ProductResponse, type Products, type ProductUnit } from '@/types/products';
import router from '@/router';
type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const search = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);

const themeColor = ref<string>('primary');

const products = ref<Product[]>([]);

const categories = ref<ProductCategorie[]>([]);
const categorie_id = ref<string>("");
const unidad_medida = ref<string>("");
const units = ref<ProductUnit[]>(UNITS);
const state = ref<string>("");

const list = async () => {
    try {
        const res: AxiosResponse<Products> = await httpClient.get(
            `products?page=${currentPage.value}&search=${search.value ?? ''}
            &categorie_id=${categorie_id.value ?? ''}
            &state=${state.value ?? ''}
            &unidad_medida=${unidad_medida.value ?? ''}
            `);

        console.log(res);
        products.value = res.data.products.data;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;

    } catch (error) {
        console.log(error);
    }
};

const config = async () => {
    try {
        const res: AxiosResponse<ProductConfigResponse> = await httpClient.get(
            `products/config`);

        console.log(res);
        categories.value = res.data.categories;
    } catch (error) {
        console.log(error);
    }
}

const reset = () => {
    search.value = '';
    currentPage.value = 1;
    categorie_id.value = '';
    unidad_medida.value = '';
    state.value = '';
    list();
};

const editProduct = (product: Product) => {
    router.push({
        name: "product.edit",
        params: { id: product.id }
    });
};

const deleteProduct = (product: any) => {
    try {
        (Swal as TVueSwalInstance)
            .fire({
                title: "Confirmar la eliminación",
                text: `¿Estas seguro de eliminar  '${product.title}' ?`,
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Si, eliminalo!",
            })
            .then(async (result: any) => {
                if (result.isConfirmed) {
                    const res: AxiosResponse<ProductResponse> = await httpClient.delete(
                        "products/" + product.id
                    );
                    console.log(res);

                    (Swal as TVueSwalInstance).fire(
                        "Felicitaciones!",
                        "El producto [" + product.title + "] ha sido eliminada con éxito.",
                        "success",
                    );
                    let INDEX = products.value.findIndex((prod) => prod.id == product.id);
                    if (INDEX != -1) {
                        products.value.splice(INDEX, 1);
                    }
                }
            });

    } catch (error) {
        console.log(error);
    }
};

onMounted(() => {
    list();
    config();
});

watch(currentPage, (value) => list());
</script>
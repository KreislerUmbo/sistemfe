<template>
    <DefaultLayout>

        <b-card class="p-0">

            <RegisterLayout title="Registrar Producto" @save="store" @cancel="goToList" />


            <b-card-body class="pt-3">

                <!-- 🔵 TABS -->
                <b-tabs card>

                    <!-- 🟢 TAB GENERAL -->
                    <b-tab title="General" active>
                        <b-row class="mt-3">
                            <b-col lg="5" md="5">
                                <label for="name-product">Nombre del Producto:</label>
                                <b-form-input id="name-product" type="text" v-model="title"
                                    @input="title = $event.target.value.toUpperCase()" placeholder="Example: Pollos" />
                            </b-col>

                            <b-col lg="4" md="4">
                                <label for="sku-product">Sku:</label>
                                <b-form-input id="sku-product" type="text" v-model="sku" @input="sku = $event.target.value.toUpperCase()"
                                    placeholder="Example: FE4RFF" />
                            </b-col>

                            <b-col lg="3" md="3">
                                <label>Categoria:</label>
                                <b-form-select v-model="categorie_id">
                                    <option value="">Selec. Categoria</option>
                                    <option v-for="(categorie, index) in categories" :key="index" :value="categorie.id">
                                        {{ categorie.title }}
                                    </option>
                                </b-form-select>
                            </b-col>
                        </b-row>

                        <b-row class="mt-3">
                            <b-col lg="3" md="3">
                                <label for="price_general">Precio Cliente Final:</label>
                                <b-form-input id="price_general" type="number" v-model="price_general" placeholder="150" />
                            </b-col>

                            <b-col lg="3" md="3">
                                <label for="price_company">Precio Cliente Empresa:</label>
                                <b-form-input id="price_company" type="number" v-model="price_company" placeholder="250" />
                            </b-col>

                            <b-col lg="5" md="5">
                                <label for="description">Descripción:</label>
                                <b-form-textarea id="description" rows="5" v-model="description" />
                            </b-col>
                        </b-row>

                        <b-row class="mt-3">
                            <b-col lg="3" md="3">
                                <label>Unidades:</label>
                                <b-form-select v-model="unidad_medida">
                                    <option value="">Selec. Unidad</option>
                                    <option v-for="(unit, index) in units" :key="index" :value="unit.code">
                                        {{ unit.name }}
                                    </option>
                                </b-form-select>
                            </b-col>

                            <b-col lg="3" md="3">
                                <label for="stock">Stock:</label>
                                <b-form-input id="stock" type="number" v-model="stock" placeholder="5" />
                            </b-col>

                            <b-col lg="5">
                                <label>Imagen:</label>
                                <b-input-group>
                                    <b-form-file @change="loadFile($event)" />
                                    <b-input-group-text>Upload</b-input-group-text>
                                </b-input-group>

                                <img v-if="IMAGEN_PREVIZUALIZA" :src="IMAGEN_PREVIZUALIZA" width="150"
                                    class="rounded d-block mx-auto mt-2" />
                            </b-col>
                        </b-row>
                    </b-tab>

                    <!-- 🔧 TAB CONFIGURACIONES -->
                    <b-tab title="Configuraciones">
                        <b-row class="mt-3">
                            <b-col lg="3">
                                <label>Descuento:</label>
                                <div class="d-flex">
                                    <b-form-radio name="discount" @click="is_discount = 1" value="1"
                                        :checked="is_discount == 1">No</b-form-radio>
                                    <b-form-radio class="mx-2" name="discount" @click="is_discount = 2" value="2"
                                        :checked="is_discount == 2">Si</b-form-radio>
                                </div>
                            </b-col>

                            <b-col lg="3" v-if="is_discount == 2">
                                <label>% Descuento:</label>
                                <b-form-input type="number" v-model="max_discount" placeholder="60%" />
                            </b-col>

                            <b-col lg="3">
                                <label>Disponibilidad:</label>
                                <b-form-radio name="disp" @click="disponiblidad = 1" value="1"
                                    :checked="disponiblidad == 1">Vender sin stock</b-form-radio>
                                <b-form-radio name="disp" @click="disponiblidad = 2" value="2"
                                    :checked="disponiblidad == 2">No vender sin stock</b-form-radio>
                            </b-col>

                            <b-col lg="3">
                                <label>Bolsa Plástico:</label>
                                <b-form-radio name="icbper" @click="is_icbper = 1" value="1"
                                    :checked="is_icbper == 1">No</b-form-radio>
                                <b-form-radio name="icbper" @click="is_icbper = 2" value="2"
                                    :checked="is_icbper == 2">Si</b-form-radio>
                            </b-col>
                        </b-row>

                        <b-row class="mt-3">
                            <b-col lg="3">
                                <label>Arroz Pilado:</label>
                                <b-form-radio name="ivap" @click="is_ivap = 1" value="1"
                                    :checked="is_ivap == 1">No</b-form-radio>
                                <b-form-radio name="ivap" @click="is_ivap = 2" value="2"
                                    :checked="is_ivap == 2">Si</b-form-radio>
                            </b-col>

                            <b-col lg="3">
                                <label>ISC:</label>
                                <b-form-radio name="isc" @click="is_isc = 0" value="0"
                                    :checked="is_isc == 0">No</b-form-radio>
                                <b-form-radio  name="isc" class="mx-2" @click="is_isc = 1" value="1"
                                    :checked="is_isc == 1">Si</b-form-radio>
                            </b-col>

                            <b-col lg="3" v-if="is_isc == 1">
                                <label>% ISC:</label>
                                <b-form-input type="number" v-model="percentage_isc" placeholder="60%" />
                            </b-col>

                            <b-col lg="3">
                                <label>Nota Electrónica:</label>
                                <b-form-radio name="nota" @click="is_especial_nota = 0" value="0"
                                    :checked="is_especial_nota == 0">No</b-form-radio>
                                <b-form-radio class="mx-2" name="nota" @click="is_especial_nota = 1" value="1"
                                    :checked="is_especial_nota == 1">Si</b-form-radio>
                            </b-col>
                        </b-row>

                        <b-row class="mt-3">
                            <b-col lg="3">
                                <label>Incluye IGV:</label>
                                <b-form-radio name="igv" @click="include_igv = 1" value="1"
                                    :checked="include_igv == 1">No</b-form-radio>
                                <b-form-radio name="igv" @click="include_igv = 2" value="2"
                                    :checked="include_igv == 2">Si</b-form-radio>
                            </b-col>

                            <b-col lg="3">
                                <label>Precio Base C.F:</label>
                                <div>S/. {{ getPriceBaseCF() }}</div>

                                <label class="mt-2">Precio Base C.E:</label>
                                <div>S/. {{ getPriceBaseCE() }}</div>
                            </b-col>
                        </b-row>
                    </b-tab>
                    <b-tab title="Información Adicional">

                    </b-tab>

                </b-tabs>


            </b-card-body>
        </b-card>
    </DefaultLayout>
</template>


<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import RegisterLayout from "@/layouts/components/RegisterLayout.vue";


import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import Swal from "sweetalert2/dist/sweetalert2.js";
type TVueSwalInstance = typeof Swal & typeof Swal.fire;

import { useRouter } from "vue-router";
const router = useRouter();

import { onMounted, ref } from "vue";
import { UNITS, type Product, type ProductCategorie, type ProductConfigResponse, type ProductResponse, type ProductUnit } from "@/types/products";
import { link } from "fs";




const title = ref<string>("");
const sku = ref<string>("");
const categorie_id = ref<string>("");
const categories = ref<ProductCategorie[]>([]);
const price_general = ref<number>(0);
const price_company = ref<number>(0);
const description = ref<string>("");
const disponiblidad = ref<number>(1);
const max_discount = ref<number>(1);
const is_icbper = ref<number>(1);
const is_discount = ref<number>(1);
const is_ivap = ref<number>(1);
const is_isc = ref<number>(1);
const is_especial_nota = ref<number>(0);
const percentage_isc = ref<number>(1);
const include_igv = ref<number>(1);
const unidad_medida = ref<string>("NIU");
const units = ref<ProductUnit[]>(UNITS);
const stock = ref<number>(0);
const state = ref<number>(1);
const FILE_IMAGEN = ref<File | undefined>(undefined);
const IMAGEN_PREVIZUALIZA = ref<string | ArrayBuffer | null>(null);



const getPriceBaseCF = () => {
    return include_igv.value == 1 ? price_general.value : (price_general.value / 1.18).toFixed(6);
}


const getPriceBaseCE = () => {
 return include_igv.value == 1 ? price_company.value : (price_company.value / 1.18).toFixed(6);
}

const loadFile = ($event: any) => {
    if ($event.target.files[0].type.indexOf("image") < 0) {// validar que sea una imagen
        IMAGEN_PREVIZUALIZA.value = null;
        (Swal as TVueSwalInstance).fire(// Usar Swal para mostrar alerta
            'Upss!',
            'El archivo seleccionado no es una imagen.',
            'error');
        return;
    }
    FILE_IMAGEN.value = $event.target.files[0];// obtener el archivo y lo asigna a la variable reactiva FILE_IMAGEN
    let reader = new FileReader();// crear un objeto FileReader para leer el archivo como una URL de datos
    if (FILE_IMAGEN.value) {
        reader.readAsDataURL(FILE_IMAGEN.value); // leer el archivo como una URL de datos
        reader.onloadend = () => IMAGEN_PREVIZUALIZA.value = reader.result;// asignar la URL de datos a la variable reactiva IMAGEN_PREVIZUALIZA para previsualizar la imagen
    }
}

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

const limpiarForm = () => {
    title.value = '';
    sku.value = '';
    categorie_id.value = '';
    price_general.value = 0;
    price_company.value = 0;
    description.value = '';
    disponiblidad.value = 1;
    max_discount.value = 0;
    is_icbper.value = 1;
    is_discount.value = 1;
    is_ivap.value = 1;
    is_isc.value = 1;
    is_especial_nota.value = 1;
    percentage_isc.value = 0;
    include_igv.value = 1;
    unidad_medida.value = '';
    stock.value = 0;
    FILE_IMAGEN.value = undefined;
};

const store = async () => {
    try {

        if (title.value.trim() === '') { //trim() elimina espacios en blanco al inicio y al final
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El nombre del producto es obligatorio.', 'error');
            return;
        }

        if (sku.value.trim() === '') {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El sku del producto es obligatorio.', 'error');
            return;
        }
        if (!categorie_id.value) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'La categoría del producto es obligatorio.', 'error');
            return;
        }
        if (!unidad_medida.value) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'La unidad de medida del producto es obligatorio.', 'error');
            return;
        }
        if (price_general.value < 0) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El precio general del producto es obligatorio.', 'error');
            return;
        }
        if (!description.value) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'La descripcion del producto es obligatorio.', 'error');
            return;
        }

        if (is_discount.value == 2 && max_discount.value == 0) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El descuento del producto es obligatorio.', 'error');
            return;
        }
        if (is_isc.value == 2 && percentage_isc.value <= 0) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El porcentaje de ISC del producto es obligatorio.', 'error');
            return;
        }
        if (is_ivap.value == 2 && percentage_isc.value == 0) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El porcentaje de IVAP del producto es obligatorio.', 'error');
            return;
        }
        if (stock.value < 0) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El stock del producto es obligatorio.', 'error');
            return;
        }


        let formData = new FormData();
        formData.append('title', title.value);
        formData.append('sku', sku.value);
        formData.append('categorie_id', categorie_id.value);
        formData.append('price_general', price_general.value.toString());
        formData.append('price_company', price_company.value.toString());
        formData.append('description', description.value);
        formData.append('disponiblidad', disponiblidad.value.toString());
        formData.append('max_discount', max_discount.value.toString());
        formData.append('is_icbper', is_icbper.value.toString());
        formData.append('is_discount', is_discount.value.toString());
        formData.append('is_ivap', is_ivap.value.toString());
        formData.append('is_isc', is_isc.value.toString());
        formData.append('is_especial_nota', is_especial_nota.value.toString());
        formData.append('percentage_isc', percentage_isc.value.toString());
        formData.append('include_igv', include_igv.value.toString());
        formData.append('unidad_medida', unidad_medida.value);
        formData.append('stock', stock.value.toString());
        formData.append('image', FILE_IMAGEN.value);
        formData.append('state', state.value.toString());

        const resp: AxiosResponse<ProductResponse> = await httpClient.post("products", formData);

        if (resp.data.code == 405) {
            (Swal as TVueSwalInstance).fire(
                "Upps",
                resp.data.message,
                "error"
            );
        } else {
            (Swal as TVueSwalInstance).fire(
                "Genial!",
                resp.data.message,
                "success"
            );

            limpiarForm();


        }
        console.log(resp);

    } catch (error: any) {
        if (error.response?.data) {
            (Swal as TVueSwalInstance).fire(
                "Upps",
                error.response?.data?.message,
                "error"
            );
            return;
        }

    }


}


function goToList() {
  router.push({ name: "product.index" });
}

onMounted(() => {
    config();
});

</script>

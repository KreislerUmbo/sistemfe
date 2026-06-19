<template>
    <DefaultLayout>
        <div class="d-flex flex-wrap justify-space-between gap-4 mb-6">
            <div class="d-flex flex-column justify-center">
                <h4 class="text-h4 mb-1">
                    💰 Add New Sale/Cotización
                </h4>
                <p class="text-body-1 mb-0">
                    Orders placed across your store
                </p>
            </div>
        </div>

        <b-row class="mt-2">
            <b-col lg="5" md="5">
                <b-card no-body :class="{ 'border-sale': state_sale == 1 }">
                    <b-card-header class="text-center">
                        <i class="fas fa-cart-plus fs-18"></i>
                        <b-card-title class="py-1">VENTA</b-card-title>
                        <b-form-radio name="state_sale" @click="state_sale = 1" value="1" checked></b-form-radio>
                    </b-card-header>
                </b-card>
            </b-col>
            <b-col lg="2" md="2">
            </b-col>
            <b-col lg="5" md="5">
                <b-card no-body :class="{ 'border-sale': state_sale == 2 }">
                    <b-card-header class="text-center">
                        <i class="fas fa-file-alt fs-18"></i>
                        <b-card-title class="py-1">COTIZACIÓN</b-card-title>
                        <b-form-radio name="state_sale" @click="state_sale = 2" value="2"></b-form-radio>
                    </b-card-header>
                </b-card>
            </b-col>
        </b-row>
        <b-card no-body>
            <b-card-header>
                <b-card-title>🔩 Datos Generales</b-card-title>
            </b-card-header>
            <b-card-body class="pt-0">

                <b-row>
                    <b-col lg="2">
                        <label for="is_exportacion-c" class="col-form-label text-lg-end">Exportación: </label>
                        <div class="d-flex">
                            <b-form-radio name="is_exportacion-c" @click="is_exportacion = 0" value="0"
                                :checked="is_exportacion == 0">No</b-form-radio>
                            {{ " " }}
                            <b-form-radio name="is_exportacion-c" class="mx-2" @click="is_exportacion = 1" value="1"
                                :checked="is_exportacion == 1">Si</b-form-radio>
                            {{ " " }}
                        </div>
                    </b-col>
                    <b-col lg="2" md="3">
                        <label for="n-transaction" class="col-form-label text-lg-end">N° de Venta/Cotización: </label>
                        <b-form-input type="text" id="n-transaction" v-model="n_transaction" placeholder="000000" />
                    </b-col>
                    <b-col lg="2" md="3">
                        <label for="n-f-emision" class="col-form-label text-lg-end">Fecha de Emisión: </label>
                        <b-form-input type="date" id="n-f-emision" v-model="today" />
                    </b-col>
                    <b-col lg="5" md="5">
                        <label for="n-f-clients" class="col-form-label text-lg-end">Clientes: </label>
                        <select id="n-f-clients">
                            <option value="">Selec. Cliente</option>
                            <template v-for="(client, index) in clients" :key="index">
                                <option :value="client.id">{{ client.full_name }}</option>
                            </template>
                        </select><b v-if="client_selected">{{ client_selected.type_client == '1' ? 'CLIENTE FINAL'
                            : 'CLIENTE EMPRESA' }}</b>

                    </b-col>

                    <b-col lg="5" v-if="is_exportacion == 0">
                        <label for="retencion-igv-c" class="col-form-label text-lg-end">Retenciones IGV: </label>
                        <div class="d-flex">
                            <b-form-radio name="retencion-igv-c" @click="retencion_igv = 0" value="0"
                                :checked="retencion_igv == 0">Ninguno</b-form-radio>
                            {{ " " }}
                            <b-form-radio name="retencion-igv-c" class="mx-2" @click="retencion_igv = 1" value="1"
                                :checked="retencion_igv == 1">Retención</b-form-radio>
                            {{ " " }}
                            <b-form-radio name="retencion-igv-c" class="mx-2" @click="retencion_igv = 2" value="2"
                                :checked="retencion_igv == 2">Detracción</b-form-radio>
                            {{ " " }}
                            <b-form-radio name="retencion-igv-c" class="mx-2" @click="retencion_igv = 3" value="3"
                                :checked="retencion_igv == 3">Percepción</b-form-radio>
                        </div>
                    </b-col>

                    <b-col lg="2">
                        <label for="is_anticipo-c" class="col-form-label text-lg-end">Anticipo: </label>
                        <div class="d-flex">
                            <b-form-radio name="is_anticipo-c" @click="is_anticipo = 0" value="0"
                                :checked="is_anticipo == 0">No</b-form-radio>
                            {{ " " }}
                            <b-form-radio name="is_anticipo-c" class="mx-2" @click="is_anticipo = 1" value="1"
                                :checked="is_anticipo == 1">Si</b-form-radio>
                            {{ " " }}
                        </div>
                    </b-col>
                    <b-col lg="2" v-if="is_anticipo == 1">
                        <div class="d-flex align-items-center">
                            <div>
                                <label for="n-of-comprobante" class="col-form-label">N° de Compr. o N° Venta: </label>
                                <b-form-input type="text" id="n-of-comprobante" v-model="n_comprobante_anticipo"
                                    @keyup.enter="searchAnticipo()" placeholder=""
                                    :disabled="sale_anticipo ? true : false" />
                            </div>
                            <div v-if="sale_anticipo">
                                <b-button type="button" class="rounded-pill btn btn-info" @click="resetAnticipo()">
                                    <i class="fas fa-window-close ml-3"></i>
                                </b-button>
                            </div>
                        </div>
                    </b-col>
                    <b-col lg="2">
                        <label for="FB" class="col-form-label text-lg-end">Tipo: </label>
                        <b-form-select id="FB" v-model="serie">
                            <option value="F001">F001</option>
                            <option value="B001">B001</option>
                        </b-form-select>
                    </b-col>
                </b-row>

            </b-card-body>
        </b-card>

        <b-card no-body>
            <b-card-header>
                <b-card-title>📦 Datos del Producto</b-card-title>
            </b-card-header>
            <b-card-body class="pt-0">
                <b-row>
                    <b-col lg="6" md="6">
                        <div class="row">
                            <div class="col-12">
                                <label for="n-f-products" class="col-form-label text-lg-end">Productos: </label>
                                <select id="n-f-products">
                                    <option value="">Selec. Producto</option>

                                    <template v-for="(product, index) in products" :key="index">
                                        <option :value="product.id" :data-stock="product.stock">
                                            {{ product.title }}
                                        </option>
                                    </template>
                                </select>


                            </div>
                            <div class="col-12 mt-2" v-if="product_selected">
                                <span v-if="product_selected.is_icbper == 2">Es Bolsa de Plastico</span>
                                <span v-if="product_selected.is_ivap == 2">Es Arroz Pilado</span>
                            </div>
                        </div>
                    </b-col>
                    <b-col lg="3" md="3">
                        <b-row>
                            <b-col lg="12" md="12"
                                v-if="product_selected && product_selected?.is_ivap == 1 && is_exportacion == 0">
                                <label for="n-f-type-operation" class="col-form-label text-lg-end">Tipo de Operación:
                                </label>
                                <b-form-select id="n-f-type-operation" v-model="type_operation">
                                    <option value="10">Gravado - Operación Onerosa</option>
                                    <option value="20">Exonerado - Operación Onerosa</option>
                                    <option value="30">Inafecto - Operación Onerosa</option>
                                    <option value="11">Gravado – Retiro por premio</option>
                                </b-form-select>
                            </b-col>
                            <b-col lg="12" md="12" v-if="product_selected && is_exportacion == 1">
                                <label for="n-f-type-operation" class="col-form-label text-lg-end">Tipo de Operación:
                                </label>
                                <b-form-select id="n-f-type-operation" v-model="type_operation">
                                    <option value="40">Exportación de Bienes o Servicios</option>
                                </b-form-select>
                            </b-col>
                            <b-col lg="12" md="12"
                                v-if="product_selected && product_selected?.is_ivap == 2 && is_exportacion == 0">
                                <label for="n-f-type-operation" class="col-form-label text-lg-end">Tipo de Operación:
                                </label>
                                <b-form-select id="n-f-type-operation" v-model="type_operation">
                                    <option value="17">Gravado - IVAP</option>
                                </b-form-select>
                            </b-col>
                            <b-col lg="12" md="12" v-if="product_selected && product_selected.is_discount == 2">
                                <label for="n-f-discount" class="col-form-label text-lg-end">Descuento: </label>
                                <b-form-input type="number" id="n-f-discount" v-model="discount" placeholder="" />
                            </b-col>
                        </b-row>
                    </b-col>
                    <b-col lg="2" md="2">
                        <b-row>
                            <b-col lg="12" md="12">
                                <label for="n-f-cantidad" class="col-form-label text-lg-end">Cantidad: </label>
                                <b-form-input type="number" id="n-f-cantidad" v-model="quantity" placeholder="" />
                            </b-col>
                            <b-col lg="12" md="12">
                                <label for="n-f-precio-base" class="col-form-label text-lg-end">Precio Base: </label>
                                <b-form-input type="number" id="n-f-precio-base" v-model="price_base" placeholder="" />
                            </b-col>
                        </b-row>
                    </b-col>
                    <b-col lg="1" md="1" class="text-end mt-4">
                        <b-button type="button" variant="success" @click="addProduct()">
                            <i class="far fa-plus-square ml-3"></i>
                        </b-button>
                    </b-col>
                </b-row>
            </b-card-body>
        </b-card>

        <b-card no-body>
            <b-card-header>
                <b-card-title>📒 Detalle de la {{ state_sale == 1 ? 'Venta' : 'Cotización' }}</b-card-title>
            </b-card-header>
            <b-card-body class="pt-0">
                <b-row>
                    <b-col lg="12">
                        <div class="table-responsive m-0">
                            <table class="table datatable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Id</th>
                                        <th>Producto</th>
                                        <th>Unidad</th>
                                        <th>Cantidad</th>
                                        <th>Precio Base</th>
                                        <th>Igv</th>
                                        <th>Descuento</th>
                                        <th>Precio Final</th>
                                        <th>SubTotal</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(sale_detail, index) in sale_details" :key="index">
                                        <td>{{ index + 1 }}</td>
                                        <td>{{ sale_detail.product.title }}</td>
                                        <td>{{ sale_detail.unidad_medida }}</td>
                                        <td>{{ sale_detail.quantity }}</td>
                                        <td>{{ currency }} {{ sale_detail.price_base }}</td>
                                        <td>{{ currency }} {{ (sale_detail.igv + (sale_detail.icbper ?? 0)).toFixed(2)
                                            }}</td>
                                        <td>{{ currency }} {{ sale_detail.discount }}</td>
                                        <td>{{ currency }} {{ sale_detail.price_final }}</td>
                                        <td v-if="![11].includes(Number(sale_detail.tip_afe_igv))">
                                            {{ currency }} {{ sale_detail.subtotal }}
                                        </td>

                                        <td v-if="[11].includes(Number(sale_detail.tip_afe_igv))">
                                            {{ currency }} 0
                                        </td>
                                        <td><b-button type="button" variant="danger" @click="deleteSaleDetail(index)">
                                                <i class="fas fa-prescription-bottle"></i>
                                            </b-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </b-col>
                    <b-col lg="6">
                        <b-row>
                            <b-col lg="4">
                                <label for="n-discount-global" class="col-form-label text-lg-end">Descuento Global:
                                </label>
                                <b-form-input type="number" id="n-discount-global" v-model="discount_global"
                                    @keyup.enter="sumDetails()" placeholder="" />
                            </b-col>
                        </b-row>
                    </b-col>
                    <b-col lg="6" class="mt-2">
                        <table style="width: 90%;">
                            <tr>
                                <td>Precio Base (Valor Venta)</td>
                                 <td>{{ currency }} {{ sale_details.reduce((acc, detail) => acc + detail.quantity * detail.price_base, 0) }}</td>
                            </tr>
                            <tr>
                                <td>(-) Descuento</td>
                                <td>{{ currency }} {{ discount_total + discount_global }}</td>
                            </tr>

                            <tr>
                                <td>Base Imponible</td>
                                <td>{{ currency }} {{ getSubTotalSale() }}</td>
                            </tr>
                            <tr>
                                <td>IGV (18%)</td>
                                <td>{{ currency }} {{ getIgvTotal() }}</td>
                            </tr>
                            <tr>
                                <td>ICBPER (10 bolsas x S/ 0.50):</td>
                                <td>{{ currency }} {{ icbper_total }}</td>
                            </tr>

                            <tr>
                                <td>TOTAL FACTURA</td>
                                <td>{{ currency }} {{ getSubTotalSale() + getIgvTotal() + icbper_total }}</td>
                            </tr>
                            <tr v-if="total_percepcion">
                                <td>(+) Percepción(4%)</td>
                                <td>{{ currency }} {{ total_percepcion }}</td>
                            </tr>

                            <tr v-if="total_retencion > 0">
                                <td>(-) Retención (3%)</td>
                                <td>{{ currency }} -{{ total_retencion }}</td>
                            </tr>

                            <tr v-if="total_detraccion">
                                <td>(-) Detracción (4%)</td>
                                <td>{{ currency }} {{ total_detraccion }}</td>
                            </tr>
                            <hr>
                            <tr>
                                <td>MONTO NETO A PAGAR:</td>
                                <td>{{ currency }} {{ getTotalSales() }}</td>
                            </tr>
                            <tr>
                                <td>Total Pagado</td>
                                <td>{{ currency }} {{ total_payments }}</td>
                            </tr>

                        </table>
                    </b-col>
                </b-row>
            </b-card-body>
        </b-card>
        /* Card de pagos */
        <b-card no-body>
            <b-card-header>
                <b-card-title>💵 Pagos</b-card-title>
            </b-card-header>
            <b-card-body class="pt-0">
                <b-row>
                    <b-col lg="3">
                        <label for="type-pay-c" class="col-form-label text-lg-end">Tipo: </label>
                        <div class="d-flex">
                            <b-form-radio name="type-pay-c" @click="type_payment = 1" value="1"
                                :checked="type_payment == 1">Al
                                contado</b-form-radio>
                            {{ " " }}
                            <b-form-radio name="type-pay-c" class="mx-2" @click="type_payment = 2" value="2"
                                :checked="type_payment == 2">Credito</b-form-radio>
                        </div>
                    </b-col>
                    <b-col lg="3">
                        <label for="n-f-method-payment" class="col-form-label text-lg-end">Metodo de Pago: </label>
                        <b-form-select id="n-f-method-payment" v-model="method_payment">
                            <option value="EFECTIVO">EFECTIVO</option>
                            <option value="TRANSFERENCIA">TRANSFERENCIA</option>
                            <option value="YAPE">YAPE</option>
                            <option value="PLIN">PLIN</option>
                            <option value="TARJETA DE CREDITO">TARJETA DE CREDITO</option>
                        </b-form-select>
                    </b-col>
                    <b-col lg="2">
                        <label for="n-f-amount" class="col-form-label text-lg-end">Monto: </label>
                        <b-form-input type="number" id="n-f-amount" v-model="amount" placeholder="" />
                    </b-col>
                    <b-col lg="3" v-if="type_payment == 2">
                        <label for="n-f-payment" class="col-form-label text-lg-end">Fecha de Pago: </label>
                        <b-form-input type="date" id="n-f-payment" v-model="date_payment" />
                    </b-col>
                    <b-col lg="1">
                        <label class="col-form-label text-lg-end">Agregar: </label>
                        <b-button type="button" variant="primary" @click="addPayment()">
                            <i class="far fa-plus-square ml-3"></i>
                        </b-button>
                    </b-col>
                </b-row>

                <b-row class="mt-2">
                    <b-col lg="6">
                        <div class="table-responsive m-0">
                            <table class="table datatable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Id</th>
                                        <th>Metodo de Pago</th>
                                        <th>Monto</th>
                                        <th v-if="type_payment == 2">Fecha de Pago</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(sale_payment, index) in sale_payments" :key="index">
                                        <td>{{ index + 1 }}</td>
                                        <td>{{ sale_payment.method_payment }}</td>
                                        <td>{{ currency }} {{ sale_payment.amount }}</td>
                                        <td v-if="type_payment == 2">{{ sale_payment.date_payment }}</td>
                                        <td>
                                            <b-button type="button" variant="danger" @click="removePayment(index)">
                                                <i class="fas fa-prescription-bottle"></i>
                                            </b-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </b-col>
                    <b-col lg="3" md="3">
                        <label for="description-product" class="col-form-label text-lg-end">Descripción: </label>
                        <b-form-textarea type="textarea" v-model="description" rows="5" id="description-product" />
                    </b-col>
                    <b-col lg="3" md="3" class="text-end">
                        <b-button type="button" variant="primary" @click="store()">
                            <i class="far fa-plus-square ml-3"></i> Guardar {{ state_sale == 1 ? 'Venta' : 'Cotización'
                            }}
                        </b-button>
                        <b-button type="button" variant="primary" @click="store()">
                            <i class="far fa-plus-square ml-3"></i> Cancelar
                        </b-button>
                    </b-col>
                </b-row>

            </b-card-body>
        </b-card>

    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import Swal from "sweetalert2/dist/sweetalert2.js";
import { onMounted, ref, watch } from 'vue';
import type { SaleConfig, Sale, SaleDetail, SalePayment, SaleResponse } from '@/types/sales';
import type { Client } from '@/types/clients';
import type { Product } from '@/types/products';
import Selectr from 'mobius1-selectr';
import { set } from '@vueuse/core';
import { get } from 'jquery';
import { s } from 'node_modules/vite/dist/node/types.d-aGj9QkWt';



type TVueSwalInstance = typeof Swal & typeof Swal.fire;


const clients = ref<Client[]>([]);
const products = ref<Product[]>([]);
const client_selected = ref<Client | undefined>(undefined);
const product_selected = ref<Product | undefined>(undefined);

const state_sale = ref<number>(1);
const is_exportacion = ref<number>(0);
const n_transaction = ref<string>("");
const today = ref<string>('');
const retencion_igv = ref<number>(0);

const is_anticipo = ref<number>(0);
const n_comprobante_anticipo = ref<string>("");
const sale_anticipo = ref<number>(0);
const serie = ref<string>("F001");
const type_operation = ref<string>("");
const discount = ref<number>(0);
const quantity = ref<number>(0);
const price_base = ref<number>(0);

const sale_details = ref<SaleDetail[]>([]);
const currency = ref<string>("S/.");
const discount_total = ref<number>(0);
const discount_global = ref<number>(0);
const discount_global_general = ref<number>(0);


//pagos
const type_payment = ref<number>(1);
const method_payment = ref<string>("EFECTIVO");
const amount = ref<number>(0);
const date_payment = ref<string>('')
const sale_payments = ref<any[]>([]);
const description = ref<string>("");

const clientSelectr = ref<any>(null);//esto es una variable para captar el id del cliente
const productSelectr = ref<any>(null);//esto es una variable para captar el id del producto
const igv_total = ref<number>(0);
const sale_total = ref<number>(0);
const sale_subtotal = ref<number>(0);
const icbper_total = ref<number>(0);
const isc_total = ref<number>(0);
//variable auxiliares
const message_text = ref<string>('');
const price_unit = ref<number>(0);
const igv_discount_global = ref<number>(0);
const igv_discount_general = ref<number>(0);

const total_detraccion = ref<number>(0);
const total_percepcion = ref<number>(0);
const total_retencion = ref<number>(0);
const total_payments = ref<number>(0);

const getPriceBaseCF = () => { //precio base cliente final
    if (product_selected.value) {
        if (product_selected.value.include_igv == 1) { //no incluye igv
            return (product_selected.value.price_general);
        } else {
            if (product_selected.value.percentage_isc > 0) {
                let p_isc = product_selected.value.percentage_isc * 0.01;
                const factor = ((1 + p_isc) + ((1 + p_isc) * 0.18));
                return Number((product_selected.value.price_general / factor).toFixed(6));
            }

            return (product_selected.value.price_general / 1.18).toFixed(6);
        }
    }
    return 0;
}

const getPriceBaseCE = () => { //precio base cliente empresa
    if (product_selected.value) {
        if (product_selected.value.include_igv == 1) { //no incluye igv
            return (product_selected.value.price_company);
        } else {
            if (product_selected.value.percentage_isc > 0) {
                let p_isc = product_selected.value.percentage_isc * 0.01;
                const factor = ((1 + p_isc) + ((1 + p_isc) * 0.18));
                return Number((product_selected.value.price_company / factor).toFixed(6));
            }
            return (product_selected.value.price_company / 1.18).toFixed(6);
        }
    }
    return 0;
}


const config = async () => {
    try {
        const res: AxiosResponse<SaleConfig> = await httpClient.get(
            `sales/config`);
        n_transaction.value = res.data.n_transaction;
        today.value = res.data.today;
        if (products.value.length == 0) {
            clients.value = res.data.clients.data;
            products.value = res.data.products.data;

            setTimeout(() => {
                clientSelectr.value = new Selectr('#n-f-clients');
                productSelectr.value = new Selectr('#n-f-products', {
                    searchable: true,

                    renderOption(option: any) {//
                        if (!option.value) return option.text;

                        const stock = option.dataset.stock ?? 0;

                        return `
                    <div style="display:flex;justify-content:space-between; width:100%;">
                        <span>${option.text}</span>
                        <span style="font-size:12px;color:${stock <= 5 ? 'red' : '#6b7280'};">stock: ${stock}</span>
                    </div>
    `;
                    },

                    renderSelection(option: any) {
                        return option.text;
                    }
                });
            }, 25);
        }



        console.log(res);

        setTimeout(() => {
            clientSelectr.value.on("selectr.change", (option: any) => {
                console.log(option.value);
                client_selected.value = clients.value.find(client => client.id == option.value);
                if (product_selected.value && client_selected.value) {
                    if (client_selected.value.type_client === '1') { //CLIENTE FINAL
                        price_base.value = Number(Number(getPriceBaseCF()).toFixed(3));
                    }
                    if (client_selected.value.type_client === '2') { // CLIENTE EMPRESA
                        price_base.value = Number(Number(getPriceBaseCE()).toFixed(3));
                    }
                }
            });

            productSelectr.value.on("selectr.change", (option: any) => {
                console.log(option.value);
                product_selected.value = products.value.find(product => product.id == option.value);
                if (product_selected.value) {
                    if (product_selected.value?.is_ivap == 1 && is_exportacion.value == 0) {
                        type_operation.value = "10";
                    };
                    if (is_exportacion.value == 1) {
                        type_operation.value = "40";
                    }
                    if (product_selected.value?.is_ivap == 2 && is_exportacion.value == 0) {
                        type_operation.value = "17";
                    };
                }




                if (client_selected.value) {
                    if (client_selected.value.type_client == '1') {//CLIENTE FINAL
                        price_base.value = Number(Number(getPriceBaseCF()).toFixed(3));

                    }
                    if (client_selected.value.type_client == '2') {// CLIENTE EMPRESA
                        price_base.value = Number(Number(getPriceBaseCE()).toFixed(3));
                    }
                } else {
                    price_base.value = product_selected.value?.price_general ?? 0;
                }
            });

        }, 50);

    } catch (error) {
        console.log(error);

    }

}


const searchAnticipo = () => {

}
const resetAnticipo = () => {

}
const addProduct = () => {

    if (!product_selected.value) {
        (Swal as TVueSwalInstance).fire({
            icon: 'error',
            title: 'Error',
            text: 'Necesitas seleccionar un producto',
        });
        return;
    }
    if (quantity.value <= 0) {

        return;
    }
    if (price_base.value <= 0) {
        (Swal as TVueSwalInstance).fire({
            icon: 'error',
            title: 'Error',
            text: 'Necesitas digitar el precio unitario del producto',
        });
        return;
    }
    let TOTAL_VENTA_BASE = quantity.value * price_unit.value;
    let DISCOUNT_TOTAL = discount.value * quantity.value;
    let SUBTOTAL = Number(((price_base.value * quantity.value) - DISCOUNT_TOTAL).toFixed(3));
    let fact_icbper = product_selected.value.is_icbper == 2 ? 0.50 : 0; //para la bolsa de plastico
    let per_isc = product_selected.value.percentage_isc;

    let PERCENTAGE_IGV = 0.18;

    if (!["10", "11"].includes(type_operation.value)) {//si no es 10() ni 11
        PERCENTAGE_IGV = 0.0;
    }

    if (product_selected.value.is_ivap == 2) { //en caso de que sea arroz pilado
        PERCENTAGE_IGV = 0.04;
    }
    let isc = 0;
    if (per_isc > 0) {
        isc = SUBTOTAL * per_isc * 0.01;
    }



    let IGV = (SUBTOTAL + isc) * PERCENTAGE_IGV;
    let PRECIO_FINAL = Number(((SUBTOTAL + IGV + isc) / quantity.value).toFixed(3));
    let icbper = quantity.value * fact_icbper;

    sale_details.value.unshift({
        product: product_selected.value,
        unidad_medida: product_selected.value.unidad_medida,
        price_base: price_base.value,
        price_final: PRECIO_FINAL,
        quantity: quantity.value,
        discount: DISCOUNT_TOTAL,
        igv: IGV,
        subtotal: SUBTOTAL,
        per_icbper: fact_icbper, //porcentaje de icbper
        icbper: icbper,
        tip_afe_igv: type_operation.value,
        percentage_isc: 0,
        isc: 0
        
    });

    sumDetails();
    resetDataItem();

}
const deleteSaleDetail = (index: number) => {
    sale_details.value.splice(index, 1);
    sumDetails();
}
const resetDataItem = () => {
    productSelectr.value.setValue("");
    product_selected.value = undefined;
    price_base.value = 0;
    quantity.value = 0;
    discount.value = 0;
    type_operation.value = "10";
}


const getIgvTotal = () => {
    return Number((igv_total.value - (igv_discount_global.value)).toFixed(4));
}
const getSubTotalSale = () => {
    return Number((sale_subtotal.value - (discount_global.value ?? 0)).toFixed(4));
}
const getTotalSales = () => {
    //lo que hace es sumar el total de la venta menos el descuento global pero el descuento global se suma el igv del descuento
    return Number(((sale_total.value + icbper_total.value + isc_total.value + total_percepcion.value) - (discount_global.value + (igv_discount_global.value + total_retencion.value + total_detraccion.value))).toFixed(4));
}
const getTotalCalc = () => {
    return Number(((sale_total.value + icbper_total.value + isc_total.value) - (discount_global.value + igv_discount_global.value)).toFixed(4));
}

const sumDetails = () => {
  //tip_afe_igv es 11 si es grabado retiro por premio 
    igv_total.value = Number((sale_details.value.filter((sale_detail: any) => ![11].includes(Number(sale_detail.tip_afe_igv)))
        .reduce((sum: number, sale_detail: any) => (sum + sale_detail.igv), 0)).toFixed(4));//reduce es una funcion de javascript que se usa para sumar

    icbper_total.value = Number((sale_details.value.filter((sale_detail: any) => ![11].includes(Number(sale_detail.tip_afe_igv)))
        .reduce((sum: number, sale_detail: any) => (sum + sale_detail.icbper), 0)).toFixed(4));

    isc_total.value = Number((sale_details.value.filter((sale_detail: any) => ![11].includes(Number(sale_detail.tip_afe_igv)))
        .reduce((sum: number, sale_detail: any) => (sum + sale_detail.isc), 0)).toFixed(4));

    sale_subtotal.value = Number((sale_details.value.filter((sale_detail: any) => ![11].includes(Number(sale_detail.tip_afe_igv)))
        .reduce((sum: Number, sale_detail: any) => sum + sale_detail.subtotal, 0)).toFixed(4));

        //el discount_total es el total de los descuentos de cada item
    discount_total.value = Number((sale_details.value.filter((sale_detail: any) => ![11].includes(Number(sale_detail.tip_afe_igv)))
        .reduce((sum: number, sale_detail) => sum + sale_detail.discount, 0)).toFixed(4));

    
    total_payments.value = Number((sale_payments.value.reduce((sum: number, payment: any) => sum + payment.amount, 0)).toFixed(2));

    discount_global_general.value = 0;
    igv_discount_general.value = 0;
    igv_discount_global.value = 0;

    if (discount_global.value > 0) {
         discount_global_general.value += discount_global.value; 
        igv_discount_global.value = discount_global.value * 0.18;
        igv_discount_general.value += discount_global.value;
    }

    sale_total.value = Number((igv_total.value + sale_subtotal.value).toFixed(4));
    


    total_retencion.value = 0;
    total_detraccion.value = 0;
    total_percepcion.value = 0;
    switch (retencion_igv.value) {
        case 1: //retencion  
            total_retencion.value = Number((getTotalCalc() * 0.03).toFixed(4));
            break;

        case 2: //detraccion
            total_detraccion.value = Number((getTotalCalc() * 0.04).toFixed(4));
            break;

        case 3://percepcion
            total_percepcion.value = Number((getTotalCalc() * 0.04).toFixed(4));
            break;

        default:
            break;
    }

}

watch(retencion_igv, (value) => {
    sumDetails();
});


watch(type_payment, (value) => {
    setTimeout(() => {
        method_payment.value = "";
        amount.value = 0;
        date_payment.value = "";
        sumDetails();
    }, 50);
});

watch(is_exportacion, (value) => {
    if (value == 1) {
        currency.value == "$. ";
    } else {
        currency.value = "S/. ";
    }
    sale_details.value = [],
        sumDetails();
    discount_global.value = 0;
})


const addPayment = () => {

    if (!method_payment.value) {
        (Swal as TVueSwalInstance).fire('Error', 'El metodo de pago es obligatorio.', 'error');
        return;
    }

    if (amount.value <= 0) {
        (Swal as TVueSwalInstance).fire('Error', 'Necesitas digitar el monto.', 'error');
        return;
    }

    if (type_payment.value == 2 && !date_payment.value) {
        (Swal as TVueSwalInstance).fire('Error', 'La fecha de pago es obligatorio.', 'error');
        return;
    }

    //validar que el monto de pago no sea mayor al total de la venta 
    if (getTotalSales() < (amount.value + total_payments.value)) {
        (Swal as TVueSwalInstance).fire('Error', 'El monto de pago no puede ser mayor al total de la venta.', 'error');
        return;
    }

    if (method_payment.value == 'EFECTIVO' && sale_payments.value.length > 0) {
        (Swal as TVueSwalInstance).fire('Error', 'La venta ya tiene un metodo de pago en efectivo, y no puedes agregar otro.', 'error');
        return;
    }
    /* if (type_payment.value == 1 && (getTotalSales() != amount.value)) {
         (Swal as TVueSwalInstance).fire('Error', 'El monto de pago debe ser igual al total de la venta.', 'error');
         return;
     }*/



    sale_payments.value.push({
        method_payment: method_payment.value,
        amount: amount.value,
        date_payment: date_payment.value
    });

    setTimeout(() => {
        method_payment.value = "";
        amount.value = 0;
        date_payment.value = "";
        sumDetails();
    }, 50);


}
const removePayment = (index: number) => {
    sale_payments.value.splice(index, 1);
    sumDetails();
}
const store = async () => {
    try {
        if (!client_selected.value) {
            (Swal as TVueSwalInstance).fire({
                icon: 'error',
                title: 'Error',
                text: 'Necesitas seleccionar un Cliente',
            });
            return;
        }
        if (sale_details.value.length == 0) {
            (Swal as TVueSwalInstance).fire({
                icon: 'error',
                title: 'Error',
                text: 'Necesitas agregar un producto',
            });
            return;
        }
        if (state_sale.value == 1 && sale_payments.value.length == 0 && getTotalSales() > 0) { //state_sale es 1= venta y 2 cotización
            (Swal as TVueSwalInstance).fire({
                icon: 'error',
                title: 'Error',
                text: 'Debes agregar al menos un pago cuando es una venta',
            });
            return;
        }

        let STATE_PAYMENT = 1;
        if (state_sale.value == 1) {
            if (total_payments.value != getTotalSales()) {
                STATE_PAYMENT = 2;
            }
            if (total_payments.value == getTotalSales()) {
                STATE_PAYMENT = 3;
            }
        }


        let data = {
            client_id: client_selected.value.id,
            type_client: client_selected.value.type_client,
            discount: discount_total.value,
            subtotal: sale_subtotal.value,
            total: sale_total.value,
            igv: igv_total.value,
            state_sale: state_sale.value,
            state_payment: STATE_PAYMENT,
            debt: getTotalSales() - total_payments.value,
            paid_out: total_payments.value,
            sale_details: sale_details.value,
            type_payment: type_payment.value,
            payments: sale_payments.value,

            retencion_igv: retencion_igv.value,
            discount_global: discount_global.value,
            description: description.value,
            serie: serie.value,

            igv_discount_general: igv_discount_general.value,
            is_exportacion: is_exportacion.value,
            currency: currency.value,//tipo de moneda

        }
        const resp: AxiosResponse<SaleResponse> = await httpClient.post("sales", data);
        //console.log(resp);
        if (resp.data.error) {
            (Swal as TVueSwalInstance).fire(
                "Hubo un error  interno!",
                resp.data.error.message,
                "error",
            );
        }

        if (resp.data.code == 200) {

            // Mostrar alerta y esperar a que el usuario la cierre
            await (Swal as TVueSwalInstance).fire(
                "Buen trabajo!",
                resp.data.message,
                "success",
            );
            resetData();
        }

        // config();//para que el numero de venta o trasaccion se actualice

    } catch (e: any) {
        if (e.response?.data) {
            (Swal as TVueSwalInstance).fire(
                "Upss!",
                e.response?.data.message, "error",
            );
            return;
        }
    }
}

const resetData = () => {
    client_selected.value = undefined;
    discount_total.value = 0;
    sale_subtotal.value = 0;
    sale_total.value = 0;
    igv_total.value = 0;
    state_sale.value = 1;
    total_payments.value = 0;
    description.value = '';

    sale_details.value = [];
    sale_payments.value = [];
    type_payment.value = 1;
    clientSelectr.value.setValue('');//limpia el selectr de clientes
    sumDetails();
    getIgvTotal();
    retencion_igv.value = 0;
    total_retencion.value = 0;
    total_detraccion.value = 0;
    total_percepcion.value = 0;
    discount_global.value = 0;
    //anticipos
    is_anticipo.value = 0;
    n_comprobante_anticipo.value = '';
    sale_anticipo.value = 0;


    serie.value = '';
    igv_discount_general.value = 0;
    is_exportacion.value = 0;
}


/**
 * Agrega un producto a la lista de detalles de la venta
 * 
 * @throws {Error} Si el producto ya se encuentra en la lista
 * @throws {Error} Si no se ha seleccionado un producto
 * @throws {Error} Si no se ha digitado la cantidad del producto
 * @throws {Error} Si no se ha digitado el precio unitario del producto
 */


onMounted(() => {
    config();
});
</script>
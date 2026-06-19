<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title> Clientes / Empresa</b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3">
                        <b-col lg="7" class="text-center">
                            <label for="search-client" class="visually-hidden">Buscar cliente</label>
                            <b-form-input type="text" id="search-client" v-model="search"
                                placeholder="Buscar cliente por nombre" @keyup.enter="list" />
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
                            <b-button type="button" variant="success" @click="openModal()">
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
                                <b-th>Nombre / Razon Social</b-th>
                                <b-th>T. Doc</b-th>
                                <b-th>N° Documento</b-th>
                                <b-th>Telefono</b-th>
                                <b-th>Tipo Cliente</b-th>
                                <b-th>Ubigeo</b-th>
                                <b-th>Estado</b-th>
                                <b-th>Fech. Registro</b-th>
                                <b-th class="text-end">Action</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(client, index) in clients" :key="index">
                                <b-td>{{ clients.length - index }}</b-td>
                                <b-td>{{ client.full_name }}</b-td>
                                <b-td>{{ client.type_document }}</b-td>
                                <b-td>{{ client.n_document }}</b-td>
                                <b-td>{{ client.phone }}</b-td>
                                <b-td v-if="client.type_client == '1'">CLIENTE FINAL</b-td>
                                <b-td v-if="client.type_client == '2'">CLIENTE EMPRESA</b-td>
                                <b-td>{{ client.distrito }}</b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="client.state == 1">Activo</b-badge>
                                    <b-badge variant="danger" v-if="client.state == 0">Inactivo</b-badge>
                                </b-td>
                                <b-td>{{ client.created_at }}</b-td>
                                <b-td class="text-end">
                                    <a href="#" @click.prevent="editClient(client)">
                                        <i class="las la-pen text-secondary fs-22"></i>
                                    </a>{{ " " }}
                                    <a href="#" @click.prevent="removeClient(client)">
                                        <i class="las la-trash-alt text-secondary fs-22"></i>
                                    </a>
                                </b-td>
                            </b-tr>
                        </b-tbody>
                    </b-table-simple>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Previous" next-text="Next" />
                </b-card-body>
            </b-col>
        </b-row>

        <!-- Modal de Registro/Edición -->
        <b-modal v-if="ModalRegisterClient" v-model="ModalRegisterClient"
            :title="`🧑 ${client_selected ? 'Edición' : 'Registro'} de Cliente`" :header-class="`bg-${themeColor}`"
            title-class="m-0 text-white" :ok-variant="themeColor" hide-footer centered size="lg">
            <b-row>
                <b-col lg="3">
                    <label for="type_client_list" class="col-form-label text-lg-end">Tipo de Cliente: </label>
                    <b-form-select id="type_client_list" v-model="type_client">
                        <option value="1">CLIENTE FINAL</option>
                        <option value="2">CLIENTE EMPRESA</option>
                        <option value="3">CLIENTE CUALQUIERA</option>
                    </b-form-select>
                </b-col>

                <b-col lg="3">
                    <label for="type-document-client" class="col-form-label text-lg-end">Tipo de documento: </label>
                    <b-form-select id="type-document-client" v-model="type_document">
                        <option value="SND">SIN DOCUMENTO</option>
                        <option value="DNI">DNI</option>
                        <option value="RUC">RUC</option>
                        <option value="PASAPORTE">PASAPORTE</option>
                        <option value="CARNET DE EXTRANJERIA">CARNET DE EXTRANJERIA</option>
                        <option value="TARJETA MILITAR">TARJETA MILITAR</option>
                    </b-form-select>
                </b-col>

                <b-col lg="4">
                    <label for="n_document-client" class="col-form-label text-lg-end">N° de Documento: </label>
                    <b-form-input type="number" id="n_document-client" v-model="n_document"
                        placeholder="Example: ######" :disabled="isSearching" />
                </b-col>

                <b-col lg="2" class="mt-4">
                    <label for="buscar_online" class="col-form-label text-lg-end visually-hidden">Buscar</label>
                    <b-button id="buscar_online" type="button" variant="success" @click="searchDocument"
                        :disabled="isSearching || !n_document">
                        <i :class="isSearching ? 'fas fa-spinner fa-spin' : 'fas fa-search'"></i>
                    </b-button>
                </b-col>

                <!-- Campos para DNI, PASAPORTE, etc (Persona Natural) -->
                <template v-if="type_document !== 'RUC'">
                    <b-col lg="6">
                        <label for="name-client" class="col-form-label text-lg-end">Nombre: </label>
                        <b-form-input type="text" id="name-client" v-model="name" placeholder="Example: Jose" />
                    </b-col>
                    <b-col lg="6">
                        <label for="surname-client" class="col-form-label text-lg-end">Apellido: </label>
                        <b-form-input type="text" id="surname-client" v-model="surname"
                            placeholder="Example: Pérez García" />
                    </b-col>
                </template>

                <!-- Campos para RUC (Empresa) -->
                <template v-else>
                    <b-col lg="6">
                        <label for="full_name-client" class="col-form-label text-lg-end">Razón Social: </label>
                        <b-form-input type="text" id="full_name-client" v-model="full_name"
                            placeholder="Example: EMPRESA S.A.C" />
                    </b-col>
                    <b-col lg="6">
                        <label for="name_comerc-client" class="col-form-label text-lg-end">Nombre Comercial: </label>
                        <b-form-input type="text" id="name_comerc-client" v-model="name_comerc"
                            placeholder="Example: Mi Tienda" />
                    </b-col>
                </template>

                <b-col lg="4">
                    <label for="email-client" class="col-form-label text-lg-end">Email: </label>
                    <b-form-input type="email" id="email-client" v-model="email"
                        placeholder="Example: laravest@gmail.com" />
                </b-col>

                <b-col lg="4">
                    <label for="phone-client" class="col-form-label text-lg-end">Telefono: </label>
                    <b-form-input type="text" id="phone-client" v-model="phone" placeholder="Example: 900 900 900" />
                </b-col>

                <!-- Solo mostrar fecha de nacimiento si NO es RUC -->
                <b-col lg="4">
                    <label for="birth_date-client" class="col-form-label text-lg-end">Fecha de Nacimiento: </label>
                    <b-form-input type="date" id="birth_date-client" v-model="birth_date"
                        placeholder="Example: 2011-08-19" />
                </b-col>

                <!-- Solo mostrar género si NO es RUC -->
                <b-col lg="3" v-if="type_document !== 'RUC'">
                    <label class="col-form-label text-lg-end">Genero: </label>
                    <b-form-radio name="gender-client" @click="gender = 'M'" value="M"
                        :checked="gender == 'M'">Masculino</b-form-radio>
                    {{ " " }}
                    <b-form-radio name="gender-client" @click="gender = 'F'" value="F"
                        :checked="gender == 'F'">Femenino</b-form-radio>
                </b-col>

                <b-col :lg="type_document === 'RUC' ? '6' : '3'">
                    <label class="col-form-label text-lg-end">Estado: </label>
                    <b-form-radio name="state-client" @click="state = 1" value="1"
                        :checked="state == 1">Activo</b-form-radio>
                    {{ " " }}
                    <b-form-radio name="state-client" @click="state = 2" value="2"
                        :checked="state == 2">Inactivo</b-form-radio>
                </b-col>

                <b-col lg="6">
                    <label for="address-client" class="col-form-label text-lg-end">Dirección: </label>
                    <b-form-input type="text" id="address-client" v-model="address" placeholder="Example: Psje Lt 18" />
                </b-col>

                <b-col lg="4">
                    <label for="region_list" class="col-form-label text-lg-end">Región: </label>
                    <b-form-select id="region_list" v-model="ubigeo_region">
                        <template v-for="(REGIONE, index) in REGIONES_L" :key="index">
                            <option :value="REGIONE.id">{{ REGIONE.name }}</option>
                        </template>
                    </b-form-select>
                </b-col>

                <b-col lg="4">
                    <label for="provincia_list" class="col-form-label text-lg-end">Provincia: </label>
                    <b-form-select id="provincia_list" v-model="ubigeo_provincia">
                        <template v-for="(PROVINCIA, index) in PROVINCIA_SELECTS" :key="index">
                            <option :value="PROVINCIA.id">{{ PROVINCIA.name }}</option>
                        </template>
                    </b-form-select>
                </b-col>

                <b-col lg="4">
                    <label for="distrito_list" class="col-form-label text-lg-end">Distrito: </label>
                    <b-form-select id="distrito_list" v-model="ubigeo_distrito">
                        <template v-for="(DISTRITO, index) in DISTRITO_SELECTS" :key="index">
                            <option :value="DISTRITO.id">{{ DISTRITO.name }}</option>
                        </template>
                    </b-form-select>
                </b-col>

                <b-col lg="12" class="mt-3">
                    <div class="modal-footer">
                        <b-button type="button" variant="secondary" @click="ModalRegisterClient = false">
                            Cerrar
                        </b-button>
                        <b-button type="button" variant="primary" @click="store">
                            {{ client_selected ? 'Actualizar' : 'Registrar' }}
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
import { ref, onMounted, watch, computed, nextTick } from 'vue';
import Swal from "sweetalert2/dist/sweetalert2.js";
import type { Client, ClientResponse, Clients, UbigeoClient } from '@/types/clients';
import REGIONES from './json/regiones.json'
import PROVINCIAS from './json/provincias.json'
import DISTRITOS from './json/distritos.json'

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const ModalRegisterClient = ref<Boolean>(false);
const search = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);
const themeColor = ref<string>('primary');
const isSearching = ref<boolean>(false);

const clients = ref<Client[]>([]);

// Campos del formulario
const type_client = ref<string>("1");
const type_document = ref<string>("DNI");
const n_document = ref<string>("");

// Campos para Persona Natural (DNI, PASAPORTE, etc)
const name = ref<string>("");
const surname = ref<string>("");

// Campos para Empresa (RUC)
const full_name = ref<string>("");
const name_comerc = ref<string>("");

// Campos comunes
const email = ref<string>("");
const phone = ref<string>("");
const birth_date = ref<Date | string>();
const gender = ref<string>("M");
const state = ref<number>(1);
const address = ref<string>("");

// Ubigeo
const ubigeo_region = ref<string>("");
const ubigeo_provincia = ref<string>("");
const ubigeo_distrito = ref<string>("");
const region = ref<string>("");
const provincia = ref<string>("");
const distrito = ref<string>("");

const REGIONES_L = REGIONES;
const PROVINCIAS_L = PROVINCIAS;
const DISTRITOS_L = DISTRITOS;

const PROVINCIA_SELECTS = ref<UbigeoClient[]>([]);
const DISTRITO_SELECTS = ref<UbigeoClient[]>([]);

const client_selected = ref<Client | undefined>(undefined);

// Función para buscar en la API de DNI/RUC
const searchDocument = async () => {
    if (!n_document.value || String(n_document.value).trim() === '') {
        (Swal as TVueSwalInstance).fire('Error', 'Ingrese un número de documento', 'error');
        return;
    }

    if (type_document.value !== 'DNI' && type_document.value !== 'RUC') {
        (Swal as TVueSwalInstance).fire('Información', 'La búsqueda online solo está disponible para DNI y RUC', 'info');
        return;
    }

    isSearching.value = true;

    try {
        const endpoint = type_document.value.toLowerCase();

        // Llamar al endpoint de tu backend Laravel
        const response: AxiosResponse = await httpClient.get(
            `/search-document/${endpoint}/${n_document.value}`
        );

        if (response.data.success === false) {
            (Swal as TVueSwalInstance).fire('Error', response.data.message || 'No se encontró información', 'error');
            return;
        }

        if (type_document.value === 'DNI') {
            // Llenar campos para DNI
            name.value = response.data.nombres || '';
            surname.value = `${response.data.apellidoPaterno || ''} ${response.data.apellidoMaterno || ''}`.trim();

            (Swal as TVueSwalInstance).fire('Éxito', 'Datos cargados correctamente', 'success');
        } else if (type_document.value === 'RUC') {
            // Llenar campos para RUC
            full_name.value = response.data.razonSocial || '';
            name_comerc.value = response.data.nombreComercial || '';

            // Llenar dirección si existe
            if (response.data.direccion) {
                address.value = response.data.direccion;
            }

            // Llenar teléfono si existe
            if (response.data.telefonos && response.data.telefonos.length > 0) {
                phone.value = response.data.telefonos[0];
            }

            // Buscar y asignar ubigeo si existe
            if (response.data.departamento && response.data.provincia && response.data.distrito) {
                assignUbigeoFromAPI(
                    response.data.departamento,
                    response.data.provincia,
                    response.data.distrito
                );
            }

            (Swal as TVueSwalInstance).fire('Éxito', 'Datos de la empresa cargados correctamente', 'success');
        }
    } catch (error: any) {
        console.error('Error al consultar la API:', error);

        let errorMessage = 'Error al consultar el documento';

        if (error.response) {
            if (error.response.status === 404) {
                errorMessage = 'Documento no encontrado';
            } else if (error.response.status === 401) {
                errorMessage = 'Token de API inválido o expirado';
            } else if (error.response.data?.message) {
                errorMessage = error.response.data.message;
            }
        } else if (error.message) {
            errorMessage = error.message;
        }

        (Swal as TVueSwalInstance).fire('Error', errorMessage, 'error');
    } finally {
        isSearching.value = false;
    }
};

// Función auxiliar para asignar ubigeo basado en nombres de la API
// Función auxiliar para asignar ubigeo basado en nombres de la API

// Función auxiliar para normalizar texto (quitar acentos y convertir a minúsculas)
const normalizeText = (text: string): string => {
    return text
        .toLowerCase()// Convertir a minúsculas
        .normalize('NFD')// Descomponer caracteres compuestos ejemplo: Ñ
        .replace(/[\u0300-\u036f]/g, '') // Quitar acentos
        .trim();// Eliminar espacios en blanco
};

// Función auxiliar para asignar ubigeo basado en nombres de la API
const assignUbigeoFromAPI = (departamento: string, provincia: string, distrito: string) => {
    console.log('Buscando ubigeo:', { departamento, provincia, distrito });

    const depNormalized = normalizeText(departamento);
    const provNormalized = normalizeText(provincia);
    const distNormalized = normalizeText(distrito);

    console.log('Texto normalizado:', { depNormalized, provNormalized, distNormalized });

    // Buscar departamento/región
    const foundRegion = REGIONES_L.find(r => {
        const regionNormalized = normalizeText(r.name);
        return regionNormalized.includes(depNormalized) || depNormalized.includes(regionNormalized);
    });

    if (foundRegion) {
        console.log('Región encontrada:', foundRegion);
        ubigeo_region.value = foundRegion.id;

        // Buscar provincia directamente en PROVINCIAS_L
        const foundProvincia = PROVINCIAS_L.find(p => {
            const provinciaName = normalizeText(p.name);
            return p.department_id === foundRegion.id &&
                (provinciaName.includes(provNormalized) || provNormalized.includes(provinciaName));
        });

        if (foundProvincia) {
            console.log('Provincia encontrada:', foundProvincia);

            // Esperar a que el watch actualice PROVINCIA_SELECTS
            nextTick(() => {
                ubigeo_provincia.value = foundProvincia.id;

                // Buscar distrito directamente en DISTRITOS_L
                const foundDistrito = DISTRITOS_L.find(d => {
                    const distritoName = normalizeText(d.name);
                    return d.province_id === foundProvincia.id &&
                        (distritoName.includes(distNormalized) || distNormalized.includes(distritoName));
                });

                if (foundDistrito) {
                    console.log('Distrito encontrado:', foundDistrito);

                    // Esperar a que el watch actualice DISTRITO_SELECTS
                    nextTick(() => {
                        ubigeo_distrito.value = foundDistrito.id;
                        console.log('Ubigeo asignado correctamente:', {
                            region: ubigeo_region.value,
                            provincia: ubigeo_provincia.value,
                            distrito: ubigeo_distrito.value
                        });
                    });
                } else {
                    console.log('Distrito no encontrado. Distritos disponibles:',
                        DISTRITOS_L.filter(d => d.province_id === foundProvincia.id).map(d => d.name));
                }
            });
        } else {
            console.log('Provincia no encontrada. Provincias disponibles:',
                PROVINCIAS_L.filter(p => p.department_id === foundRegion.id).map(p => p.name));
        }
    } else {
        console.log('Región no encontrada. Regiones disponibles:', REGIONES_L.map(r => r.name));
    }
};

// Función para abrir el modal
const openModal = async (client?: Client) => {
    if (client) {
        client_selected.value = client;
        type_client.value = client.type_client || "1";
        type_document.value = client.type_document || "DNI";
        n_document.value = client.n_document || "";

        // Si es RUC
        if (client.type_document === "RUC") {
            full_name.value = client.full_name || "";
            name_comerc.value = client.name_comerc || "";
        } else {
            // Si es persona natural
            name.value = client.name || "";
            surname.value = client.surname || "";
            full_name.value = `${name.value} ${surname.value}`;
        }

        email.value = client.email || "";
        phone.value = client.phone || "";
        birth_date.value =  client.birth_date|| "";
        gender.value = client.gender || "M";
        state.value = client.state || 1;
        address.value = client.address || "";

        // Ubigeo
        ubigeo_region.value = client.ubigeo_region || "";
        ubigeo_provincia.value = client.ubigeo_provincia || "";
        ubigeo_distrito.value = client.ubigeo_distrito || "";
        region.value = client.region || "";
        provincia.value = client.provincia || "";
        distrito.value = client.distrito || "";

        // Ubigeo - Cargar en cascada
        if (client.ubigeo_region) {
            ubigeo_region.value = client.ubigeo_region;

            // Esperar a que se carguen las provincias
            await nextTick();//nextTick(); es una función de Vue que permite esperar a que se actualicen los selects

            if (client.ubigeo_provincia) {
                ubigeo_provincia.value = client.ubigeo_provincia;

                // Esperar a que se carguen los distritos
                await nextTick();

                if (client.ubigeo_distrito) {
                    ubigeo_distrito.value = client.ubigeo_distrito;
                }
            }
        }

    }

    await nextTick();// Esperar a que se actualicen los selects
    ModalRegisterClient.value = true;
};

const list = async () => {
    try {
        const res: AxiosResponse<Clients> = await httpClient.get(
            `clients?page=${currentPage.value}&search=${search.value ?? ''}`);

        clients.value = res.data.clients.data;
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
    type_client.value = "1";
    type_document.value = "DNI";
    n_document.value = "";
    name.value = "";
    surname.value = "";
    full_name.value = "";
    name_comerc.value = "";
    email.value = "";
    phone.value = "";
    birth_date.value = "";
    gender.value = "M";
    state.value = 1;
    address.value = "";
    ubigeo_region.value = "";
    ubigeo_provincia.value = "";
    ubigeo_distrito.value = "";
    region.value = "";
    provincia.value = "";
    distrito.value = "";
};

watch(ubigeo_region, (regionId) => {
    PROVINCIA_SELECTS.value = PROVINCIAS_L
        .filter(p => p.department_id === regionId)
        .map(p => ({
            id: p.id,
            name: p.name,
            department_id: p.department_id,
            province_id: p.id,
            region: REGIONES_L.find(r => r.id === p.department_id)?.name || ""
        }));
    ubigeo_provincia.value = "";
    ubigeo_distrito.value = "";
    DISTRITO_SELECTS.value = [];
});

watch(ubigeo_provincia, (provinciaId) => {
    DISTRITO_SELECTS.value = DISTRITOS_L
        .filter(d => d.province_id === provinciaId)
        .map(d => ({
            id: d.id,
            name: d.name,
            department_id: d.department_id,
            province_id: d.province_id,
            region: REGIONES_L.find(r => r.id === d.department_id)?.name || ""
        }));
    ubigeo_distrito.value = "";
});

const store = async () => {
    try {
        if (type_document.value === 'RUC') {
            if (full_name.value.trim() === '') {
                const swal = Swal as TVueSwalInstance;
                await swal.fire('Error', 'La razón social es obligatoria.', 'error');
                return;
            }
        } else {
            if (name.value.trim() === '' || surname.value.trim() === '') {
                const swal = Swal as TVueSwalInstance;
                await swal.fire('Error', 'El nombre y apellido son obligatorios.', 'error');
                return;
            }
        }

        if (n_document.value.toString().trim() === '') {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El número de documento es obligatorio.', 'error');
            return;
        }


        let REGION_SELECTED = REGIONES_L.find((region: any) => region.id == ubigeo_region.value);
        if (REGION_SELECTED) {
            region.value = REGION_SELECTED.name;
        }
        let PROVINCIA_SELECTED = PROVINCIAS_L.find((provincia: any) => provincia.id == ubigeo_provincia.value);
        if (PROVINCIA_SELECTED) {
            provincia.value = PROVINCIA_SELECTED.name;
        }
        let DISTRITO_SELECTED = DISTRITOS_L.find((distrito: any) => distrito.id == ubigeo_distrito.value);
        if (DISTRITO_SELECTED) {
            distrito.value = DISTRITO_SELECTED.name;
        }



        let data: any = {
            type_client: type_client.value,
            type_document: type_document.value,
            n_document: n_document.value,
            email: email.value,
            phone: phone.value,
            state: state.value,
            address: address.value,
            ubigeo_region: ubigeo_region.value,
            ubigeo_provincia: ubigeo_provincia.value,
            ubigeo_distrito: ubigeo_distrito.value,
            region: region.value,
            provincia: provincia.value,
            distrito: distrito.value
        };

        if (type_document.value === 'RUC') {
            data.full_name = full_name.value;
            data.name_comerc = name_comerc.value;
        } else {
            data.name = name.value;
            data.surname = surname.value;
            data.full_name = `${name.value} ${surname.value}`;
            data.birth_date = birth_date.value;
            data.gender = gender.value;
        }

        const res: AxiosResponse<ClientResponse> = !client_selected.value
            ? await httpClient.post("clients", data)
            : await httpClient.put("clients/" + client_selected.value?.id, data);

        if (res.data.code == 405) {
            (Swal as TVueSwalInstance).fire("Upps!", res.data.message, "warning");
        } else {
            ModalRegisterClient.value = false;

            if (!client_selected.value) {
                if (res.data.client) {
                    clients.value.unshift(res.data.client);
                }
            } else {
                const INDEX = clients.value.findIndex((cat) => cat.id == client_selected.value?.id);
                if (INDEX != -1 && res.data.client) {
                    clients.value[INDEX] = res.data.client;
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

const editClient = (client: Client) => {
    openModal(client);
};

const removeClient = (client: Client) => {
    try {
        (Swal as TVueSwalInstance)
            .fire({
                title: "Confirmar la eliminación",
                text: `¿Estás seguro de eliminar al cliente '${client.full_name}'?`,
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Sí, eliminarlo!",
                cancelButtonText: "Cancelar"
            })
            .then(async (result: any) => {
                if (result.isConfirmed) {
                    const res: AxiosResponse<ClientResponse> = await httpClient.delete("clients/" + client.id);

                    (Swal as TVueSwalInstance).fire(
                        "Eliminado!",
                        `El cliente [${client.full_name}] ha sido eliminado con éxito.`,
                        "success"
                    );

                    let INDEX = clients.value.findIndex((cli) => cli.id == client.id);
                    if (INDEX != -1) {
                        clients.value.splice(INDEX, 1);
                    }
                }
            });
    } catch (error) {
        console.log(error);
    }
};

watch(type_document, (newValue) => {
    if (newValue === 'RUC') {
        name.value = "";
        surname.value = "";
        birth_date.value = "";
        gender.value = "M";
    } else {
        full_name.value = "";
        name_comerc.value = "";
    }
});

watch(ModalRegisterClient, (value) => {
    if (!value) {
        client_selected.value = undefined;
        clearFields();
    }
});

watch(currentPage, () => list());

onMounted(() => {
    list();
});
</script>

<style scoped>
.modal.fade .modal-dialog {
    transition: transform 0.3s ease-out;
}

.modal.show .modal-dialog {
    transform: none;
}

.modal-backdrop.fade {
    transition: opacity 0.15s linear;
}
</style>
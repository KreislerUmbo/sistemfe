<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>
                        <i class="fas fa-users me-2 text-primary"></i>Clientes
                    </b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3 gap-2">
                        <b-col lg="6">
                            <label for="search-client" class="visually-hidden">Buscar cliente</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <b-form-input type="text" id="search-client" v-model="search"
                                    placeholder="Buscar por nombre, documento…" @keyup.enter="list" />
                                <b-button type="button" variant="outline-secondary" @click="reset">
                                    <i class="fas fa-sync"></i>
                                </b-button>
                            </div>
                        </b-col>
                        <b-col lg="5" class="d-flex gap-2 justify-content-end flex-wrap">
                            <b-button type="button" variant="primary" @click="openModal()">
                                <i class="fas fa-user-plus me-1"></i> Nuevo Cliente
                            </b-button>
                            <!-- Venta rápida consumidor sin identificar (boleta < S/ 700) -->
                            <b-button type="button" variant="outline-secondary" @click="setClienteSinDatos()"
                                title="Consumidor final sin datos — boleta menor a S/ 700">
                                <i class="fas fa-bolt me-1"></i> Sin Datos
                            </b-button>
                        </b-col>
                    </b-row>
                </b-card-header>

                <b-card-body class="pt-0 mt-2">
                    <b-table-simple responsive class="mb-0 table-centered">
                        <b-thead class="table-light">
                            <b-tr>
                                <b-td>Nro</b-td>
                                <b-th>Nombre / Razón Social</b-th>
                                <b-th>T. Doc</b-th>
                                <b-th>N° Documento</b-th>
                                <b-th>Teléfono</b-th>
                                <b-th>Tipo</b-th>
                                <b-th>Ubigeo</b-th>
                                <b-th>Amazonía</b-th>
                                <b-th>Estado</b-th>
                                <b-th>Fech. Registro</b-th>
                                <b-th class="text-end">Acción</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(client, index) in clients" :key="index">
                                <b-td>{{ clients.length - index }}</b-td>
                                <b-td class="fw-semibold">{{ client.full_name }}</b-td>
                                <b-td>
                                    <span class="badge bg-secondary-subtle text-secondary border small">
                                        {{ client.type_document }}
                                    </span>
                                </b-td>
                                <b-td>{{ client.n_document }}</b-td>
                                <b-td>{{ client.phone }}</b-td>
                                <b-td>
                                    <span class="badge" :class="client.type_client == '1' ? 'bg-success-subtle text-success border border-success-subtle'
                                        : client.type_client == '2' ? 'bg-info-subtle text-info border border-info-subtle'
                                            : 'bg-warning-subtle text-warning border border-warning-subtle'">
                                        {{ client.type_client == '1' ? 'Final'
                                            : client.type_client == '2' ? 'Empresa' : 'Cualquiera' }}
                                    </span>
                                </b-td>
                                <b-td class="small text-muted">{{ client.distrito }}, {{ client.region }}</b-td>
                                <b-td class="text-center">
                                    <span v-if="client.es_amazonia"
                                        class="badge bg-success-subtle text-success border border-success-subtle"
                                        title="Zona Amazonía — Ley 27037">
                                        <i class="fas fa-tree me-1"></i>Sí
                                    </span>
                                    <span v-else class="text-muted small">—</span>
                                </b-td>
                                <b-td>
                                    <span class="badge" :class="client.state == 1 ? 'bg-success' : 'bg-danger'">
                                        {{ client.state == 1 ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </b-td>
                                <b-td class="small text-muted">{{ client.created_at }}</b-td>
                                <b-td class="text-end">
                                    <b-button type="button" variant="outline-warning" size="sm" class="me-1"
                                        @click="editClient(client)">
                                        <i class="fas fa-edit"></i>
                                    </b-button>
                                    <b-button type="button" variant="outline-danger" size="sm"
                                        @click="removeClient(client)">
                                        <i class="fas fa-trash-alt"></i>
                                    </b-button>
                                </b-td>
                            </b-tr>
                        </b-tbody>
                    </b-table-simple>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Anterior" next-text="Siguiente" />
                </b-card-body>
            </b-col>
        </b-row>

        <!-- ╔══════════════════════════════════════════════════════╗
             ║  MODAL REGISTRO / EDICIÓN DE CLIENTE                ║
             ╚══════════════════════════════════════════════════════╝ -->
        <b-modal v-if="ModalRegisterClient" v-model="ModalRegisterClient"
            :title="`${client_selected ? '✏️ Editar' : '➕ Nuevo'} Cliente`" :header-class="`bg-${themeColor}`"
            title-class="m-0 text-white" hide-footer centered size="xl">

            <div class="row g-3">

                <!-- ── FILA 1: Tipo cliente + Tipo documento + N° doc + Buscar ── -->
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold text-secondary mb-1">
                        <i class="fas fa-tag me-1"></i>Tipo de Cliente
                    </label>
                    <select class="form-select form-select-sm" v-model="type_client">
                        <option value="1">Cliente Final</option>
                        <option value="2">Cliente Empresa</option>
                        <option value="3">Cliente Cualquiera</option>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold text-secondary mb-1">
                        <i class="fas fa-id-card me-1"></i>Tipo de Documento
                    </label>
                    <select class="form-select form-select-sm" v-model="type_document">
                        <option value="DNI">DNI</option>
                        <option value="RUC">RUC</option>
                        <option value="CE">Carnet de Extranjería</option>
                        <option value="PAS">Pasaporte</option>
                        <option value="TM">Tarjeta Militar</option>
                        <option value="SND">Sin Documento</option>
                    </select>
                    <!-- Código SUNAT derivado — informativo -->
                    <small class="text-muted">
                        Cód. SUNAT: <strong>{{ cod_tipo_doc_sunat }}</strong>
                        (Catálogo 06)
                    </small>
                </div>

                <div class="col-8 col-md-4">
                    <label for="n_document-client" class="form-label small fw-semibold text-secondary mb-1">
                        <i class="fas fa-hashtag me-1"></i>N° de Documento
                    </label>
                    <div class="input-group input-group-sm">
                        <b-form-input type="text" id="n_document-client" v-model="n_document" placeholder="Ej: 12345678"
                            :disabled="isSearching" @keyup.enter="searchDocument" />
                        <b-button type="button" variant="success" size="sm" @click="searchDocument"
                            :disabled="isSearching || !n_document || !['DNI', 'RUC'].includes(type_document)"
                            title="Buscar en API (solo DNI y RUC)">
                            <i :class="isSearching ? 'fas fa-spinner fa-spin' : 'fas fa-search'"></i>
                        </b-button>
                    </div>
                </div>

                <div class="col-4 col-md-2 d-flex align-items-end">
                    <!-- Indicador de zona Amazonía -->
                    <div class="w-100">
                        <div v-if="es_amazonia"
                            class="alert alert-success py-1 px-2 mb-0 small d-flex align-items-center gap-1">
                            <i class="fas fa-tree"></i>
                            <span>Zona Amazonía</span>
                        </div>
                        <div v-else
                            class="alert alert-light py-1 px-2 mb-0 small border d-flex align-items-center gap-1 text-muted">
                            <i class="fas fa-city"></i>
                            <span>Zona Normal</span>
                        </div>
                    </div>
                </div>

                <!-- ── FILA 2: Nombre / Razón Social ── -->
                <template v-if="type_document !== 'RUC'">
                    <div class="col-12 col-md-5">
                        <label for="name-client" class="form-label small fw-semibold text-secondary mb-1">
                            Nombre(s)
                        </label>
                        <b-form-input type="text" id="name-client" v-model="name" placeholder="Ej: Juan Carlos"
                            size="sm" />
                    </div>
                    <div class="col-12 col-md-5">
                        <label for="surname-client" class="form-label small fw-semibold text-secondary mb-1">
                            Apellidos
                        </label>
                        <b-form-input type="text" id="surname-client" v-model="surname" placeholder="Ej: Pérez García"
                            size="sm" />
                    </div>
                </template>
                <template v-else>
                    <div class="col-12 col-md-6">
                        <label for="full_name-client" class="form-label small fw-semibold text-secondary mb-1">
                            Razón Social
                        </label>
                        <b-form-input type="text" id="full_name-client" v-model="full_name"
                            placeholder="Ej: EMPRESA S.A.C." size="sm" />
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="name_comerc-client" class="form-label small fw-semibold text-secondary mb-1">
                            Nombre Comercial
                        </label>
                        <b-form-input type="text" id="name_comerc-client" v-model="name_comerc"
                            placeholder="Ej: Mi Tienda" size="sm" />
                    </div>

                <!-- Sección Datos Tributarios -->
                <div class="col-12">
                    
                    <small class="fw-semibold text-secondary"><i class="fas fa-gavel me-1"></i>Datos Tributarios</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Régimen Tributario</label>
                    <select class="form-select form-select-sm" v-model="regimen_tributario">
                       <option value="MYPE">SIN REGIMEN</option>
                        <option value="NRUS">NRUS</option>
                        <option value="RER">RER</option>
                        <option value="GENERAL">General</option>
                        <option value="MYPE">MYPE</option>
                        
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Condición de Domicilio</label>
                    <select class="form-select form-select-sm" v-model="condicion_domicilio">
                        <option value="HABIDO">Habido</option>
                        <option value="NO_HABIDO">No Habido</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="agenteRetencion"
                            v-model="es_agente_retencion">
                        <label class="form-check-label small" for="agenteRetencion">Agente de Retención</label>
                    </div>
                </div>
                <hr class="my-2" />
                </template>

                <!-- ── FILA 3: Email + Teléfono + Fecha nac. (solo no-RUC) + Género ── -->
                <div class="col-12 col-md-4">
                    <label for="email-client" class="form-label small fw-semibold text-secondary mb-1">
                        <i class="fas fa-envelope me-1"></i>Email
                    </label>
                    <b-form-input type="email" id="email-client" v-model="email" placeholder="Ej: cliente@email.com"
                        size="sm" />
                </div>

                <div class="col-6 col-md-3">
                    <label for="phone-client" class="form-label small fw-semibold text-secondary mb-1">
                        <i class="fas fa-phone me-1"></i>Teléfono
                    </label>
                    <b-form-input type="text" id="phone-client" v-model="phone" placeholder="Ej: 900 900 900"
                        size="sm" />
                </div>

                <!-- Fecha nac. y género — solo si NO es RUC -->
                <template v-if="type_document !== 'RUC'">
                    <div class="col-6 col-md-3">
                        <label for="birth_date-client" class="form-label small fw-semibold text-secondary mb-1">
                            <i class="fas fa-birthday-cake me-1"></i>Fecha de Nacimiento
                        </label>
                        <b-form-input type="date" id="birth_date-client" v-model="birth_date" size="sm" />
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Género</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" id="gen-m" value="M" v-model="gender"
                                autocomplete="off">
                            <label class="btn btn-outline-primary btn-sm" for="gen-m">M</label>
                            <input type="radio" class="btn-check" id="gen-f" value="F" v-model="gender"
                                autocomplete="off">
                            <label class="btn btn-outline-danger btn-sm" for="gen-f">F</label>
                        </div>
                    </div>
                </template>

                <!-- ── FILA 4: Dirección ── -->
                <div class="col-12 col-md-8">
                    <label for="address-client" class="form-label small fw-semibold text-secondary mb-1">
                        <i class="fas fa-map-marker-alt me-1"></i>Dirección
                    </label>
                    <b-form-input type="text" id="address-client" v-model="address"
                        placeholder="Ej: Jr. Lima 123, Tarapoto" size="sm" />
                </div>
       

                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold text-secondary mb-1">Estado</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" id="state-1" :value="1" v-model="state"
                            autocomplete="off">
                        <label class="btn btn-outline-success btn-sm" for="state-1">Activo</label>
                        <input type="radio" class="btn-check" id="state-2" :value="2" v-model="state"
                            autocomplete="off">
                        <label class="btn btn-outline-danger btn-sm" for="state-2">Inactivo</label>
                    </div>
                </div>

                <!-- ── FILA 5: Ubigeo en cascada ── -->


                <div class="col-12 col-md-4">
                    <label for="region_list" class="form-label small fw-semibold text-secondary mb-1">
                        Región / Departamento
                    </label>
                    <select id="region_list" class="form-select form-select-sm" v-model="ubigeo_region">
                        <option value="">— Seleccionar —</option>
                        <template v-for="(REGIONE, index) in REGIONES_L" :key="index">
                            <option :value="REGIONE.id">{{ REGIONE.name }}</option>
                        </template>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label for="provincia_list" class="form-label small fw-semibold text-secondary mb-1">
                        Provincia
                    </label>
                    <select id="provincia_list" class="form-select form-select-sm" v-model="ubigeo_provincia"
                        :disabled="!ubigeo_region">
                        <option value="">— Seleccionar —</option>
                        <template v-for="(PROVINCIA, index) in PROVINCIA_SELECTS" :key="index">
                            <option :value="PROVINCIA.id">{{ PROVINCIA.name }}</option>
                        </template>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label for="distrito_list" class="form-label small fw-semibold text-secondary mb-1">
                        Distrito
                    </label>
                    <select id="distrito_list" class="form-select form-select-sm" v-model="ubigeo_distrito"
                        :disabled="!ubigeo_provincia">
                        <option value="">— Seleccionar —</option>
                        <template v-for="(DISTRITO, index) in DISTRITO_SELECTS" :key="index">
                            <option :value="DISTRITO.id">{{ DISTRITO.name }}</option>
                        </template>
                    </select>
                </div>
                <div class="col-12">
                    <hr class="my-1" />
                    <small class="text-muted fw-semibold">
                        <i class="fas fa-map me-1"></i>Ubicación— <span :class="es_amazonia ? 'text-success' : 'text-muted'">{{ es_amazonia ? 'Zona Amazonía detectada automáticamente (Ley 27037)' : 'Zona fuera deAmazonía' }}
                        </span>
                    </small>
                </div>

                <!-- ── BOTONES ── -->
                <div class="col-12 mt-2">
                    <div class="d-flex justify-content-end gap-2">
                        <b-button type="button" variant="outline-secondary" @click="ModalRegisterClient = false">
                            Cancelar
                        </b-button>
                        <b-button type="button" :variant="themeColor" @click="store">
                            <i class="fas me-1" :class="client_selected ? 'fa-save' : 'fa-user-plus'"></i>
                            {{ client_selected ? 'Guardar Cambios' : 'Registrar Cliente' }}
                        </b-button>
                    </div>
                </div>

            </div>
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
import REGIONES from './json/regiones.json';
import PROVINCIAS from './json/provincias.json';
import DISTRITOS from './json/distritos.json';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

// ── UI ───────────────────────────────────────────────────────────────
const ModalRegisterClient = ref<boolean>(false);
const search = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);

const themeColor = ref<string>('primary');
const isSearching = ref<boolean>(false);

const clients = ref<Client[]>([]);

// ── Campos del formulario ────────────────────────────────────────────
const type_client = ref<string>("1");
const type_document = ref<string>("DNI");
const n_document = ref<string>("");
const name = ref<string>("");
const surname = ref<string>("");
const full_name = ref<string>("");
const name_comerc = ref<string>("");
const email = ref<string>("");
const phone = ref<string>("");
const birth_date = ref<string>("");
const gender = ref<string>("M");
const state = ref<number>(1);
const address = ref<string>("");

// ── Campos nuevos SUNAT ──────────────────────────────────────────────
const es_amazonia = ref<boolean>(false);
const regimen_tributario = ref('SIN REGIMEN');
const es_agente_retencion = ref(false);
const condicion_domicilio = ref('HABIDO');

// Código Catálogo 06 SUNAT — se deriva automáticamente de type_document
// No es un ref editable, es computed para que siempre sea consistente
const cod_tipo_doc_sunat = computed<string>(() => {
    switch (type_document.value) {
        case 'DNI': return '1';
        case 'RUC': return '6';
        case 'CE': return '4';
        case 'PAS': return '7';
        case 'TM': return 'A';
        case 'SND': return '0';
        default: return '1';
    }
});

// ── Ubigeo ───────────────────────────────────────────────────────────
const ubigeo_region = ref<string>("");
const ubigeo_provincia = ref<string>("");
const ubigeo_distrito = ref<string>("");
const region = ref<string>("");
const provincia = ref<string>("");
const distrito = ref<string>("");

// Tipamos REGIONES para que TypeScript reconozca es_amazonia del JSON
const REGIONES_L = REGIONES as Array<{ id: string; name: string; es_amazonia?: boolean }>;
const PROVINCIAS_L = PROVINCIAS;
const DISTRITOS_L = DISTRITOS;

const PROVINCIA_SELECTS = ref<UbigeoClient[]>([]);
const DISTRITO_SELECTS = ref<UbigeoClient[]>([]);

const client_selected = ref<Client | undefined>(undefined);

// ── Detectar zona Amazonía al cambiar región ─────────────────────────
// Lee el flag es_amazonia directamente del regiones.json — sin arrays hardcodeados.
// Si mañana SUNAT agrega o quita una región del beneficio, solo actualizas el JSON.
watch(ubigeo_region, (regionId) => {
    // Cargar provincias del departamento seleccionado
    PROVINCIA_SELECTS.value = PROVINCIAS_L
        .filter(p => p.department_id === regionId)
        .map(p => ({
            id: p.id,
            name: p.name,
            department_id: p.department_id,
            province_id: p.id,
            region: REGIONES_L.find(r => r.id === p.department_id)?.name || "",
        }));
    ubigeo_provincia.value = "";
    ubigeo_distrito.value = "";
    DISTRITO_SELECTS.value = [];

    // Leer es_amazonia directo del JSON — la fuente de verdad es el archivo
    const regionData = REGIONES_L.find(r => r.id === regionId);
    es_amazonia.value = regionData?.es_amazonia ?? false;
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
    // es_amazonia ya fue determinado en watch(ubigeo_region) — no se recalcula aquí
});

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

// ── Búsqueda API DNI / RUC ───────────────────────────────────────────
const normalizeText = (text: string): string =>
    text.toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();

const searchDocument = async () => {
    if (!n_document.value?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'Ingrese un número de documento', 'error');
        return;
    }
    if (!['DNI', 'RUC'].includes(type_document.value)) {
        (Swal as TVueSwalInstance).fire('Info',
            'La búsqueda online solo está disponible para DNI y RUC', 'info');
        return;
    }

    isSearching.value = true;
    try {
        const endpoint = type_document.value.toLowerCase();
        const response: AxiosResponse = await httpClient.get(
            `/search-document/${endpoint}/${n_document.value}`
        );

        if (response.data.success === false) {
            (Swal as TVueSwalInstance).fire('Error',
                response.data.message || 'No se encontró información', 'error');
            return;
        }

        if (type_document.value === 'DNI') {
            name.value = response.data.nombres || '';
            surname.value = `${response.data.apellidoPaterno || ''} ${response.data.apellidoMaterno || ''}`.trim();
        } else {
            full_name.value = response.data.razonSocial || '';
            name_comerc.value = response.data.nombreComercial || '';
            if (response.data.direccion) address.value = response.data.direccion;
            if (response.data.telefonos?.length) phone.value = response.data.telefonos[0];
            if (response.data.departamento) {
                assignUbigeoFromAPI(
                    response.data.departamento,
                    response.data.provincia,
                    response.data.distrito
                );
            }
        }

        (Swal as TVueSwalInstance).fire('Éxito', 'Datos cargados correctamente', 'success');
    } catch (error: any) {
        let msg = 'Error al consultar el documento';
        if (error.response?.status === 404) msg = 'Documento no encontrado';
        else if (error.response?.status === 401) msg = 'Token de API inválido o expirado';
        else if (error.response?.data?.message) msg = error.response.data.message;
        else if (error.message) msg = error.message;
        (Swal as TVueSwalInstance).fire('Error', msg, 'error');
    } finally {
        isSearching.value = false;
    }
};

const assignUbigeoFromAPI = (departamento: string, provincia: string, distrito: string) => {
    const depN = normalizeText(departamento);
    const provN = normalizeText(provincia);
    const distN = normalizeText(distrito);

    const foundRegion = REGIONES_L.find(r =>
        normalizeText(r.name).includes(depN) || depN.includes(normalizeText(r.name))
    );
    if (!foundRegion) return;

    ubigeo_region.value = foundRegion.id;
    // Detectar Amazonía desde el JSON al cargar desde API
    es_amazonia.value = foundRegion.es_amazonia ?? false;

    const foundProv = PROVINCIAS_L.find(p =>
        p.department_id === foundRegion.id &&
        (normalizeText(p.name).includes(provN) || provN.includes(normalizeText(p.name)))
    );
    if (!foundProv) return;

    nextTick(() => {
        ubigeo_provincia.value = foundProv.id;

        const foundDist = DISTRITOS_L.find(d =>
            d.province_id === foundProv.id &&
            (normalizeText(d.name).includes(distN) || distN.includes(normalizeText(d.name)))
        );
        if (foundDist) {
            nextTick(() => { ubigeo_distrito.value = foundDist.id; });
        }
    });
};

// ── Cliente sin datos (boleta < S/ 700) ─────────────────────────────
// No abre modal — crea directamente el cliente genérico y emite el evento
// para que el componente padre (o el router) lo use en la venta.
// Si este componente es standalone (no embedded), puedes guardarlo en BD
// o simplemente mantenerlo en memoria para la sesión.
const setClienteSinDatos = () => {
    const clienteSinDatos: Partial<Client> = {
        id: 0,              // temporal, sin persistir
        full_name: 'CLIENTES VARIOS',
        type_document: 'SND',
        n_document: '00000000',
        type_client: '1',
        cod_tipo_doc_sunat: '0',            // Catálogo 06 SUNAT: sin doc.
        es_amazonia: false,
        state: 1,
    };

    // Si el componente de venta escucha este evento, lo recibe listo:
    // emit('clienteSeleccionado', clienteSinDatos);
    //
    // Si usas una store (Pinia / Vuex), guárdalo ahí:
    // clienteStore.setCliente(clienteSinDatos);
    //
    // Por ahora muestra confirmación:
    (Swal as TVueSwalInstance).fire({
        icon: 'info',
        title: 'Cliente: CLIENTES VARIOS',
        text: 'Se usará para boletas de consumidor final sin datos. El comprobante no debe superar S/ 700.',
        timer: 2500,
        showConfirmButton: false,
    });
};

// ── CRUD ─────────────────────────────────────────────────────────────
const openModal = async (client?: Client) => {
    client_selected.value = client;

    if (client) {
        type_client.value = client.type_client || "1";
        type_document.value = client.type_document || "DNI";
        n_document.value = client.n_document || "";
        email.value = client.email || "";
        phone.value = client.phone || "";
        birth_date.value = client.birth_date || "";
        gender.value = client.gender || "M";
        state.value = client.state || 1;
        address.value = client.address || "";
        es_amazonia.value = client.es_amazonia ?? false;

        if (client.type_document === 'RUC') {
            full_name.value = client.full_name || "";
            name_comerc.value = client.name_comerc || "";
        } else {
            name.value = client.name || "";
            surname.value = client.surname || "";
        }

        // Ubigeo en cascada
        if (client.ubigeo_region) {
            ubigeo_region.value = client.ubigeo_region;
            await nextTick();
            if (client.ubigeo_provincia) {
                ubigeo_provincia.value = client.ubigeo_provincia;
                await nextTick();
                if (client.ubigeo_distrito) {
                    ubigeo_distrito.value = client.ubigeo_distrito;
                }
            }
        }
    }

    await nextTick();
    ModalRegisterClient.value = true;
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
    es_amazonia.value = false;
    ubigeo_region.value = "";
    ubigeo_provincia.value = "";
    ubigeo_distrito.value = "";
    region.value = "";
    provincia.value = "";
    distrito.value = "";
};

const store = async () => {
    // Validaciones
    if (type_document.value === 'RUC') {
        if (!full_name.value.trim()) {
            (Swal as TVueSwalInstance).fire('Error', 'La razón social es obligatoria.', 'error');
            return;
        }
    } else if (type_document.value !== 'SND') {
        if (!name.value.trim() || !surname.value.trim()) {
            (Swal as TVueSwalInstance).fire('Error', 'El nombre y apellido son obligatorios.', 'error');
            return;
        }
        if (!n_document.value.trim()) {
            (Swal as TVueSwalInstance).fire('Error', 'El número de documento es obligatorio.', 'error');
            return;
        }
    }

    // Resolver nombres de ubigeo para guardar
    const REGION_OBJ = REGIONES_L.find((r: any) => r.id === ubigeo_region.value);
    const PROV_OBJ = PROVINCIAS_L.find((p: any) => p.id === ubigeo_provincia.value);
    const DIST_OBJ = DISTRITOS_L.find((d: any) => d.id === ubigeo_distrito.value);
    if (REGION_OBJ) region.value = REGION_OBJ.name;
    if (PROV_OBJ) provincia.value = PROV_OBJ.name;
    if (DIST_OBJ) distrito.value = DIST_OBJ.name;

    // Payload
    let data: any = {
        type_client: type_client.value,
        type_document: type_document.value,
        n_document: n_document.value,
        // ── Campos nuevos SUNAT ──────────────────────────
        cod_tipo_doc_sunat: cod_tipo_doc_sunat.value,   // computed, siempre correcto
        es_amazonia: es_amazonia.value,
        // ── Resto ────────────────────────────────────────
        email: email.value,
        phone: phone.value,
        state: state.value,
        address: address.value,
        ubigeo_region: ubigeo_region.value,
        ubigeo_provincia: ubigeo_provincia.value,
        ubigeo_distrito: ubigeo_distrito.value,
        region: region.value,
        provincia: provincia.value,
        distrito: distrito.value,
    };

    if (type_document.value === 'RUC') {
        data.full_name = full_name.value;
        data.name_comerc = name_comerc.value;
    } else {
        data.name = name.value;
        data.surname = surname.value;
        data.full_name = type_document.value === 'SND'
            ? 'CLIENTES VARIOS'
            : `${name.value} ${surname.value}`.trim();
        data.birth_date = birth_date.value;
        data.gender = gender.value;
    }

    try {
        const res: AxiosResponse<ClientResponse> = !client_selected.value
            ? await httpClient.post("clients", data)
            : await httpClient.put("clients/" + client_selected.value?.id, data);

        if (res.data.code == 405) {
            (Swal as TVueSwalInstance).fire("¡Atención!", res.data.message, "warning");
        } else {
            ModalRegisterClient.value = false;
            if (!client_selected.value) {
                if (res.data.client) clients.value.unshift(res.data.client);
            } else {
                const idx = clients.value.findIndex(c => c.id == client_selected.value?.id);
                if (idx !== -1 && res.data.client) clients.value[idx] = res.data.client;
            }
            (Swal as TVueSwalInstance).fire("¡Éxito!", res.data.message, "success");
            reset();
        }
    } catch (error: any) {
        const msg = error.response?.data?.message || 'Error inesperado al guardar';
        (Swal as TVueSwalInstance).fire('Error', msg, 'error');
    }
};

const editClient = (client: Client) => openModal(client);

const removeClient = (client: Client) => {
    (Swal as TVueSwalInstance).fire({
        title: "¿Eliminar cliente?",
        text: `Se eliminará a "${client.full_name}". Esta acción no se puede deshacer.`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#dc2626",
        cancelButtonColor: "#6b7280",
        confirmButtonText: "Sí, eliminar",
        cancelButtonText: "Cancelar",
    }).then(async (result: any) => {
        if (result.isConfirmed) {
            await httpClient.delete("clients/" + client.id);
            const idx = clients.value.findIndex(c => c.id == client.id);
            if (idx !== -1) clients.value.splice(idx, 1);
            (Swal as TVueSwalInstance).fire("Eliminado", `"${client.full_name}" fue eliminado.`, "success");
        }
    });
};

const list = async () => {
    try {
        const res: AxiosResponse<Clients> = await httpClient.get(
            `clients?page=${currentPage.value}&search=${search.value ?? ''}`
        );
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

watch(ModalRegisterClient, (value) => {
    if (!value) {
        client_selected.value = undefined;
        clearFields();
    }
});

watch(currentPage, () => list());

onMounted(() => list());
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
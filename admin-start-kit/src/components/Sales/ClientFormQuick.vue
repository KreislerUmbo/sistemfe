<template>
  <b-form @submit.prevent="save">
    <div class="row g-3">
      <!-- Tipo documento -->
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Tipo Documento</label>
        <b-form-select v-model="form.type_document" size="sm">
          <option value="DNI">DNI</option>
          <option value="RUC">RUC</option>
          <option value="CE">Carnet de Extranjería</option>
          <option value="PAS">Pasaporte</option>
        </b-form-select>
      </div>
      <div class="col-md-5">
        <label class="form-label small fw-semibold">N° Documento</label>
        <b-input-group size="sm">
          <b-form-input v-model="form.n_document" />
          <b-button variant="primary" @click="searchAPI" :disabled="!form.n_document">
            <i class="fas fa-search"></i> Buscar
          </b-button>
        </b-input-group>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Tipo</label>
        <b-form-select v-model="form.type_client" size="sm">
          <option value="1">Final</option>
          <option value="2">Empresa</option>
        </b-form-select>
      </div>

      <!-- Nombre / Razón Social -->
      <div class="col-md-6" v-if="form.type_document === 'RUC'">
        <label class="form-label small fw-semibold">Razón Social</label>
        <b-form-input v-model="form.full_name" size="sm" />
      </div>
      <template v-else>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Nombres</label>
          <b-form-input v-model="form.name" size="sm" />
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Apellidos</label>
          <b-form-input v-model="form.surname" size="sm" />
        </div>
      </template>

      <!-- Contacto -->
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Teléfono</label>
        <b-form-input v-model="form.phone" size="sm" />
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Email</label>
        <b-form-input v-model="form.email" size="sm" />
      </div>

      <!-- Dirección -->
      <div class="col-md-8">
        <label class="form-label small fw-semibold">Dirección</label>
        <b-form-input v-model="form.address" size="sm" />
      </div>

      <!-- Ubigeo -->
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Región</label>
        <b-form-select v-model="form.ubigeo_region" @change="loadProvinces" size="sm">
          <option value="">Seleccionar</option>
          <option v-for="r in regiones" :key="r.id" :value="r.id">{{ r.name }}</option>
        </b-form-select>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Provincia</label>
        <b-form-select v-model="form.ubigeo_provincia" @change="loadDistricts" size="sm">
          <option value="">Seleccionar</option>
          <option v-for="p in provincias" :key="p.id" :value="p.id">{{ p.name }}</option>
        </b-form-select>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Distrito</label>
        <b-form-select v-model="form.ubigeo_distrito" size="sm">
          <option value="">Seleccionar</option>
          <option v-for="d in distritos" :key="d.id" :value="d.id">{{ d.name }}</option>
        </b-form-select>
      </div>
    </div>

    <div class="mt-3 d-flex justify-content-end gap-2">
      <b-button variant="secondary" @click="$emit('cancel')">Cancelar</b-button>
      <b-button variant="primary" type="submit">Guardar y Seleccionar</b-button>
    </div>
  </b-form>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import httpClient from '@/helpers/http-client';
import Swal from 'sweetalert2';
import REGIONES from '../../views/clients/json/regiones.json';
import PROVINCIAS from '../../views/clients/json/provincias.json';
import DISTRITOS from '../../views/clients/json/distritos.json';

const props = defineProps<{ initialData: any }>();
const emit = defineEmits(['saved', 'cancel']);

const form = ref({
  type_document: 'DNI',
  n_document: '',
  name: '',
  surname: '',
  full_name: '',
  name_comerc: '',
  email: '',
  phone: '',
  address: '',
  ubigeo_region: '',
  ubigeo_provincia: '',
  ubigeo_distrito: '',
  type_client: '1',
  state: 1,
});

const regiones = REGIONES;
const provincias = ref<any[]>([]);
const distritos = ref<any[]>([]);

// Cargar datos iniciales (cuando viene de API o DNI/RUC manual)
watch(() => props.initialData, (data) => {
  if (data) {
    form.value.type_document = data.type_document || 'DNI';
    form.value.n_document = data.n_document || '';
    form.value.name = data.name || '';
    form.value.surname = data.surname || '';
    form.value.full_name = data.full_name || '';
    form.value.address = data.address || '';
    if (data.type_client) form.value.type_client = data.type_client;
  }
}, { immediate: true });

const searchAPI = async () => {
  if (!form.value.n_document) return;
  const type = form.value.type_document.toLowerCase();
  try {
    const res = await httpClient.get(`/search-document/${type}/${form.value.n_document}`);
    if (res.data.success === false) {
      Swal.fire('Error', 'No se encontró información', 'error');
      return;
    }
    const data = res.data;
    if (type === 'dni') {
      form.value.name = data.nombres || '';
      form.value.surname = `${data.apellidoPaterno || ''} ${data.apellidoMaterno || ''}`.trim();
      form.value.full_name = `${form.value.name} ${form.value.surname}`;
    } else {
      form.value.full_name = data.razonSocial || '';
      form.value.name_comerc = data.nombreComercial || '';
      form.value.address = data.direccion || '';
      // Asignar ubigeo si viene (simplificado)
      if (data.departamento) {
        const region = regiones.find(r => r.name.toLowerCase().includes(data.departamento.toLowerCase()));
        if (region) {
          form.value.ubigeo_region = region.id;
          // Cargar provincias y distritos (se puede mejorar)
        }
      }
    }
  } catch (error) {
    console.error(error);
    Swal.fire('Error', 'No se pudo consultar la API', 'error');
  }
};

const save = async () => {
  try {
    if (!form.value.n_document && form.value.type_document !== 'SND') {
      Swal.fire('Error', 'El número de documento es obligatorio', 'error');
      return;
    }
    const payload: any = {
      type_client: form.value.type_client,
      type_document: form.value.type_document,
      n_document: form.value.n_document,
      cod_tipo_doc_sunat: getCodTipoDoc(form.value.type_document),
      es_amazonia: false,
      email: form.value.email,
      phone: form.value.phone,
      state: 1,
      address: form.value.address,
      ubigeo_region: form.value.ubigeo_region,
      ubigeo_provincia: form.value.ubigeo_provincia,
      ubigeo_distrito: form.value.ubigeo_distrito,
    };
    if (form.value.type_document === 'RUC') {
      payload.full_name = form.value.full_name;
      payload.name_comerc = form.value.name_comerc;
    } else {
      payload.name = form.value.name;
      payload.surname = form.value.surname;
      payload.full_name = `${form.value.name} ${form.value.surname}`.trim();
    }
    const res = await httpClient.post('clients', payload);
    if (res.data.code === 200) {
      emit('saved', res.data.client);
    } else {
      Swal.fire('Error', res.data.message, 'error');
    }
  } catch (error: any) {
    Swal.fire('Error', error.response?.data?.message || 'Error al guardar', 'error');
  }
};

const getCodTipoDoc = (type: string): string => {
  const map: Record<string, string> = { DNI: '1', RUC: '6', CE: '4', PAS: '7' };
  return map[type] || '1';
};

const loadProvinces = () => {
  provincias.value = PROVINCIAS.filter((p: any) => p.department_id === form.value.ubigeo_region);
  form.value.ubigeo_provincia = '';
  form.value.ubigeo_distrito = '';
  distritos.value = [];
};

const loadDistricts = () => {
  distritos.value = DISTRITOS.filter((d: any) => d.province_id === form.value.ubigeo_provincia);
  form.value.ubigeo_distrito = '';
};
</script>
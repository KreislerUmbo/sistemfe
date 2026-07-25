<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import httpClient from '@/services/httpClient';
import type { Company, CompanyPayload } from '@/types/tenant-detail';

const props = defineProps<{ tenantId: string }>();

const company = ref<Company | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const saving = ref(false);
const saveError = ref<string | null>(null);
const saved = ref(false);

// Todos como string vacío en el form (nunca null) — más simple para v-model. Se
// convierten de vuelta a null al armar el payload (ver onSubmit): 'nullable|date' en
// el backend (birth_date) rechaza '' como fecha inválida, así que un opcional vacío
// tiene que viajar como null, no como string vacío.
const form = reactive({
  razon_social: '',
  razon_social_comercial: '',
  n_document: '',
  urbanizacion: '',
  cod_local: '',
  phone: '',
  email: '',
  birth_date: '',
  address: '',
  ubigeo_distrito: '',
  ubigeo_provincia: '',
  ubigeo_region: '',
  distrito: '',
  provincia: '',
  region: '',
});

function fillForm(c: Company | null) {
  form.razon_social = c?.razon_social ?? '';
  form.razon_social_comercial = c?.razon_social_comercial ?? '';
  form.n_document = c?.n_document ?? '';
  form.urbanizacion = c?.urbanizacion ?? '';
  form.cod_local = c?.cod_local ?? '';
  form.phone = c?.phone ?? '';
  form.email = c?.email ?? '';
  form.birth_date = c?.birth_date ?? '';
  form.address = c?.address ?? '';
  form.ubigeo_distrito = c?.ubigeo_distrito ?? '';
  form.ubigeo_provincia = c?.ubigeo_provincia ?? '';
  form.ubigeo_region = c?.ubigeo_region ?? '';
  form.distrito = c?.distrito ?? '';
  form.provincia = c?.provincia ?? '';
  form.region = c?.region ?? '';
}

async function fetchCompany() {
  loading.value = true;
  error.value = null;

  try {
    const { data } = await httpClient.get(`central/tenants/${props.tenantId}/company`);
    company.value = data.company;
    fillForm(company.value);
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar Company.';
  } finally {
    loading.value = false;
  }
}

async function onSubmit() {
  saving.value = true;
  saveError.value = null;
  saved.value = false;

  const payload: CompanyPayload = {
    razon_social: form.razon_social,
    razon_social_comercial: form.razon_social_comercial,
    n_document: form.n_document,
    urbanizacion: form.urbanizacion,
    cod_local: form.cod_local,
    phone: form.phone || null,
    email: form.email || null,
    birth_date: form.birth_date || null,
    address: form.address || null,
    ubigeo_distrito: form.ubigeo_distrito || null,
    ubigeo_provincia: form.ubigeo_provincia || null,
    ubigeo_region: form.ubigeo_region || null,
    distrito: form.distrito || null,
    provincia: form.provincia || null,
    region: form.region || null,
  };

  try {
    const { data } = await httpClient.post(`central/tenants/${props.tenantId}/company`, payload);
    company.value = data.company;
    saved.value = true;
  } catch (e: any) {
    saveError.value = e.response?.data?.message ?? 'No se pudo guardar Company.';
  } finally {
    saving.value = false;
  }
}

onMounted(fetchCompany);
</script>

<template>
  <div>
    <div v-if="loading" class="d-flex align-items-center gap-2 text-muted py-3">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando Company…</span>
    </div>

    <div v-else-if="error" class="alert alert-danger d-flex justify-content-between align-items-center">
      <span>{{ error }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="fetchCompany">
        Reintentar
      </button>
    </div>

    <template v-else>
      <p class="text-muted">
        <span v-if="company">Editando los datos de Company existentes.</span>
        <span v-else>Este tenant no tiene Company todavía — completá el formulario para crearla.</span>
      </p>

      <form class="row g-3" style="max-width: 720px" @submit.prevent="onSubmit">
        <div class="col-md-6">
          <label class="form-label">Razón social *</label>
          <input v-model="form.razon_social" type="text" class="form-control" required maxlength="250" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Nombre comercial *</label>
          <input
            v-model="form.razon_social_comercial"
            type="text"
            class="form-control"
            required
            maxlength="250"
          />
        </div>
        <div class="col-md-6">
          <label class="form-label">RUC / documento *</label>
          <input v-model="form.n_document" type="text" class="form-control" required maxlength="50" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Urbanización *</label>
          <input v-model="form.urbanizacion" type="text" class="form-control" required maxlength="200" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Código de local (SUNAT) *</label>
          <input v-model="form.cod_local" type="text" class="form-control" required maxlength="100" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Teléfono</label>
          <input v-model="form.phone" type="text" class="form-control" maxlength="50" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input v-model="form.email" type="email" class="form-control" maxlength="50" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Fecha de nacimiento (repr. legal)</label>
          <input v-model="form.birth_date" type="date" class="form-control" />
        </div>
        <div class="col-12">
          <label class="form-label">Dirección</label>
          <input v-model="form.address" type="text" class="form-control" maxlength="200" />
        </div>
        <div class="col-md-4">
          <label class="form-label">Ubigeo distrito</label>
          <input v-model="form.ubigeo_distrito" type="text" class="form-control" maxlength="25" />
        </div>
        <div class="col-md-4">
          <label class="form-label">Ubigeo provincia</label>
          <input v-model="form.ubigeo_provincia" type="text" class="form-control" maxlength="25" />
        </div>
        <div class="col-md-4">
          <label class="form-label">Ubigeo región</label>
          <input v-model="form.ubigeo_region" type="text" class="form-control" maxlength="25" />
        </div>
        <div class="col-md-4">
          <label class="form-label">Distrito</label>
          <input v-model="form.distrito" type="text" class="form-control" maxlength="80" />
        </div>
        <div class="col-md-4">
          <label class="form-label">Provincia</label>
          <input v-model="form.provincia" type="text" class="form-control" maxlength="80" />
        </div>
        <div class="col-md-4">
          <label class="form-label">Región</label>
          <input v-model="form.region" type="text" class="form-control" maxlength="80" />
        </div>

        <div class="col-12">
          <div v-if="saveError" class="alert alert-danger py-2">{{ saveError }}</div>
          <div v-if="saved" class="alert alert-success py-2">Company guardada correctamente.</div>
          <button type="submit" class="btn btn-primary" :disabled="saving">
            {{ saving ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </form>
    </template>
  </div>
</template>

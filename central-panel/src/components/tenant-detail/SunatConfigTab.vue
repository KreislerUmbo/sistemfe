<script setup lang="ts">
import { onMounted, reactive, watch } from 'vue';
import { useTenantsStore } from '@/stores/tenants';
import type { SunatConfig, SunatConfigPayload } from '@/types/tenant-detail';

const props = defineProps<{ tenantId: string }>();
const store = useTenantsStore();

const form = reactive({
  ruc: '',
  razon_social_sunat: '',
  modo: 'beta' as 'beta' | 'produccion',
  sol_usuario: '',
  // sol_clave SIEMPRE arranca vacío — decisión ya tomada, el backend tampoco lo
  // devuelve nunca en el GET (por seguridad). Hay que reescribirlo para guardar.
  sol_clave: '',
  cuenta_bancaria_detraccion: '',
});

function fillForm(c: SunatConfig | null) {
  form.ruc = c?.ruc ?? '';
  form.razon_social_sunat = c?.razon_social_sunat ?? '';
  form.modo = c?.modo ?? 'beta';
  form.sol_usuario = c?.sol_usuario ?? '';
  form.sol_clave = '';
  form.cuenta_bancaria_detraccion = c?.cuenta_bancaria_detraccion ?? '';
}

onMounted(async () => {
  await store.fetchSunatConfig(props.tenantId);
  fillForm(store.sunatConfig.data);
});

// Si otro tab (Certificado) actualiza sunatConfig.data (mismo slice del store,
// compartido a propósito), refrescar el form salvo sol_clave (nunca se pisa sola).
watch(
  () => store.sunatConfig.data,
  (data) => {
    const currentClave = form.sol_clave;
    fillForm(data);
    form.sol_clave = currentClave;
  },
);

async function onSubmit() {
  const payload: SunatConfigPayload = {
    ruc: form.ruc || null,
    razon_social_sunat: form.razon_social_sunat || null,
    modo: form.modo,
    sol_usuario: form.sol_usuario,
    sol_clave: form.sol_clave,
    cuenta_bancaria_detraccion: form.cuenta_bancaria_detraccion || null,
  };

  const ok = await store.saveSunatConfig(props.tenantId, payload);
  if (ok) form.sol_clave = '';
}
</script>

<template>
  <div>
    <div v-if="store.sunatConfig.loading" class="d-flex align-items-center gap-2 text-muted py-3">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando configuración SUNAT…</span>
    </div>

    <div
      v-else-if="store.sunatConfig.error"
      class="alert alert-danger d-flex justify-content-between align-items-center"
    >
      <span>{{ store.sunatConfig.error }}</span>
      <button
        type="button"
        class="btn btn-sm btn-outline-danger"
        @click="store.fetchSunatConfig(tenantId)"
      >
        Reintentar
      </button>
    </div>

    <template v-else>
      <p class="text-muted">
        <span v-if="store.sunatConfig.data">Editando la configuración SUNAT existente.</span>
        <span v-else>
          Este tenant no tiene configuración SUNAT todavía — completá el formulario para
          crearla. Requiere que Company ya exista (tab Company).
        </span>
      </p>

      <form class="row g-3" style="max-width: 640px" @submit.prevent="onSubmit">
        <div class="col-md-6">
          <label class="form-label">RUC</label>
          <input v-model="form.ruc" type="text" class="form-control" maxlength="20" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Razón social SUNAT</label>
          <input v-model="form.razon_social_sunat" type="text" class="form-control" maxlength="250" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Modo *</label>
          <select v-model="form.modo" class="form-select" required>
            <option value="beta">Beta (pruebas)</option>
            <option value="produccion">Producción</option>
          </select>
          <div v-if="form.modo === 'produccion'" class="form-text text-warning">
            Exige certificado propio ya cargado y vigente (tab Certificado) — si no, el
            backend rechaza el guardado.
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Cuenta bancaria detracción</label>
          <input v-model="form.cuenta_bancaria_detraccion" type="text" class="form-control" maxlength="50" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Usuario SOL *</label>
          <input v-model="form.sol_usuario" type="text" class="form-control" required />
        </div>
        <div class="col-md-6">
          <label class="form-label">Clave SOL *</label>
          <input
            v-model="form.sol_clave"
            type="password"
            class="form-control"
            required
            autocomplete="new-password"
            placeholder="Reescribir para guardar — nunca se precarga"
          />
        </div>

        <div class="col-12">
          <div v-if="store.sunatConfig.saveError" class="alert alert-danger py-2">
            {{ store.sunatConfig.saveError }}
          </div>
          <button type="submit" class="btn btn-primary" :disabled="store.sunatConfig.saving">
            {{ store.sunatConfig.saving ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </form>

      <div v-if="store.sunatConfig.data" class="mt-4 border-top pt-3">
        <h3 class="h6">Estado del certificado</h3>
        <p class="mb-0">
          <span v-if="store.sunatConfig.data.certificado_cargado" class="text-success">
            Certificado propio cargado
          </span>
          <span v-else class="text-muted">Sin certificado propio (usando el demo en beta)</span>
          — válido: {{ store.sunatConfig.data.certificado_valido ? 'sí' : 'no' }}
          <span v-if="store.sunatConfig.data.certificado_fecha_vencimiento">
            — vence {{ store.sunatConfig.data.certificado_fecha_vencimiento }}
          </span>
        </p>
      </div>
    </template>
  </div>
</template>

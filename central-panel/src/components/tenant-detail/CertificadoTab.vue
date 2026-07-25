<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useTenantsStore } from '@/stores/tenants';

const props = defineProps<{ tenantId: string }>();
const store = useTenantsStore();

// Mismo slice que el tab SunatConfig (store.sunatConfig) — se refresca acá también al
// montar, porque el usuario puede entrar directo a este tab sin haber pasado antes por
// SunatConfig (TenantDetailView solo monta el tab activo).
onMounted(() => {
  store.fetchSunatConfig(props.tenantId);
});

const file = ref<File | null>(null);
const password = ref('');
const uploaded = ref(false);

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement;
  file.value = input.files?.[0] ?? null;
  uploaded.value = false;
}

async function onSubmit() {
  if (!file.value || !password.value) return;
  uploaded.value = false;
  const ok = await store.uploadCertificado(props.tenantId, file.value, password.value);
  if (ok) {
    uploaded.value = true;
    file.value = null;
    password.value = '';
  }
}
</script>

<template>
  <div>
    <div v-if="store.sunatConfig.loading" class="d-flex align-items-center gap-2 text-muted py-3">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando estado del certificado…</span>
    </div>

    <template v-else>
      <div v-if="!store.sunatConfig.data" class="alert alert-light border text-muted">
        Este tenant no tiene configuración SUNAT todavía — completá primero el tab
        SunatConfig antes de subir un certificado.
      </div>

      <template v-else>
        <div class="mb-3">
          <span v-if="store.sunatConfig.data.certificado_cargado" class="text-success">
            Certificado propio cargado
          </span>
          <span v-else class="text-muted">Sin certificado propio (modo beta usa el demo)</span>
          — válido: {{ store.sunatConfig.data.certificado_valido ? 'sí' : 'no' }}
          <span v-if="store.sunatConfig.data.certificado_fecha_vencimiento">
            — vence {{ store.sunatConfig.data.certificado_fecha_vencimiento }}
          </span>
        </div>

        <form class="row g-3" style="max-width: 480px" @submit.prevent="onSubmit">
          <div class="col-12">
            <label class="form-label">Certificado (.pfx, máx. 5MB) *</label>
            <input type="file" accept=".pfx" class="form-control" required @change="onFileChange" />
          </div>
          <div class="col-12">
            <label class="form-label">Contraseña del certificado *</label>
            <input v-model="password" type="password" class="form-control" required autocomplete="new-password" />
          </div>
          <div class="col-12">
            <div v-if="store.certificado.error" class="alert alert-danger py-2">
              {{ store.certificado.error }}
            </div>
            <div v-if="uploaded" class="alert alert-success py-2">Certificado subido correctamente.</div>
            <button type="submit" class="btn btn-primary" :disabled="store.certificado.uploading || !file || !password">
              {{ store.certificado.uploading ? 'Subiendo…' : 'Subir certificado' }}
            </button>
          </div>
        </form>
      </template>
    </template>
  </div>
</template>

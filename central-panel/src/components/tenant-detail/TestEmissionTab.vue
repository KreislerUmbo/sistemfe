<script setup lang="ts">
import { useTenantsStore } from '@/stores/tenants';

const props = defineProps<{ tenantId: string }>();
const store = useTenantsStore();
</script>

<template>
  <div>
    <p class="text-muted">
      Confirma que Company, la configuración SUNAT y el certificado están en condiciones de
      emitir — sin quemar ningún correlativo real ni enviar nada a SUNAT.
    </p>

    <button
      type="button"
      class="btn btn-primary"
      :disabled="store.testEmission.loading"
      @click="store.runTestEmission(tenantId)"
    >
      {{ store.testEmission.loading ? 'Probando…' : 'Probar emisión' }}
    </button>

    <div v-if="store.testEmission.error" class="alert alert-danger mt-3">
      {{ store.testEmission.error }}
    </div>

    <div v-else-if="store.testEmission.result" class="card mt-3" style="max-width: 480px">
      <div class="card-body">
        <h3 class="h6">Listo para emitir ✓</h3>
        <ul class="list-unstyled mb-0 small">
          <li>
            <strong>Company:</strong> {{ store.testEmission.result.company.razon_social }} ({{
              store.testEmission.result.company.n_document
            }})
          </li>
          <li><strong>Modo:</strong> {{ store.testEmission.result.modo }}</li>
          <li>
            <strong>Certificado:</strong>
            {{ store.testEmission.result.certificado.propio_o_demo }}
            <span v-if="store.testEmission.result.certificado.valido !== null">
              — válido: {{ store.testEmission.result.certificado.valido ? 'sí' : 'no' }}
            </span>
            <span v-if="store.testEmission.result.certificado.fecha_vencimiento">
              — vence {{ store.testEmission.result.certificado.fecha_vencimiento }}
            </span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

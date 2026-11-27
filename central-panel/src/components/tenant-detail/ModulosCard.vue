<script setup lang="ts">
import { onMounted, ref } from 'vue';
import httpClient from '@/services/httpClient';
import type { TenantModulo } from '@/types/tenant-detail';

// T-135 — módulos activables por tenant: un interruptor por módulo, con auditoría en el backend.
const props = defineProps<{ tenantId: string }>();

const modulos = ref<TenantModulo[]>([]);
const cargando = ref(false);
const guardando = ref<string | null>(null);
const error = ref<string | null>(null);

async function cargar() {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await httpClient.get(`central/tenants/${props.tenantId}/modulos`);
    modulos.value = data.modulos;
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'No se pudieron cargar los módulos.';
  } finally {
    cargando.value = false;
  }
}

async function cambiar(modulo: TenantModulo, event: Event) {
  const input = event.target as HTMLInputElement;
  const habilitado = input.checked;
  guardando.value = modulo.codigo;
  error.value = null;
  try {
    const { data } = await httpClient.put(`central/tenants/${props.tenantId}/modulos/${modulo.codigo}`, {
      habilitado,
    });
    modulos.value = data.modulos;
  } catch (e: any) {
    // No se guardó: el interruptor vuelve a como estaba.
    input.checked = modulo.habilitado;
    error.value = e.response?.data?.message ?? 'No se pudo cambiar el módulo.';
  } finally {
    guardando.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <div class="card mb-3">
    <div class="card-header">
      <h2 class="h6 mb-0">Módulos</h2>
    </div>
    <div class="card-body">
      <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
      <div v-if="cargando" class="text-muted small">Cargando módulos…</div>
      <p v-else-if="!modulos.length && !error" class="text-muted small mb-0">
        No hay módulos activables para este giro.
      </p>
      <div v-for="modulo in modulos" :key="modulo.codigo" class="form-check form-switch">
        <input
          :id="`modulo-${modulo.codigo}`"
          type="checkbox"
          role="switch"
          class="form-check-input"
          :checked="modulo.habilitado"
          :disabled="guardando !== null"
          @change="cambiar(modulo, $event)"
        />
        <label class="form-check-label" :for="`modulo-${modulo.codigo}`">
          <strong>{{ modulo.nombre }}</strong>
          <span v-if="modulo.descripcion" class="text-muted small"> — {{ modulo.descripcion }}</span>
        </label>
      </div>
    </div>
  </div>
</template>

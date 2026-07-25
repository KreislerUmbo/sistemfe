<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useTenantsStore } from '@/stores/tenants';
import type { RestorePreview } from '@/types/tenant-detail';

const props = defineProps<{ tenantId: string }>();
const store = useTenantsStore();

onMounted(() => {
  store.fetchBackups(props.tenantId);
});

function goToPage(page: number) {
  store.fetchBackups(props.tenantId, page);
}

function formatBytes(bytes: number | null): string {
  if (bytes === null) return '—';
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex++;
  }
  return `${value.toFixed(1)} ${units[unitIndex]}`;
}

function estadoBadgeClass(estado: string): string {
  switch (estado) {
    case 'completado':
      return 'bg-success';
    case 'fallido':
      return 'bg-danger';
    default:
      return 'bg-warning text-dark';
  }
}

// Restauración: preview (con confirm_token + expiración de 10 min, backend) → countdown
// visual → confirmar. Fricción intencional heredada del backend (Fase C) — la UI solo
// refleja el estado, no inventa ninguna validación extra.
const activeRestore = ref<RestorePreview | null>(null);
const restoreError = ref<string | null>(null);
const restoreResult = ref<string | null>(null);
const remainingSeconds = ref(0);
let countdownInterval: ReturnType<typeof setInterval> | null = null;

function stopCountdown() {
  if (countdownInterval) {
    clearInterval(countdownInterval);
    countdownInterval = null;
  }
}

function startCountdown(expiresAt: string) {
  const expiresMs = new Date(expiresAt).getTime();
  const tick = () => {
    remainingSeconds.value = Math.max(0, Math.floor((expiresMs - Date.now()) / 1000));
    if (remainingSeconds.value <= 0) stopCountdown();
  };
  tick();
  countdownInterval = setInterval(tick, 1000);
}

onBeforeUnmount(stopCountdown);

async function onPreviewRestore(backupId: number) {
  restoreResult.value = null;
  restoreError.value = null;
  const preview = await store.previewRestore(props.tenantId, backupId);
  if (preview) {
    activeRestore.value = preview;
    startCountdown(preview.restore.confirm_token_expires_at);
  } else {
    restoreError.value = store.backups.actionError;
  }
}

async function onConfirmRestore() {
  if (!activeRestore.value) return;
  const result = await store.confirmRestore(props.tenantId, activeRestore.value.restore.confirm_token);
  if (result) {
    restoreResult.value = `Restauración ${result.estado}.`;
    activeRestore.value = null;
    stopCountdown();
  } else {
    restoreError.value = store.backups.actionError;
  }
}

function cancelRestore() {
  activeRestore.value = null;
  stopCountdown();
}

const countdownLabel = computed(() => {
  const m = Math.floor(remainingSeconds.value / 60);
  const s = remainingSeconds.value % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
});
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h6 mb-0">Backups</h2>
      <button
        type="button"
        class="btn btn-outline-primary btn-sm"
        :disabled="store.backups.actionLoading"
        @click="store.createBackup(tenantId)"
      >
        Crear backup manual
      </button>
    </div>

    <div v-if="restoreResult" class="alert alert-success py-2">{{ restoreResult }}</div>
    <div v-if="restoreError" class="alert alert-danger py-2">{{ restoreError }}</div>

    <!-- Confirmación de restauración: resumen + countdown + botón de doble confirmación -->
    <div v-if="activeRestore" class="alert alert-warning">
      <p class="mb-1">
        <strong>Restaurar backup #{{ activeRestore.backup.id }}</strong>
        ({{ activeRestore.backup.tipo }}, {{ formatBytes(activeRestore.backup.size_bytes) }},
        creado {{ activeRestore.backup.created_at }}).
      </p>
      <p class="mb-2">
        Esto reemplaza los datos actuales del tenant con los de este backup (se crea un
        backup de seguridad automático antes de restaurar). Confirmación expira en
        <strong>{{ countdownLabel }}</strong> — pedí un nuevo preview si se vence.
      </p>
      <div class="d-flex gap-2">
        <button
          type="button"
          class="btn btn-danger btn-sm"
          :disabled="store.backups.actionLoading || remainingSeconds <= 0"
          @click="onConfirmRestore"
        >
          Sí, restaurar
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="cancelRestore">
          Cancelar
        </button>
      </div>
    </div>

    <div v-if="store.backups.loading" class="d-flex align-items-center gap-2 text-muted py-3">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando backups…</span>
    </div>

    <div
      v-else-if="store.backups.error"
      class="alert alert-danger d-flex justify-content-between align-items-center"
    >
      <span>{{ store.backups.error }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="store.fetchBackups(tenantId)">
        Reintentar
      </button>
    </div>

    <div
      v-else-if="!store.backups.page || store.backups.page.data.length === 0"
      class="alert alert-light border text-muted"
    >
      No hay backups todavía.
    </div>

    <div v-else class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Tipo</th>
            <th>Tamaño</th>
            <th>Estado</th>
            <th>Integridad</th>
            <th>Creado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="backup in store.backups.page.data" :key="backup.id">
            <td>{{ backup.id }}</td>
            <td>{{ backup.tipo }}</td>
            <td>{{ formatBytes(backup.size_bytes) }}</td>
            <td>
              <span class="badge" :class="estadoBadgeClass(backup.estado)">{{ backup.estado }}</span>
              <div v-if="backup.error_message" class="text-danger small">{{ backup.error_message }}</div>
            </td>
            <td>
              <span v-if="backup.integridad_verificada" class="text-success">✓ verificada</span>
              <span v-else class="text-muted">sin verificar</span>
            </td>
            <td>{{ backup.created_at }}</td>
            <td>
              <div class="d-flex gap-1">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  :disabled="backup.estado !== 'completado' || store.backups.actionLoading"
                  @click="store.verifyBackup(tenantId, backup.id)"
                >
                  Verificar
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-warning"
                  :disabled="backup.estado !== 'completado' || store.backups.actionLoading"
                  @click="onPreviewRestore(backup.id)"
                >
                  Restaurar
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <nav v-if="store.backups.page.last_page > 1" aria-label="Paginación de backups">
        <ul class="pagination pagination-sm">
          <li
            v-for="page in store.backups.page.last_page"
            :key="page"
            class="page-item"
            :class="{ active: page === store.backups.page.current_page }"
          >
            <button type="button" class="page-link" @click="goToPage(page)">{{ page }}</button>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import httpClient from '@/services/httpClient';
import { useTenantsStore } from '@/stores/tenants';
import { AUDIT_LOG_ACTIONS, AUDITABLE_TYPE_TENANT } from '@/types/audit-log';
import type { AuditLogsPage } from '@/types/audit-log';

// Página global — no vive en stores/tenants.ts (esa vista es para el listado/detalle de
// tenants; esto es una vista aparte que cruza todos los tenants). Fetch propio, mismo
// criterio ya usado para el overview del tenant.
const tenantsStore = useTenantsStore();

const page = ref<AuditLogsPage | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

const filters = reactive({
  tenantId: '',
  action: '',
});

async function fetchLogs(pageNumber = 1) {
  loading.value = true;
  error.value = null;

  const params: Record<string, string | number> = { page: pageNumber };
  if (filters.tenantId) {
    params.auditable_type = AUDITABLE_TYPE_TENANT;
    params.auditable_id = filters.tenantId;
  }
  if (filters.action) {
    params.action = filters.action;
  }

  try {
    const { data } = await httpClient.get('central/audit-logs', { params });
    page.value = data as AuditLogsPage;
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar el registro de auditoría.';
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  tenantsStore.fetchTenants();
  fetchLogs();
});

function onFilterChange() {
  fetchLogs(1);
}

function goToPage(pageNumber: number) {
  fetchLogs(pageNumber);
}

const expandedIds = ref(new Set<number>());

function toggleExpanded(id: number) {
  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id);
  } else {
    expandedIds.value.add(id);
  }
}
</script>

<template>
  <div class="container py-4">
    <h1 class="h4 mb-3">Auditoría</h1>

    <div class="row g-2 mb-3">
      <div class="col-md-4">
        <label class="form-label small">Tenant</label>
        <select v-model="filters.tenantId" class="form-select form-select-sm" @change="onFilterChange">
          <option value="">Todos</option>
          <option v-for="tenant in tenantsStore.tenants" :key="tenant.id" :value="tenant.id">
            {{ tenant.razon_social_comercial || tenant.razon_social }} ({{ tenant.id }})
          </option>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label small">Acción</label>
        <select v-model="filters.action" class="form-select form-select-sm" @change="onFilterChange">
          <option value="">Todas</option>
          <option v-for="action in AUDIT_LOG_ACTIONS" :key="action" :value="action">{{ action }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="d-flex align-items-center gap-2 text-muted py-4">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando…</span>
    </div>

    <div v-else-if="error" class="alert alert-danger d-flex justify-content-between align-items-center">
      <span>{{ error }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="fetchLogs(page?.current_page ?? 1)">
        Reintentar
      </button>
    </div>

    <div v-else-if="!page || page.data.length === 0" class="alert alert-light border text-muted">
      No hay registros de auditoría para este filtro.
    </div>

    <div v-else class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Usuario</th>
            <th>Acción</th>
            <th>Tenant / recurso afectado</th>
            <th>Detalle</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="log in page.data" :key="log.id">
            <tr>
              <td>{{ new Date(log.created_at).toLocaleString('es-PE') }}</td>
              <td>{{ log.central_user?.name ?? 'Sistema' }}</td>
              <td><code class="small">{{ log.action }}</code></td>
              <td>{{ log.auditable_id }}</td>
              <td>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="toggleExpanded(log.id)">
                  {{ expandedIds.has(log.id) ? 'Ocultar' : 'Ver' }}
                </button>
              </td>
            </tr>
            <tr v-if="expandedIds.has(log.id)">
              <td colspan="5">
                <pre class="bg-light p-2 rounded small mb-0">{{ JSON.stringify(log.payload, null, 2) }}</pre>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <nav v-if="page.last_page > 1" aria-label="Paginación de auditoría">
        <ul class="pagination pagination-sm">
          <li
            v-for="p in page.last_page"
            :key="p"
            class="page-item"
            :class="{ active: p === page.current_page }"
          >
            <button type="button" class="page-link" @click="goToPage(p)">{{ p }}</button>
          </li>
        </ul>
      </nav>
      <p class="text-muted small">Total: {{ page.total }}</p>
    </div>
  </div>
</template>

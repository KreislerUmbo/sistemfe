<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useTenantsStore } from '@/stores/tenants';
import type { CreateTenantPayload } from '@/stores/tenants';

const store = useTenantsStore();

onMounted(() => {
  store.fetchTenants();
});

function statusBadgeClass(status: string): string {
  switch (status) {
    case 'activo':
      return 'bg-success';
    case 'suspendido':
      return 'bg-warning text-dark';
    case 'archivado':
      return 'bg-secondary';
    default:
      return 'bg-light text-dark';
  }
}

function formatFecha(fecha: string | null): string {
  if (!fecha) return '—';
  return new Date(fecha).toLocaleDateString('es-PE', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  });
}

// Alta de tenant — panel inline (mismo patrón que suspender/rechazar en
// SubscriptionTab.vue), sin modal de Bootstrap con JS imperativo.
const showCreateForm = ref(false);

const emptyForm: CreateTenantPayload = {
  ruc: '',
  razon_social: '',
  razon_social_comercial: '',
  domain: '',
  admin_name: '',
  admin_email: '',
  admin_password: '',
};

const form = reactive<CreateTenantPayload>({ ...emptyForm });

function resetForm() {
  Object.assign(form, emptyForm);
}

// Conveniencia — el backend exige una contraseña real desde el panel (a diferencia del
// Command CLI, que puede generarla sola). Autogenerar acá es solo para no obligar al
// superadmin a inventar una a mano; puede editarla igual antes de enviar.
function generatePassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  let result = '';
  for (let i = 0; i < 16; i++) {
    result += chars[Math.floor(Math.random() * chars.length)];
  }
  form.admin_password = result;
}

async function onCreateSubmit() {
  const ok = await store.createTenant({ ...form });
  if (ok) {
    showCreateForm.value = false;
    resetForm();
  }
}

function onCancelCreate() {
  showCreateForm.value = false;
  store.createError = null;
  resetForm();
}

// Archivar/Restaurar — "archivado, no borrado" (retención legal SUNAT): bloquea login/
// API, conserva base y storage intactos, reversible en cualquier momento.
async function onArchive(id: string) {
  await store.archiveTenant(id);
}

async function onRestore(id: string) {
  await store.restoreTenant(id);
}

// Eliminar — deliberadamente estrecho (el backend rechaza con 422 si el tenant ya tiene
// datos reales, ver TenantAdminController::destroy()). Confirmación inline por fila (sin
// modal de Bootstrap con JS imperativo, mismo patrón que el resto del panel) — pensado
// solo para "me equivoqué al crearlo".
const confirmingDeleteId = ref<string | null>(null);

function askDelete(id: string) {
  confirmingDeleteId.value = id;
  store.deleteError = null;
}

function cancelDelete() {
  confirmingDeleteId.value = null;
  store.deleteError = null;
}

async function onConfirmDelete(id: string) {
  const ok = await store.deleteTenant(id);
  if (ok) confirmingDeleteId.value = null;
}
</script>

<template>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 mb-0">Tenants</h1>
      <div class="d-flex gap-2">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="store.loading"
          @click="store.fetchTenants(true)"
        >
          Actualizar
        </button>
        <button
          type="button"
          class="btn btn-primary btn-sm"
          @click="showCreateForm = !showCreateForm"
        >
          Nuevo tenant
        </button>
      </div>
    </div>

    <!-- Alta de tenant -->
    <div v-if="showCreateForm" class="card mb-3">
      <div class="card-body">
        <h2 class="h6">Nuevo tenant</h2>
        <p class="text-muted small">
          Crea la base de datos del tenant y corre sus migraciones — puede tardar varios
          segundos, no es instantáneo.
        </p>

        <form class="row g-3" @submit.prevent="onCreateSubmit">
          <div class="col-md-6">
            <label class="form-label">RUC / documento *</label>
            <input v-model="form.ruc" type="text" class="form-control" required />
          </div>
          <div class="col-md-6">
            <label class="form-label">Subdominio *</label>
            <input v-model="form.domain" type="text" class="form-control" required placeholder="ej. negocio3" />
            <div class="form-text">
              Solo el fragmento de subdominio (ej. "negocio3"), nunca un hostname completo
              — no puede contener puntos.
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Razón social *</label>
            <input v-model="form.razon_social" type="text" class="form-control" required />
          </div>
          <div class="col-md-6">
            <label class="form-label">Nombre comercial *</label>
            <input v-model="form.razon_social_comercial" type="text" class="form-control" required />
          </div>
          <div class="col-md-6">
            <label class="form-label">Nombre del admin *</label>
            <input v-model="form.admin_name" type="text" class="form-control" required />
          </div>
          <div class="col-md-6">
            <label class="form-label">Email del admin *</label>
            <input v-model="form.admin_email" type="email" class="form-control" required />
          </div>
          <div class="col-md-6">
            <label class="form-label">Contraseña del admin * (mín. 8 caracteres)</label>
            <div class="input-group">
              <input
                v-model="form.admin_password"
                type="text"
                class="form-control"
                required
                minlength="8"
              />
              <button type="button" class="btn btn-outline-secondary" @click="generatePassword">
                Generar
              </button>
            </div>
          </div>

          <div class="col-12">
            <div v-if="store.createError" class="alert alert-danger py-2">{{ store.createError }}</div>
            <button type="submit" class="btn btn-primary" :disabled="store.creating">
              {{ store.creating ? 'Creando tenant…' : 'Crear tenant' }}
            </button>
            <button type="button" class="btn btn-outline-secondary ms-2" @click="onCancelCreate">
              Cancelar
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="store.loading" class="d-flex align-items-center gap-2 text-muted py-4">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando tenants…</span>
    </div>

    <!-- Error state -->
    <div v-else-if="store.error" class="alert alert-danger d-flex justify-content-between align-items-center">
      <span>{{ store.error }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="store.fetchTenants(true)">
        Reintentar
      </button>
    </div>

    <!-- Empty state -->
    <div v-else-if="store.tenants.length === 0" class="alert alert-light border text-muted">
      No hay tenants registrados todavía.
    </div>

    <!-- Tabla -->
    <div v-else class="table-responsive">
      <div v-if="store.archiveError" class="alert alert-danger py-2">{{ store.archiveError }}</div>

      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th scope="col">Tenant</th>
            <th scope="col">RUC</th>
            <th scope="col">Dominio(s)</th>
            <th scope="col">Estado</th>
            <th scope="col">Fecha de alta</th>
            <th scope="col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="tenant in store.tenants" :key="tenant.id">
            <tr
              role="link"
              style="cursor: pointer"
              @click="$router.push({ name: 'tenant-detail', params: { id: tenant.id } })"
            >
              <td>
                <router-link
                  :to="{ name: 'tenant-detail', params: { id: tenant.id } }"
                  class="text-decoration-none fw-semibold"
                  @click.stop
                >
                  {{ tenant.razon_social_comercial || tenant.razon_social }}
                </router-link>
                <div class="text-muted small">{{ tenant.id }}</div>
              </td>
              <td>{{ tenant.ruc }}</td>
              <td>
                <span v-if="tenant.domains.length === 0" class="text-muted fst-italic">sin dominio</span>
                <span v-else>{{ tenant.domains.join(', ') }}</span>
              </td>
              <td>
                <span class="badge" :class="statusBadgeClass(tenant.status)">{{ tenant.status }}</span>
              </td>
              <td>{{ formatFecha(tenant.fecha_alta) }}</td>
              <td @click.stop>
                <div class="d-flex gap-1">
                  <button
                    v-if="tenant.status === 'archivado'"
                    type="button"
                    class="btn btn-sm btn-outline-success"
                    :disabled="store.archiving"
                    @click="onRestore(tenant.id)"
                  >
                    Restaurar
                  </button>
                  <button
                    v-else
                    type="button"
                    class="btn btn-sm btn-outline-warning"
                    :disabled="store.archiving"
                    @click="onArchive(tenant.id)"
                  >
                    Archivar
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    :disabled="store.deleting"
                    @click="askDelete(tenant.id)"
                  >
                    Eliminar
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="confirmingDeleteId === tenant.id">
              <td colspan="6">
                <div class="alert alert-danger d-flex justify-content-between align-items-center mb-0">
                  <div>
                    <strong>¿Eliminar "{{ tenant.id }}" definitivamente?</strong>
                    <div class="small">
                      Solo funciona si el tenant no tiene Company/SunatConfig/clientes/
                      productos/ventas todavía — pensado para deshacer una creación por
                      error. Si ya tiene datos reales, el backend lo va a rechazar; usá
                      "Archivar" en su lugar.
                    </div>
                    <div v-if="store.deleteError" class="text-danger mt-1">{{ store.deleteError }}</div>
                  </div>
                  <div class="d-flex gap-2">
                    <button
                      type="button"
                      class="btn btn-sm btn-danger"
                      :disabled="store.deleting"
                      @click="onConfirmDelete(tenant.id)"
                    >
                      {{ store.deleting ? 'Eliminando…' : 'Sí, eliminar' }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="cancelDelete">
                      Cancelar
                    </button>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <p class="text-muted small">Total: {{ store.total }}</p>
    </div>
  </div>
</template>

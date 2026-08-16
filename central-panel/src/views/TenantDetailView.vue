<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import httpClient from '@/services/httpClient';
import { useTenantsStore } from '@/stores/tenants';
import type { UpdateTenantPayload } from '@/stores/tenants';
import type { TenantOverview } from '@/types/tenant-detail';
import CompanyTab from '@/components/tenant-detail/CompanyTab.vue';
import SubscriptionTab from '@/components/tenant-detail/SubscriptionTab.vue';
import BackupsTab from '@/components/tenant-detail/BackupsTab.vue';
import SunatConfigTab from '@/components/tenant-detail/SunatConfigTab.vue';
import CertificadoTab from '@/components/tenant-detail/CertificadoTab.vue';
import TestEmissionTab from '@/components/tenant-detail/TestEmissionTab.vue';

const route = useRoute();
const tenantId = route.params.id as string;
const store = useTenantsStore();

// Overview (GET tenants/{id}) — fetch propio, no vive en el store de tenants (decisión
// ya tomada: ese store es para el listado; el detalle resuelve cada pieza por su cuenta).
const overview = ref<TenantOverview | null>(null);
const overviewLoading = ref(false);
const overviewError = ref<string | null>(null);

async function fetchOverview() {
  overviewLoading.value = true;
  overviewError.value = null;

  try {
    const { data } = await httpClient.get(`central/tenants/${tenantId}`);
    overview.value = data.tenant;
  } catch (e: any) {
    overviewError.value = e.response?.data?.message ?? 'No se pudo cargar el tenant.';
  } finally {
    overviewLoading.value = false;
  }
}

onMounted(fetchOverview);

// suspender/reactivar (tab Suscripción) devuelven el Tenant actualizado — se emite hacia
// acá para refrescar el encabezado sin tener que volver a pedir el overview completo.
function onTenantUpdated(tenant: TenantOverview) {
  overview.value = tenant;
}

// Editar razón social/comercial/giro — panel inline (mismo patrón que el resto del
// panel, sin modal de Bootstrap con JS imperativo). Ver
// docs/planning/panel-superadmin/gap-editar-tenant-giro-password.md: cambiar giro
// dispara migrarVertical() del lado del backend, puede tardar unos segundos.
const showEditForm = ref(false);
const editForm = reactive<UpdateTenantPayload>({
  razon_social: '',
  razon_social_comercial: '',
  giro: 'retail',
});

// Cambiar el giro dispara migraciones retroactivas reales sobre la base física del
// tenant — no debería poder pasar por accidente con un solo click (ver
// docs/planning/panel-superadmin/sesion-giro-selector-panel.md, punto 4). Mientras el
// giro elegido difiera del original, exige tildar la confirmación explícita antes de
// habilitar "Guardar cambios".
const giroChangeConfirmed = ref(false);
const giroChanged = computed(
  () => !!overview.value && editForm.giro !== overview.value.giro,
);

function openEditForm() {
  if (!overview.value) return;
  editForm.razon_social = overview.value.razon_social;
  editForm.razon_social_comercial = overview.value.razon_social_comercial;
  editForm.giro = overview.value.giro;
  giroChangeConfirmed.value = false;
  store.updateError = null;
  showEditForm.value = true;
}

function cancelEditForm() {
  showEditForm.value = false;
  store.updateError = null;
}

async function onSaveEdit() {
  if (giroChanged.value && !giroChangeConfirmed.value) return;
  const tenant = await store.updateTenant(tenantId, { ...editForm });
  if (tenant) {
    overview.value = tenant;
    showEditForm.value = false;
  }
}

// Restablecer password del admin — sección separada del formulario de edición de
// arriba a propósito (acción sensible, más fácil de auditar sola). admin_email solo
// hace falta si el tenant tiene más de un Super-Admin (el backend avisa con 422 y
// lista los candidatos si hace falta).
const showResetPasswordForm = ref(false);
const resetPasswordForm = reactive({ admin_email: '', new_password: '' });
const resetPasswordSuccess = ref(false);

function openResetPasswordForm() {
  resetPasswordForm.admin_email = '';
  resetPasswordForm.new_password = '';
  resetPasswordSuccess.value = false;
  store.resetPasswordError = null;
  showResetPasswordForm.value = true;
}

function cancelResetPasswordForm() {
  showResetPasswordForm.value = false;
  store.resetPasswordError = null;
}

// Misma conveniencia que "Generar" en el alta de tenant (TenantListView) — el
// superadmin puede editarla igual antes de confirmar.
function generateResetPassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  let result = '';
  for (let i = 0; i < 16; i++) {
    result += chars[Math.floor(Math.random() * chars.length)];
  }
  resetPasswordForm.new_password = result;
}

async function onSaveResetPassword() {
  resetPasswordSuccess.value = false;
  const ok = await store.resetAdminPassword(
    tenantId,
    resetPasswordForm.new_password,
    resetPasswordForm.admin_email || undefined,
  );
  if (ok) {
    resetPasswordSuccess.value = true;
    resetPasswordForm.new_password = '';
  }
}

const tabs = [
  { key: 'company', label: 'Company' },
  { key: 'subscription', label: 'Suscripción' },
  { key: 'backups', label: 'Backups' },
  { key: 'sunat-config', label: 'SunatConfig' },
  { key: 'certificado', label: 'Certificado' },
  { key: 'test-emission', label: 'Test-emission' },
] as const;

const activeTab = ref<(typeof tabs)[number]['key']>('company');

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

const domainsText = computed(() =>
  overview.value && overview.value.domains.length > 0 ? overview.value.domains.join(', ') : 'sin dominio',
);
</script>

<template>
  <div class="container py-4">
    <router-link :to="{ name: 'tenants' }" class="btn btn-outline-secondary btn-sm mb-3">
      ← Volver al listado
    </router-link>

    <div v-if="overviewLoading" class="d-flex align-items-center gap-2 text-muted py-3">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando tenant…</span>
    </div>

    <div v-else-if="overviewError" class="alert alert-danger d-flex justify-content-between align-items-center">
      <span>{{ overviewError }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="fetchOverview">
        Reintentar
      </button>
    </div>

    <template v-else-if="overview">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <h1 class="h4 mb-1">{{ overview.razon_social_comercial || overview.razon_social }}</h1>
          <p class="text-muted mb-0">
            {{ overview.id }} — RUC {{ overview.ruc }} — giro: {{ overview.giro }} —
            dominio(s): {{ domainsText }}
          </p>
        </div>
        <div class="d-flex align-items-start gap-2">
          <span class="badge" :class="statusBadgeClass(overview.status)">{{ overview.status }}</span>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="openEditForm">
            Editar
          </button>
          <button type="button" class="btn btn-outline-warning btn-sm" @click="openResetPasswordForm">
            Restablecer password del admin
          </button>
        </div>
      </div>

      <!-- Edición de razón social / giro -->
      <div v-if="showEditForm" class="card mb-3">
        <div class="card-body">
          <h2 class="h6">Editar tenant</h2>
          <p class="text-muted small">
            Cambiar el giro dispara las migraciones del vertical correspondiente del
            lado del backend — puede tardar unos segundos, no es instantáneo.
          </p>
          <form class="row g-3" @submit.prevent="onSaveEdit">
            <div class="col-md-6">
              <label class="form-label">Razón social</label>
              <input v-model="editForm.razon_social" type="text" class="form-control" />
            </div>
            <div class="col-md-6">
              <label class="form-label">Nombre comercial</label>
              <input v-model="editForm.razon_social_comercial" type="text" class="form-control" />
            </div>
            <div class="col-md-6">
              <label class="form-label">Giro</label>
              <select v-model="editForm.giro" class="form-select" @change="giroChangeConfirmed = false">
                <option value="retail">Retail</option>
                <option value="agencia_viajes">Agencia de viajes</option>
              </select>
            </div>
            <div v-if="giroChanged" class="col-12">
              <div class="alert alert-warning py-2 mb-2">
                Esto va a aplicar retroactivamente las migraciones del vertical
                "{{ editForm.giro }}" a este tenant — puede tardar unos segundos y no
                es reversible con un click. Confirmá antes de guardar.
                <div class="form-check mt-2">
                  <input
                    id="confirmGiroChange"
                    v-model="giroChangeConfirmed"
                    type="checkbox"
                    class="form-check-input"
                  />
                  <label class="form-check-label" for="confirmGiroChange">
                    Confirmo el cambio de giro y sus migraciones retroactivas.
                  </label>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div v-if="store.updateError" class="alert alert-danger py-2">{{ store.updateError }}</div>
              <button
                type="submit"
                class="btn btn-primary"
                :disabled="store.updating || (giroChanged && !giroChangeConfirmed)"
              >
                {{ store.updating ? 'Guardando…' : 'Guardar cambios' }}
              </button>
              <button type="button" class="btn btn-outline-secondary ms-2" @click="cancelEditForm">
                Cancelar
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Restablecer password del admin — separado a propósito, acción sensible -->
      <div v-if="showResetPasswordForm" class="card mb-3 border-warning">
        <div class="card-body">
          <h2 class="h6">Restablecer password del administrador</h2>
          <p class="text-muted small">
            Busca el usuario con rol Super-Admin dentro de este tenant. Si hay más de
            uno, especificá el email — el backend lista los candidatos si hace falta.
          </p>
          <form class="row g-3" @submit.prevent="onSaveResetPassword">
            <div class="col-md-6">
              <label class="form-label">Email del admin (opcional)</label>
              <input
                v-model="resetPasswordForm.admin_email"
                type="email"
                class="form-control"
                placeholder="solo si hay más de un Super-Admin"
              />
            </div>
            <div class="col-md-6">
              <label class="form-label">Password nuevo * (mín. 8 caracteres)</label>
              <div class="input-group">
                <input
                  v-model="resetPasswordForm.new_password"
                  type="text"
                  class="form-control"
                  required
                  minlength="8"
                />
                <button type="button" class="btn btn-outline-secondary" @click="generateResetPassword">
                  Generar
                </button>
              </div>
            </div>
            <div class="col-12">
              <div v-if="resetPasswordSuccess" class="alert alert-success py-2">
                Password actualizado correctamente.
              </div>
              <div v-if="store.resetPasswordError" class="alert alert-danger py-2">
                {{ store.resetPasswordError }}
              </div>
              <button type="submit" class="btn btn-warning" :disabled="store.resettingPassword">
                {{ store.resettingPassword ? 'Guardando…' : 'Restablecer password' }}
              </button>
              <button type="button" class="btn btn-outline-secondary ms-2" @click="cancelResetPasswordForm">
                Cerrar
              </button>
            </div>
          </form>
        </div>
      </div>

      <ul class="nav nav-tabs mb-3">
        <li v-for="tab in tabs" :key="tab.key" class="nav-item">
          <button
            type="button"
            class="nav-link"
            :class="{ active: activeTab === tab.key }"
            @click="activeTab = tab.key"
          >
            {{ tab.label }}
          </button>
        </li>
      </ul>

      <CompanyTab v-if="activeTab === 'company'" :tenant-id="tenantId" />
      <SubscriptionTab
        v-else-if="activeTab === 'subscription'"
        :tenant-id="tenantId"
        @tenant-updated="onTenantUpdated"
      />
      <BackupsTab v-else-if="activeTab === 'backups'" :tenant-id="tenantId" />
      <SunatConfigTab v-else-if="activeTab === 'sunat-config'" :tenant-id="tenantId" />
      <CertificadoTab v-else-if="activeTab === 'certificado'" :tenant-id="tenantId" />
      <TestEmissionTab v-else-if="activeTab === 'test-emission'" :tenant-id="tenantId" />
    </template>
  </div>
</template>

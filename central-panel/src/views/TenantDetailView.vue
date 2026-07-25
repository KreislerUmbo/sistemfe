<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import httpClient from '@/services/httpClient';
import type { TenantOverview } from '@/types/tenant-detail';
import CompanyTab from '@/components/tenant-detail/CompanyTab.vue';
import SubscriptionTab from '@/components/tenant-detail/SubscriptionTab.vue';
import BackupsTab from '@/components/tenant-detail/BackupsTab.vue';
import SunatConfigTab from '@/components/tenant-detail/SunatConfigTab.vue';
import CertificadoTab from '@/components/tenant-detail/CertificadoTab.vue';
import TestEmissionTab from '@/components/tenant-detail/TestEmissionTab.vue';

const route = useRoute();
const tenantId = route.params.id as string;

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
            {{ overview.id }} — RUC {{ overview.ruc }} — dominio(s): {{ domainsText }}
          </p>
        </div>
        <span class="badge" :class="statusBadgeClass(overview.status)">{{ overview.status }}</span>
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

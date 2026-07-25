<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useTenantsStore } from '@/stores/tenants';
import SubscriptionPlanForm from '@/components/tenant-detail/SubscriptionPlanForm.vue';
import type { TenantOverview } from '@/types/tenant-detail';

const props = defineProps<{ tenantId: string }>();
const emit = defineEmits<{ (e: 'tenant-updated', tenant: TenantOverview): void }>();

const store = useTenantsStore();

onMounted(() => {
  store.fetchSubscription(props.tenantId);
});

// Formularios inline chicos (sin modal de Bootstrap con JS imperativo) para las 2
// acciones que exigen un motivo — mismo criterio de "mínimo, iterar después" del resto
// del scaffold.
const showSuspendForm = ref(false);
const suspendMotivo = ref('');

async function onSuspend() {
  if (!suspendMotivo.value.trim()) return;
  const tenant = await store.suspendTenant(props.tenantId, suspendMotivo.value);
  if (tenant) {
    emit('tenant-updated', tenant);
    showSuspendForm.value = false;
    suspendMotivo.value = '';
  }
}

async function onReactivate() {
  const tenant = await store.reactivateTenant(props.tenantId);
  if (tenant) emit('tenant-updated', tenant);
}

const nuevoInvoicePeriodo = ref('');

async function onGenerateInvoice() {
  await store.generateInvoice(props.tenantId, nuevoInvoicePeriodo.value || undefined);
  nuevoInvoicePeriodo.value = '';
}

// Un solo formulario de "rechazar voucher" abierto a la vez — se identifica el voucher
// activo por id, no por índice (los invoices/vouchers se reordenan al refrescar).
const rejectingVoucherId = ref<number | null>(null);
const rejectMotivo = ref('');

function openReject(voucherId: number) {
  rejectingVoucherId.value = voucherId;
  rejectMotivo.value = '';
}

async function onReject(invoiceId: number) {
  if (rejectingVoucherId.value === null || !rejectMotivo.value.trim()) return;
  await store.rejectVoucher(props.tenantId, invoiceId, rejectingVoucherId.value, rejectMotivo.value);
  rejectingVoucherId.value = null;
  rejectMotivo.value = '';
}

const voucherFiles = reactive<Record<number, File | null>>({});

function onFileChange(invoiceId: number, event: Event) {
  const input = event.target as HTMLInputElement;
  voucherFiles[invoiceId] = input.files?.[0] ?? null;
}

async function onUploadVoucher(invoiceId: number) {
  const file = voucherFiles[invoiceId];
  if (!file) return;
  await store.uploadVoucher(props.tenantId, invoiceId, file);
  voucherFiles[invoiceId] = null;
}

function invoiceBadgeClass(estado: string): string {
  switch (estado) {
    case 'pagado':
      return 'bg-success';
    case 'vencido':
      return 'bg-danger';
    default:
      return 'bg-warning text-dark';
  }
}

function voucherBadgeClass(estado: string): string {
  switch (estado) {
    case 'verificado':
      return 'bg-success';
    case 'rechazado':
      return 'bg-danger';
    default:
      return 'bg-warning text-dark';
  }
}
</script>

<template>
  <div>
    <div v-if="store.subscription.loading" class="d-flex align-items-center gap-2 text-muted py-3">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando suscripción…</span>
    </div>

    <div
      v-else-if="store.subscription.error"
      class="alert alert-danger d-flex justify-content-between align-items-center"
    >
      <span>{{ store.subscription.error }}</span>
      <button
        type="button"
        class="btn btn-sm btn-outline-danger"
        @click="store.fetchSubscription(tenantId)"
      >
        Reintentar
      </button>
    </div>

    <div v-else-if="!store.subscription.data" class="card mb-3">
      <div class="card-body">
        <p class="text-muted mb-2">Este tenant no tiene una suscripción activa.</p>
        <SubscriptionPlanForm :tenant-id="tenantId" button-label="Asignar plan" />
      </div>
    </div>

    <template v-else>
      <div class="card mb-3">
        <div class="card-body">
          <h2 class="h6">Plan: {{ store.subscription.data.plan.nombre }}</h2>
          <p class="text-muted small mb-2">
            S/ {{ store.subscription.data.monto_mensual_override ?? store.subscription.data.plan.precio_mensual }}
            / mes — corte día {{ store.subscription.data.dia_corte }} — facturación automática:
            <strong>{{ store.subscription.data.facturacion_automatica ? 'sí' : 'no' }}</strong>
          </p>

          <div v-if="store.subscription.actionError" class="alert alert-danger py-2">
            {{ store.subscription.actionError }}
          </div>

          <div class="d-flex gap-2 flex-wrap align-items-start">
            <SubscriptionPlanForm :tenant-id="tenantId" button-label="Cambiar plan" button-class="btn-outline-primary" />
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              :disabled="store.subscription.actionLoading"
              @click="store.toggleAutomaticBilling(tenantId)"
            >
              {{ store.subscription.data.facturacion_automatica ? 'Desactivar' : 'Activar' }} facturación
              automática
            </button>
            <button
              type="button"
              class="btn btn-outline-warning btn-sm"
              :disabled="store.subscription.actionLoading"
              @click="showSuspendForm = !showSuspendForm"
            >
              Suspender tenant
            </button>
            <button
              type="button"
              class="btn btn-outline-success btn-sm"
              :disabled="store.subscription.actionLoading"
              @click="onReactivate"
            >
              Reactivar tenant
            </button>
          </div>

          <div v-if="showSuspendForm" class="mt-3 border rounded p-3 bg-light">
            <label class="form-label">Motivo de la suspensión *</label>
            <textarea v-model="suspendMotivo" class="form-control mb-2" rows="2" maxlength="500"></textarea>
            <button
              type="button"
              class="btn btn-warning btn-sm"
              :disabled="store.subscription.actionLoading || !suspendMotivo.trim()"
              @click="onSuspend"
            >
              Confirmar suspensión
            </button>
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Invoices</h2>
        <div class="d-flex gap-2">
          <input
            v-model="nuevoInvoicePeriodo"
            type="text"
            class="form-control form-control-sm"
            placeholder="YYYY-MM (opcional)"
            style="width: 160px"
          />
          <button
            type="button"
            class="btn btn-outline-primary btn-sm"
            :disabled="store.subscription.actionLoading"
            @click="onGenerateInvoice"
          >
            Generar invoice
          </button>
        </div>
      </div>

      <div v-for="invoice in store.subscription.invoices" :key="invoice.id" class="card mb-2">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <strong>{{ invoice.folio_interno }}</strong>
              <span class="text-muted"> — {{ invoice.periodo }} — S/ {{ invoice.monto }}</span>
              <div class="text-muted small">Vence: {{ invoice.fecha_vencimiento }}</div>
            </div>
            <span class="badge" :class="invoiceBadgeClass(invoice.estado)">{{ invoice.estado }}</span>
          </div>

          <button
            v-if="invoice.estado === 'pendiente'"
            type="button"
            class="btn btn-sm btn-outline-success mt-2"
            :disabled="store.subscription.actionLoading"
            @click="store.markInvoicePaid(tenantId, invoice.id)"
          >
            Marcar pagado
          </button>

          <hr />
          <h3 class="h6">Vouchers</h3>
          <ul v-if="invoice.vouchers.length > 0" class="list-unstyled mb-2">
            <li v-for="voucher in invoice.vouchers" :key="voucher.id" class="mb-2">
              <span class="badge" :class="voucherBadgeClass(voucher.estado)">{{ voucher.estado }}</span>
              <span v-if="voucher.motivo_rechazo" class="text-muted small ms-2">
                ({{ voucher.motivo_rechazo }})
              </span>

              <template v-if="voucher.estado === 'pendiente_verificacion'">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-success ms-2"
                  :disabled="store.subscription.actionLoading"
                  @click="store.verifyVoucher(tenantId, invoice.id, voucher.id)"
                >
                  Verificar
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger ms-1"
                  :disabled="store.subscription.actionLoading"
                  @click="openReject(voucher.id)"
                >
                  Rechazar
                </button>

                <div v-if="rejectingVoucherId === voucher.id" class="mt-2 border rounded p-2 bg-light">
                  <label class="form-label small">Motivo del rechazo *</label>
                  <textarea v-model="rejectMotivo" class="form-control form-control-sm mb-2" rows="2" maxlength="500"></textarea>
                  <button
                    type="button"
                    class="btn btn-sm btn-danger"
                    :disabled="store.subscription.actionLoading || !rejectMotivo.trim()"
                    @click="onReject(invoice.id)"
                  >
                    Confirmar rechazo
                  </button>
                </div>
              </template>
            </li>
          </ul>
          <p v-else class="text-muted small">Sin vouchers subidos todavía.</p>

          <div class="d-flex gap-2 align-items-center">
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              class="form-control form-control-sm"
              style="max-width: 300px"
              @change="onFileChange(invoice.id, $event)"
            />
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              :disabled="store.subscription.actionLoading || !voucherFiles[invoice.id]"
              @click="onUploadVoucher(invoice.id)"
            >
              Subir voucher
            </button>
          </div>
        </div>
      </div>
      <p v-if="store.subscription.invoices.length === 0" class="text-muted">Sin invoices todavía.</p>
    </template>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useTenantsStore } from '@/stores/tenants';
import { usePlansStore } from '@/stores/plans';
import type { SubscriptionPayload } from '@/types/tenant-detail';

// Formulario de "asignar plan" (sin suscripción todavía) / "cambiar plan" (ya tiene una
// activa) — mismo componente en los 2 lugares donde SubscriptionTab.vue lo necesita
// (branch sin suscripción y botón dentro de la card de suscripción existente), evita
// duplicar el select de planes + validación dos veces en el mismo archivo.
const props = withDefaults(
  defineProps<{
    tenantId: string;
    buttonLabel: string;
    buttonClass?: string;
  }>(),
  { buttonClass: 'btn-primary' },
);

const store = useTenantsStore();
const plansStore = usePlansStore();

onMounted(() => {
  // Solo los planes activos son elegibles para una suscripción nueva o un cambio de
  // plan (mismo criterio que el backend) — se carga una sola vez, cacheado en el store.
  plansStore.fetchPlans();
});

const show = ref(false);
const form = reactive<SubscriptionPayload>({
  tenant_plan_id: 0,
  dia_corte: null,
  facturacion_automatica: false,
});

function open() {
  if (store.subscription.data) {
    form.tenant_plan_id = store.subscription.data.tenant_plan_id;
    form.dia_corte = store.subscription.data.dia_corte;
    form.facturacion_automatica = store.subscription.data.facturacion_automatica;
  } else {
    form.tenant_plan_id = 0;
    form.dia_corte = null;
    form.facturacion_automatica = false;
  }
  show.value = true;
  store.subscription.actionError = null;
}

function close() {
  show.value = false;
  store.subscription.actionError = null;
}

async function onSubmit() {
  if (!form.tenant_plan_id) return;

  const ok = store.subscription.data
    ? await store.updateSubscription(props.tenantId, { ...form })
    : await store.createSubscription(props.tenantId, { ...form });

  if (ok) show.value = false;
}
</script>

<template>
  <div>
    <button
      v-if="!show"
      type="button"
      class="btn btn-sm"
      :class="buttonClass"
      @click="open"
    >
      {{ buttonLabel }}
    </button>

    <div v-else class="border rounded p-3 bg-light mt-2" style="min-width: 320px">
      <div v-if="plansStore.loading" class="text-muted small mb-2">Cargando planes…</div>
      <div v-else-if="plansStore.plans.filter((p) => p.activo).length === 0" class="alert alert-warning py-2 mb-2">
        No hay planes activos en el catálogo — creá uno primero en
        <router-link :to="{ name: 'plans' }">Planes</router-link>.
      </div>

      <form v-else class="row g-2" @submit.prevent="onSubmit">
        <div class="col-12">
          <label class="form-label">Plan *</label>
          <select v-model.number="form.tenant_plan_id" class="form-select form-select-sm" required>
            <option :value="0" disabled>Elegí un plan…</option>
            <option
              v-for="plan in plansStore.plans.filter((p) => p.activo || p.id === form.tenant_plan_id)"
              :key="plan.id"
              :value="plan.id"
            >
              {{ plan.nombre }} — S/ {{ plan.precio_mensual }}/mes
            </option>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label">Día de corte</label>
          <input
            v-model.number="form.dia_corte"
            type="number"
            min="1"
            max="31"
            class="form-control form-control-sm"
            placeholder="default"
          />
        </div>
        <div class="col-6 d-flex align-items-end">
          <div class="form-check">
            <input
              v-model="form.facturacion_automatica"
              type="checkbox"
              class="form-check-input"
              id="subscriptionFormFacturacionAutomatica"
            />
            <label class="form-check-label small" for="subscriptionFormFacturacionAutomatica">
              Facturación automática
            </label>
          </div>
        </div>

        <div class="col-12">
          <div v-if="store.subscription.actionError" class="alert alert-danger py-2 mb-2">
            {{ store.subscription.actionError }}
          </div>
          <button type="submit" class="btn btn-sm btn-primary" :disabled="store.subscription.actionLoading">
            {{ store.subscription.actionLoading ? 'Guardando…' : 'Guardar' }}
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-2" @click="close">
            Cancelar
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

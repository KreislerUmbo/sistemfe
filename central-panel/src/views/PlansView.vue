<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { usePlansStore } from '@/stores/plans';
import type { PlanPayload } from '@/stores/plans';
import type { Plan } from '@/types/tenant-detail';

const store = usePlansStore();

onMounted(() => {
  store.fetchPlans();
});

const emptyForm: PlanPayload = {
  nombre: '',
  limite_usuarios: null,
  limite_comprobantes_mes: null,
  limite_storage_mb: null,
  precio_mensual: 0,
  activo: true,
};

// Un solo formulario reusado para alta y edición (mismo criterio que el resto del panel:
// paneles inline, sin modal de Bootstrap con JS imperativo) — editingId null = alta.
const form = reactive<PlanPayload>({ ...emptyForm });
const showForm = ref(false);
const editingId = ref<number | null>(null);

function openCreate() {
  Object.assign(form, emptyForm);
  editingId.value = null;
  showForm.value = true;
  store.saveError = null;
}

function openEdit(plan: Plan) {
  form.nombre = plan.nombre;
  form.limite_usuarios = plan.limite_usuarios;
  form.limite_comprobantes_mes = plan.limite_comprobantes_mes;
  form.limite_storage_mb = plan.limite_storage_mb;
  form.precio_mensual = Number(plan.precio_mensual);
  form.activo = plan.activo;
  editingId.value = plan.id;
  showForm.value = true;
  store.saveError = null;
}

function closeForm() {
  showForm.value = false;
  editingId.value = null;
  store.saveError = null;
}

async function onSubmit() {
  const ok = editingId.value
    ? await store.updatePlan(editingId.value, { ...form })
    : await store.createPlan({ ...form });
  if (ok) closeForm();
}

const confirmingDeactivateId = ref<number | null>(null);

function askDeactivate(id: number) {
  confirmingDeactivateId.value = id;
  store.deactivateError = null;
}

function cancelDeactivate() {
  confirmingDeactivateId.value = null;
  store.deactivateError = null;
}

async function onConfirmDeactivate(id: number) {
  const ok = await store.deactivatePlan(id);
  if (ok) confirmingDeactivateId.value = null;
}

function formatLimite(valor: number | null): string {
  return valor === null ? 'sin límite' : valor.toLocaleString('es-PE');
}
</script>

<template>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 mb-0">Planes de suscripción</h1>
      <div class="d-flex gap-2">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="store.loading"
          @click="store.fetchPlans(true)"
        >
          Actualizar
        </button>
        <button type="button" class="btn btn-primary btn-sm" @click="showForm ? closeForm() : openCreate()">
          Nuevo plan
        </button>
      </div>
    </div>

    <!-- Alta / edición -->
    <div v-if="showForm" class="card mb-3">
      <div class="card-body">
        <h2 class="h6">{{ editingId ? 'Editar plan' : 'Nuevo plan' }}</h2>

        <form class="row g-3" @submit.prevent="onSubmit">
          <div class="col-md-6">
            <label class="form-label">Nombre *</label>
            <input v-model="form.nombre" type="text" class="form-control" required maxlength="255" />
          </div>
          <div class="col-md-6">
            <label class="form-label">Precio mensual (S/) *</label>
            <input
              v-model.number="form.precio_mensual"
              type="number"
              step="0.01"
              min="0"
              class="form-control"
              required
            />
          </div>
          <div class="col-md-4">
            <label class="form-label">Límite de usuarios</label>
            <input
              v-model.number="form.limite_usuarios"
              type="number"
              min="0"
              class="form-control"
              placeholder="sin límite"
            />
          </div>
          <div class="col-md-4">
            <label class="form-label">Límite de comprobantes/mes</label>
            <input
              v-model.number="form.limite_comprobantes_mes"
              type="number"
              min="0"
              class="form-control"
              placeholder="sin límite"
            />
          </div>
          <div class="col-md-4">
            <label class="form-label">Límite de storage (MB)</label>
            <input
              v-model.number="form.limite_storage_mb"
              type="number"
              min="0"
              class="form-control"
              placeholder="sin límite"
            />
          </div>
          <div class="col-12">
            <div class="form-check">
              <input v-model="form.activo" type="checkbox" class="form-check-input" id="planActivo" />
              <label class="form-check-label" for="planActivo">Activo (elegible para suscripciones nuevas)</label>
            </div>
          </div>

          <div class="col-12">
            <div v-if="store.saveError" class="alert alert-danger py-2">{{ store.saveError }}</div>
            <button type="submit" class="btn btn-primary" :disabled="store.saving">
              {{ store.saving ? 'Guardando…' : editingId ? 'Guardar cambios' : 'Crear plan' }}
            </button>
            <button type="button" class="btn btn-outline-secondary ms-2" @click="closeForm">
              Cancelar
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="store.loading" class="d-flex align-items-center gap-2 text-muted py-4">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando planes…</span>
    </div>

    <!-- Error state -->
    <div v-else-if="store.error" class="alert alert-danger d-flex justify-content-between align-items-center">
      <span>{{ store.error }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="store.fetchPlans(true)">
        Reintentar
      </button>
    </div>

    <!-- Empty state -->
    <div v-else-if="store.plans.length === 0" class="alert alert-light border text-muted">
      No hay planes registrados todavía.
    </div>

    <!-- Tabla -->
    <div v-else class="table-responsive">
      <div v-if="store.deactivateError" class="alert alert-danger py-2">{{ store.deactivateError }}</div>

      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th scope="col">Plan</th>
            <th scope="col">Precio mensual</th>
            <th scope="col">Usuarios</th>
            <th scope="col">Comprobantes/mes</th>
            <th scope="col">Storage</th>
            <th scope="col">Estado</th>
            <th scope="col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="plan in store.plans" :key="plan.id">
            <tr>
              <td class="fw-semibold">{{ plan.nombre }}</td>
              <td>S/ {{ plan.precio_mensual }}</td>
              <td>{{ formatLimite(plan.limite_usuarios) }}</td>
              <td>{{ formatLimite(plan.limite_comprobantes_mes) }}</td>
              <td>{{ plan.limite_storage_mb === null ? 'sin límite' : `${plan.limite_storage_mb.toLocaleString('es-PE')} MB` }}</td>
              <td>
                <span class="badge" :class="plan.activo ? 'bg-success' : 'bg-secondary'">
                  {{ plan.activo ? 'activo' : 'inactivo' }}
                </span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button type="button" class="btn btn-sm btn-outline-primary" @click="openEdit(plan)">
                    Editar
                  </button>
                  <button
                    v-if="plan.activo"
                    type="button"
                    class="btn btn-sm btn-outline-warning"
                    :disabled="store.deactivating"
                    @click="askDeactivate(plan.id)"
                  >
                    Desactivar
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="confirmingDeactivateId === plan.id">
              <td colspan="7">
                <div class="alert alert-warning d-flex justify-content-between align-items-center mb-0">
                  <div>
                    <strong>¿Desactivar "{{ plan.nombre }}"?</strong>
                    <div class="small">
                      Deja de estar disponible para suscripciones nuevas. Las suscripciones
                      que ya lo usan no se ven afectadas.
                    </div>
                  </div>
                  <div class="d-flex gap-2">
                    <button
                      type="button"
                      class="btn btn-sm btn-warning"
                      :disabled="store.deactivating"
                      @click="onConfirmDeactivate(plan.id)"
                    >
                      {{ store.deactivating ? 'Desactivando…' : 'Sí, desactivar' }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="cancelDeactivate">
                      Cancelar
                    </button>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <p class="text-muted small">Total: {{ store.plans.length }}</p>
    </div>
  </div>
</template>

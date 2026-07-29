<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useProveedorTiposStore } from '@/stores/proveedorTipos';
import type { ProveedorTipoPayload } from '@/stores/proveedorTipos';
import type { ProveedorTipo } from '@/types/proveedor-tipo';

const store = useProveedorTiposStore();

onMounted(() => {
  store.fetchTipos();
});

const emptyForm: ProveedorTipoPayload = {
  nombre: '',
  activo: true,
};

// Un solo formulario reusado para alta y edición (mismo criterio que PlansView.vue) —
// editingId null = alta. No hay campo de slug: lo deriva el backend de `nombre` una sola
// vez al crear y queda fijo para siempre (ver ProveedorTipoController).
const form = reactive<ProveedorTipoPayload>({ ...emptyForm });
const showForm = ref(false);
const editingId = ref<number | null>(null);
const editingSlug = ref<string | null>(null);

function openCreate() {
  Object.assign(form, emptyForm);
  editingId.value = null;
  editingSlug.value = null;
  showForm.value = true;
  store.saveError = null;
}

function openEdit(tipo: ProveedorTipo) {
  form.nombre = tipo.nombre;
  form.activo = tipo.activo;
  editingId.value = tipo.id;
  editingSlug.value = tipo.slug;
  showForm.value = true;
  store.saveError = null;
}

function closeForm() {
  showForm.value = false;
  editingId.value = null;
  editingSlug.value = null;
  store.saveError = null;
}

async function onSubmit() {
  const ok = editingId.value
    ? await store.updateTipo(editingId.value, { ...form })
    : await store.createTipo({ ...form });
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
  const ok = await store.deactivateTipo(id);
  if (ok) confirmingDeactivateId.value = null;
}
</script>

<template>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h1 class="h4 mb-0">Tipos de proveedor</h1>
        <p class="text-muted small mb-0">
          Catálogo compartido por todos los tenants del vertical Agencia de Viajes — cada
          agencia elige cuáles usar desde su propio panel (habilitar/deshabilitar), pero el
          catálogo en sí se administra acá.
        </p>
      </div>
      <div class="d-flex gap-2">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="store.loading"
          @click="store.fetchTipos(true)"
        >
          Actualizar
        </button>
        <button type="button" class="btn btn-primary btn-sm" @click="showForm ? closeForm() : openCreate()">
          Nuevo tipo
        </button>
      </div>
    </div>

    <!-- Alta / edición -->
    <div v-if="showForm" class="card mb-3">
      <div class="card-body">
        <h2 class="h6">{{ editingId ? 'Editar tipo de proveedor' : 'Nuevo tipo de proveedor' }}</h2>

        <form class="row g-3" @submit.prevent="onSubmit">
          <div class="col-md-6">
            <label class="form-label">Nombre *</label>
            <input v-model="form.nombre" type="text" class="form-control" required maxlength="255" />
          </div>
          <div v-if="editingSlug" class="col-md-6">
            <label class="form-label">Slug (fijo, no editable)</label>
            <input :value="editingSlug" type="text" class="form-control" disabled />
          </div>
          <div class="col-12">
            <div class="form-check">
              <input v-model="form.activo" type="checkbox" class="form-check-input" id="tipoActivo" />
              <label class="form-check-label" for="tipoActivo">Activo (disponible para que las agencias lo habiliten)</label>
            </div>
          </div>

          <div class="col-12">
            <div v-if="store.saveError" class="alert alert-danger py-2">{{ store.saveError }}</div>
            <button type="submit" class="btn btn-primary" :disabled="store.saving">
              {{ store.saving ? 'Guardando…' : editingId ? 'Guardar cambios' : 'Crear tipo' }}
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
      <span>Cargando tipos de proveedor…</span>
    </div>

    <!-- Error state -->
    <div v-else-if="store.error" class="alert alert-danger d-flex justify-content-between align-items-center">
      <span>{{ store.error }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="store.fetchTipos(true)">
        Reintentar
      </button>
    </div>

    <!-- Empty state -->
    <div v-else-if="store.tipos.length === 0" class="alert alert-light border text-muted">
      No hay tipos de proveedor registrados todavía.
    </div>

    <!-- Tabla -->
    <div v-else class="table-responsive">
      <div v-if="store.deactivateError" class="alert alert-danger py-2">{{ store.deactivateError }}</div>

      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th scope="col">Nombre</th>
            <th scope="col">Slug</th>
            <th scope="col">Estado</th>
            <th scope="col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="tipo in store.tipos" :key="tipo.id">
            <tr>
              <td class="fw-semibold">{{ tipo.nombre }}</td>
              <td><code>{{ tipo.slug }}</code></td>
              <td>
                <span class="badge" :class="tipo.activo ? 'bg-success' : 'bg-secondary'">
                  {{ tipo.activo ? 'activo' : 'inactivo' }}
                </span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button type="button" class="btn btn-sm btn-outline-primary" @click="openEdit(tipo)">
                    Editar
                  </button>
                  <button
                    v-if="tipo.activo"
                    type="button"
                    class="btn btn-sm btn-outline-warning"
                    :disabled="store.deactivating"
                    @click="askDeactivate(tipo.id)"
                  >
                    Desactivar
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="confirmingDeactivateId === tipo.id">
              <td colspan="4">
                <div class="alert alert-warning d-flex justify-content-between align-items-center mb-0">
                  <div>
                    <strong>¿Desactivar "{{ tipo.nombre }}"?</strong>
                    <div class="small">
                      Deja de estar disponible para que las agencias lo habiliten nuevo.
                      Los proveedores que ya lo usan no se ven afectados.
                    </div>
                  </div>
                  <div class="d-flex gap-2">
                    <button
                      type="button"
                      class="btn btn-sm btn-warning"
                      :disabled="store.deactivating"
                      @click="onConfirmDeactivate(tipo.id)"
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
      <p class="text-muted small">Total: {{ store.tipos.length }}</p>
    </div>
  </div>
</template>

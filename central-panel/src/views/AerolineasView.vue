<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useAerolineasStore } from '@/stores/aerolineas';
import type { AerolineaPayload } from '@/stores/aerolineas';
import type { Aerolinea } from '@/types/aerolinea';

const store = useAerolineasStore();

onMounted(() => {
  store.fetchAerolineas();
});

// Solo edición: las aerolíneas las agregan las agencias desde su cotizador/reserva.
const form = reactive<AerolineaPayload>({ nombre: '', codigo_iata: null, activa: true });
const editingId = ref<number | null>(null);

function openEdit(a: Aerolinea) {
  form.nombre = a.nombre;
  form.codigo_iata = a.codigo_iata;
  form.activa = a.activa;
  editingId.value = a.id;
  store.actionError = null;
}

function closeForm() {
  editingId.value = null;
  store.actionError = null;
}

async function onSubmit() {
  if (editingId.value === null) return;
  const ok = await store.updateAerolinea(editingId.value, {
    ...form,
    codigo_iata: form.codigo_iata?.trim() ? form.codigo_iata.trim() : null,
  });
  if (ok) closeForm();
}

// Desactivar y unir se confirman en una fila debajo de la aerolínea (una sola abierta a la vez).
const confirmingDeactivateId = ref<number | null>(null);
const mergingId = ref<number | null>(null);
const mergeTargetId = ref<number | null>(null);

function askDeactivate(id: number) {
  mergingId.value = null;
  confirmingDeactivateId.value = id;
  store.actionError = null;
}

function askMerge(id: number) {
  confirmingDeactivateId.value = null;
  mergingId.value = id;
  mergeTargetId.value = null;
  store.actionError = null;
}

function cancelRow() {
  confirmingDeactivateId.value = null;
  mergingId.value = null;
  store.actionError = null;
}

async function onConfirmDeactivate(id: number) {
  if (await store.deactivateAerolinea(id)) confirmingDeactivateId.value = null;
}

async function onConfirmMerge(id: number) {
  if (mergeTargetId.value === null) return;
  if (await store.uneAerolinea(id, mergeTargetId.value)) mergingId.value = null;
}

// Destinos posibles para unir: las activas, menos la propia.
const destinosPara = (id: number) => store.aerolineas.filter((a) => a.activa && a.id !== id);

const nombrePorId = computed(() => new Map(store.aerolineas.map((a) => [a.id, a.nombre])));
</script>

<template>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h1 class="h4 mb-0">Aerolíneas</h1>
        <p class="text-muted small mb-0">
          Catálogo común de todas las agencias. Las agencias agregan las que faltan; acá se
          corrigen, se desactivan o se unen duplicadas ("A se une en B": A queda inactiva).
        </p>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        :disabled="store.loading"
        @click="store.fetchAerolineas()"
      >
        Actualizar
      </button>
    </div>

    <!-- Edición -->
    <div v-if="editingId !== null" class="card mb-3">
      <div class="card-body">
        <h2 class="h6">Editar aerolínea</h2>
        <form class="row g-3" @submit.prevent="onSubmit">
          <div class="col-md-6">
            <label class="form-label">Nombre *</label>
            <input v-model="form.nombre" type="text" class="form-control" required minlength="2" maxlength="100" />
          </div>
          <div class="col-md-3">
            <label class="form-label">Código IATA</label>
            <input
              v-model="form.codigo_iata"
              type="text"
              class="form-control"
              maxlength="3"
              pattern="[A-Za-z0-9]{2,3}"
              title="2 o 3 letras o números"
            />
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <div class="form-check">
              <input v-model="form.activa" type="checkbox" class="form-check-input" id="aerolineaActiva" />
              <label class="form-check-label" for="aerolineaActiva">Activa</label>
            </div>
          </div>
          <div class="col-12">
            <div v-if="store.actionError" class="alert alert-danger py-2">{{ store.actionError }}</div>
            <button type="submit" class="btn btn-primary" :disabled="store.working">
              {{ store.working ? 'Guardando…' : 'Guardar cambios' }}
            </button>
            <button type="button" class="btn btn-outline-secondary ms-2" @click="closeForm">Cancelar</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="store.loading" class="d-flex align-items-center gap-2 text-muted py-4">
      <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
      <span>Cargando aerolíneas…</span>
    </div>

    <div v-else-if="store.error" class="alert alert-danger d-flex justify-content-between align-items-center">
      <span>{{ store.error }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="store.fetchAerolineas()">Reintentar</button>
    </div>

    <div v-else-if="store.aerolineas.length === 0" class="alert alert-light border text-muted">
      No hay aerolíneas registradas todavía.
    </div>

    <div v-else class="table-responsive">
      <div v-if="store.actionError && editingId === null" class="alert alert-danger py-2">
        {{ store.actionError }}
      </div>

      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th scope="col">Nombre</th>
            <th scope="col">IATA</th>
            <th scope="col">Estado</th>
            <th scope="col">Agregada por</th>
            <th scope="col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="a in store.aerolineas" :key="a.id">
            <tr>
              <td class="fw-semibold">{{ a.nombre }}</td>
              <td>{{ a.codigo_iata ?? '—' }}</td>
              <td>
                <span class="badge" :class="a.activa ? 'bg-success' : 'bg-secondary'">
                  {{ a.activa ? 'activa' : 'inactiva' }}
                </span>
                <span v-if="a.unida_a_id" class="small text-muted ms-1">
                  unida a {{ nombrePorId.get(a.unida_a_id) ?? `#${a.unida_a_id}` }}
                </span>
              </td>
              <td class="small text-muted">{{ a.creada_por_tenant ?? 'lista inicial' }}</td>
              <td>
                <div class="d-flex gap-1">
                  <button type="button" class="btn btn-sm btn-outline-primary" @click="openEdit(a)">Editar</button>
                  <template v-if="a.activa">
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="askMerge(a.id)">
                      Unir…
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning" @click="askDeactivate(a.id)">
                      Desactivar
                    </button>
                  </template>
                </div>
              </td>
            </tr>

            <tr v-if="confirmingDeactivateId === a.id">
              <td colspan="5">
                <div class="alert alert-warning d-flex justify-content-between align-items-center mb-0">
                  <div>
                    <strong>¿Desactivar "{{ a.nombre }}"?</strong>
                    <div class="small">Deja de salir en el buscador de las agencias. Lo ya guardado no cambia.</div>
                  </div>
                  <div class="d-flex gap-2">
                    <button
                      type="button"
                      class="btn btn-sm btn-warning"
                      :disabled="store.working"
                      @click="onConfirmDeactivate(a.id)"
                    >
                      {{ store.working ? 'Desactivando…' : 'Sí, desactivar' }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="cancelRow">Cancelar</button>
                  </div>
                </div>
              </td>
            </tr>

            <tr v-if="mergingId === a.id">
              <td colspan="5">
                <div class="alert alert-info mb-0">
                  <strong>Unir "{{ a.nombre }}" en…</strong>
                  <div class="small mb-2">
                    "{{ a.nombre }}" queda inactiva y la aerolínea elegida es la que se ofrece a las agencias.
                    Lo ya guardado en cada agencia se corrige aparte, con un comando.
                  </div>
                  <div class="d-flex gap-2 align-items-center">
                    <select v-model="mergeTargetId" class="form-select form-select-sm w-auto">
                      <option :value="null" disabled>Elegir aerolínea de destino</option>
                      <option v-for="d in destinosPara(a.id)" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                    </select>
                    <button
                      type="button"
                      class="btn btn-sm btn-primary"
                      :disabled="store.working || mergeTargetId === null"
                      @click="onConfirmMerge(a.id)"
                    >
                      {{ store.working ? 'Uniendo…' : 'Unir' }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="cancelRow">Cancelar</button>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <p class="text-muted small">Total: {{ store.aerolineas.length }}</p>
    </div>
  </div>
</template>

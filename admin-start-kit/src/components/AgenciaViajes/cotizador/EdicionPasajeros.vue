<template>
    <!-- Agregar/quitar pasajeros — gap real: el endpoint existía en el
         backend desde Sesión 7a pero nunca tuvo control en pantalla.
         Bloqueado (ícono de arriba deshabilitado) si ya hay una
         alternativa aceptada, ver hayAlternativaAceptada. -->
    <div v-if="editandoPasajeros" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-outline-primary" @click="agregarPaxEdicion(30)"><i class="fas fa-plus me-1"></i>Adulto</button>
                <button class="btn btn-sm btn-outline-primary" @click="agregarPaxEdicion(8)"><i class="fas fa-plus me-1"></i>Niño</button>
                <button class="btn btn-sm btn-outline-primary" @click="agregarPaxEdicion(1)"><i class="fas fa-plus me-1"></i>Infante</button>
            </div>
            <div v-if="formPasajeros.length === 0" class="text-muted small fst-italic mb-2">Agregá al menos un pasajero.</div>
            <div class="d-flex flex-column gap-2 mb-3">
                <div v-for="(pax, idx) in formPasajeros" :key="pax.id ?? `nuevo-${idx}`" class="d-flex align-items-center gap-2 border rounded p-2 small">
                    <span class="badge" :class="tipoPorEdadEdicion(pax.edad).clase" style="min-width:70px">{{ tipoPorEdadEdicion(pax.edad).label }}</span>
                    <label class="text-muted mb-0">Edad</label>
                    <input type="number" class="form-control form-control-sm" style="max-width:80px" v-model.number="pax.edad" min="0" max="120">
                    <!-- Fila 13, fase 1b (§3.3): residencia, solo para el precio del tour. -->
                    <select class="form-select form-select-sm" style="max-width:130px" v-model="pax.residencia" title="Residencia (precio del tour)">
                        <option value="nacional">Nacional</option>
                        <option value="extranjero">Extranjero</option>
                    </select>
                    <span v-if="!pax.id" class="badge bg-info-subtle text-info">nuevo</span>
                    <i class="fas fa-times text-danger ms-auto" style="cursor:pointer" @click="formPasajeros.splice(idx, 1)"></i>
                </div>
            </div>
            <div class="alert alert-warning py-2 px-3 small mb-3">
                <i class="fas fa-triangle-exclamation me-1"></i>Si esta cotización ya tiene ítems cargados que se cobran "por persona", al guardar sus precios se recalculan automáticamente con la nueva cantidad de pasajeros — los que no se puedan recalcular solos (ej. pasajes aéreos, paquetes de mayorista) quedan avisados para revisar el precio a mano.
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary btn-sm" @click="guardarPasajeros" :disabled="guardandoPasajeros || formPasajeros.length === 0">
                    <span v-if="guardandoPasajeros" class="spinner-border spinner-border-sm me-1"></span>
                    <span v-else><i class="fas fa-check me-1"></i>Guardar</span>
                </button>
                <button class="btn btn-outline-secondary btn-sm" @click="editandoPasajeros = false">Cancelar</button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Formulario para agregar o quitar pasajeros de la cotización. Todo llega por inject desde
 * cotizador/editar.vue (T-105).
 */
import { CLAVE_PASAJEROS, inyectar } from '@/composables/agencia-viajes/cotizador/claves';

const {
    agregarPaxEdicion, editandoPasajeros, formPasajeros, guardandoPasajeros, guardarPasajeros,
    tipoPorEdadEdicion,
} = inyectar(CLAVE_PASAJEROS);
</script>

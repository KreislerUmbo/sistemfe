<template>
    <!-- Sesión 12f-2, §7.1 punto 1 — chips de destino, un nivel arriba de
         las pestañas de día. Ocultos con 1 solo destino (Punto B, mismo
         criterio que mostrarTabsDia) — el "+ Agregar destino" queda
         visible igual, es el único camino para pasar a 2. -->
    <div v-if="alternativaActiva" class="d-flex align-items-center gap-2 mb-2 flex-wrap ms-2">
        <i v-if="mostrarChipsDestino" class="fas fa-angle-right text-muted" style="font-size:11px"></i>
        <template v-if="mostrarChipsDestino">
            <span v-for="d in alternativaDestinos" :key="d.id" class="destino-chip badge rounded-pill px-2 py-1 d-inline-flex align-items-center gap-2"
                :class="destinoActivoId === d.id ? 'bg-primary' : 'bg-light text-dark border'"
                style="cursor:pointer" @click="destinoActivoId = d.id">
                <span style="font-size:12px"><i class="fas fa-map-marker-alt me-1"></i>{{ d.destino_texto }}</span>
                <template v-if="destinoActivoId === d.id">
                    <i class="fas fa-pen destino-chip-action" title="Editar este destino" @click.stop="abrirFormEditarDestinoActivo"></i>
                    <i class="fas fa-trash destino-chip-action" title="Eliminar este destino" @click.stop="eliminarDestinoActivo"></i>
                </template>
            </span>
        </template>
        <span class="badge rounded-pill px-2 py-1 bg-light text-dark border" style="cursor:pointer;border-style:dashed" @click="abrirFormDestino">
            <i class="fas fa-plus me-1"></i>Agregar destino
        </span>
    </div>

    <div v-if="mostrarFormDestino" class="card border-0 shadow-sm mb-2">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                    <DestinoTreeSelect v-model="nuevoDestinoAtractivoId" @update:label="nuevoDestinoTexto = $event" />
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Fecha desde</label>
                    <CampoFecha v-model="nuevoDestinoFechaInicio" />
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Fecha hasta</label>
                    <CampoFecha v-model="nuevoDestinoFechaFin" />
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm w-100" @click="guardarNuevoDestino" :disabled="guardandoDestino || !nuevoDestinoTexto.trim()">
                        <span v-if="guardandoDestino" class="spinner-border spinner-border-sm me-1"></span>{{ destinoEnEdicionId ? 'Guardar' : 'Agregar' }}
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" @click="mostrarFormDestino = false"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Chips de destino de la alternativa y su formulario (agregar o editar). Todo llega por inject desde
 * cotizador/editar.vue (T-105).
 */
import CampoFecha from '@/components/CampoFecha.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { CLAVE_NUCLEO, CLAVE_DESTINOS, inyectar } from '@/composables/agencia-viajes/cotizador/claves';

const { alternativaActiva } = inyectar(CLAVE_NUCLEO);
const {
    abrirFormDestino, abrirFormEditarDestinoActivo, alternativaDestinos, destinoActivoId, destinoEnEdicionId,
    eliminarDestinoActivo, guardandoDestino, guardarNuevoDestino, mostrarChipsDestino, mostrarFormDestino,
    nuevoDestinoAtractivoId, nuevoDestinoFechaFin, nuevoDestinoFechaInicio, nuevoDestinoTexto,
} = inyectar(CLAVE_DESTINOS);
</script>

<style scoped>
/* Íconos de editar/eliminar del chip de destino activo — mismo patrón de
   hover-reveal que .alt-pill-edit/.alt-pill-delete de PestanasAlternativas. */
.destino-chip-action { opacity: 0; transition: opacity .15s; }
.destino-chip:hover .destino-chip-action { opacity: 1; }
</style>

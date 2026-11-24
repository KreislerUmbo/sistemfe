<template>
    <div class="card border p-2 small mayorista-card"
        :class="{ 'border-success border-2': op.estado === 'elegida', 'opacity-50': op.estado === 'descartada' }">
        <div class="d-flex justify-content-between align-items-start">
            <strong>{{ op.proveedor?.nombre_comercial ?? op.proveedor?.razon_social }}</strong>
            <div v-if="!alternativaSoloLectura" class="d-flex gap-2">
                <i class="fas fa-pen text-muted" style="cursor:pointer;font-size:11px" title="Editar esta opción" @click="abrirFormEditarOpcionMayorista(op)"></i>
                <i v-if="eliminandoOpcionMayoristaId !== op.id" class="fas fa-trash text-muted" style="cursor:pointer;font-size:11px" title="Eliminar esta opción (borra hoteles/opcionales/tours vinculados)" @click="eliminarOpcionMayorista(op)"></i>
                <span v-else class="spinner-border spinner-border-sm" style="width:11px;height:11px"></span>
            </div>
        </div>
        <div class="text-muted" v-if="op.vuelo_aerolinea"><i class="fas fa-plane me-1"></i>{{ op.vuelo_aerolinea }}</div>
        <div class="text-muted mb-1" v-if="op.incluye">{{ textoPlano(op.incluye) }}</div>
        <div class="d-flex justify-content-between align-items-center">
            <span class="badge" :class="{
                'bg-success-subtle text-success': op.estado === 'elegida',
                'bg-warning-subtle text-warning-emphasis': op.estado === 'candidata',
                'bg-light text-muted': op.estado === 'descartada',
            }">{{ op.estado === 'candidata' ? 'Pendiente' : op.estado === 'elegida' ? 'Elegida' : 'Descartada' }}</span>
            <div class="d-flex gap-1" v-if="op.estado !== 'descartada'">
                <button class="btn btn-sm btn-outline-primary" @click="verHoteles(op)">Hoteles</button>
                <button class="btn btn-sm btn-outline-secondary" @click="verOpcionales(op)">
                    Opcionales<span v-if="op.opcionales?.length" class="badge bg-light text-dark border ms-1">{{ op.opcionales.length }}</span>
                </button>
                <button class="btn btn-sm btn-outline-secondary" @click="verTours(op)">
                    Tours<span v-if="op.tours?.length" class="badge bg-light text-dark border ms-1">{{ op.tours.length }}</span>
                </button>
                <button v-if="op.estado !== 'elegida' && !alternativaSoloLectura" class="btn btn-sm btn-outline-success" @click="elegirOpcion(op)" :disabled="eligiendoOpcionId === op.id">
                    <span v-if="eligiendoOpcionId === op.id" class="spinner-border spinner-border-sm"></span><span v-else>Elegir mayorista</span>
                </button>
                <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary" @click="descartarOpcion(op)" :disabled="descartandoOpcionId === op.id" title="Descartar esta opción">
                    <span v-if="descartandoOpcionId === op.id" class="spinner-border spinner-border-sm"></span><i v-else class="fas fa-ban"></i>
                </button>
            </div>
            <button v-else-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary" @click="reactivarOpcion(op)" :disabled="reactivandoOpcionId === op.id">
                <span v-if="reactivandoOpcionId === op.id" class="spinner-border spinner-border-sm"></span><span v-else>Reactivar</span>
            </button>
        </div>
        <OpcionMayoristaForm v-if="opcionMayoristaEnEdicionId === op.id"
            :alternativa-id="alternativaActiva!.id" :destino-activo-id="destinoActivoId"
            :opcion-existente="op" :tipo-mayorista-id="tipoMayoristaId"
            class="mt-2 border-top pt-2"
            @actualizado="onOpcionMayoristaGuardada" @cancelar="opcionMayoristaEnEdicionId = null" />
        <HotelesOpcionMayorista :op="op" />
        <OpcionalesOpcionMayorista :op="op" />
        <!-- Simulación Panamá (04-sep-2026) — tours incluidos con itinerario
             real, distinto de "Incluye" (texto plano). Alimenta la sección
             "Itinerario" del PDF (AlternativaController::itinerarioAlternativa()). -->
        <div v-if="mostrarToursId === op.id" class="mt-2 border-top pt-2">
            <div v-if="!op.tours?.length" class="text-muted small fst-italic mb-2">Sin tours incluidos todavía — con itinerario real, salen en la sección "Itinerario" del PDF.</div>
            <div v-for="t in op.tours" :key="t.id" class="d-flex justify-content-between align-items-center border rounded p-2 mb-1">
                <span><span class="badge bg-light text-dark border me-1">Día {{ t.orden }}</span>{{ t.paquete_plantilla?.nombre ?? t.nombre }}</span>
                <div v-if="!alternativaSoloLectura" class="d-flex gap-2">
                    <!-- Un tour ad-hoc (sin paquete_plantilla_id) no tiene ficha de catálogo que editar. -->
                    <i v-if="t.paquete_plantilla_id" class="fas fa-pen text-muted" style="cursor:pointer;font-size:11px" title="Editar este tour" @click="abrirEdicionTour(t)"></i>
                    <i class="fas fa-times text-muted" style="cursor:pointer" title="Quitar este tour" @click="quitarTourIncluido(t, op)"></i>
                </div>
            </div>
            <TourIncluidoForm v-if="op.tours?.some((t) => t.id === tourEnEdicionId)"
                :opcion-mayorista-id="op.id" :destino-atractivo-id="destinoAtractivoIdActivo"
                :dia-sugerido="0" :tour-existente="op.tours?.find((t) => t.id === tourEnEdicionId) ?? null" class="mt-2 mb-2"
                @actualizado="onTourIncluidoActualizado" @cancelar="tourEnEdicionId = null" />
            <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary w-100 mt-1" @click="mostrarFormTourIncluido = mostrarFormTourIncluido === op.id ? null : op.id; tourEnEdicionId = null">
                <i class="fas fa-plus me-1"></i>Agregar tour nuevo
            </button>
            <TourIncluidoForm v-if="mostrarFormTourIncluido === op.id"
                :opcion-mayorista-id="op.id" :destino-atractivo-id="destinoAtractivoIdActivo"
                :dia-sugerido="(op.tours?.length ?? 0) + 1" class="mt-2"
                @agregado="(t) => onTourIncluidoAgregado(op, t)" @cancelar="mostrarFormTourIncluido = null" />
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Tarjeta de una opción de mayorista del comparador: cabecera, botones (hoteles, opcionales, tours,
 * elegir, descartar), edición, HotelesOpcionMayorista, OpcionalesOpcionMayorista y tours incluidos. El
 * v-for quedó en el tag. Todo llega por inject desde cotizador/editar.vue; por prop, solo la opción
 * del bucle (T-105).
 */
import OpcionMayoristaForm from '@/components/AgenciaViajes/OpcionMayoristaForm.vue';
import TourIncluidoForm from '@/components/AgenciaViajes/TourIncluidoForm.vue';
import { textoPlano } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import {
    CLAVE_NUCLEO, CLAVE_DESTINOS, CLAVE_OPCIONES_MAYORISTA, CLAVE_OPCIONALES_MAYORISTA, CLAVE_PAGINA,
    inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';
import HotelesOpcionMayorista from '@/components/AgenciaViajes/cotizador/HotelesOpcionMayorista.vue';
import OpcionalesOpcionMayorista from '@/components/AgenciaViajes/cotizador/OpcionalesOpcionMayorista.vue';
import type { OpcionMayorista } from '@/types/agencia-viajes';

defineProps<{ op: OpcionMayorista }>();
const { alternativaActiva, alternativaSoloLectura } = inyectar(CLAVE_NUCLEO);
const { destinoActivoId, destinoAtractivoIdActivo } = inyectar(CLAVE_DESTINOS);
const {
    abrirFormEditarOpcionMayorista, descartandoOpcionId, descartarOpcion, elegirOpcion, eligiendoOpcionId,
    eliminandoOpcionMayoristaId, eliminarOpcionMayorista, onOpcionMayoristaGuardada,
    opcionMayoristaEnEdicionId, reactivandoOpcionId, reactivarOpcion, verHoteles,
} = inyectar(CLAVE_OPCIONES_MAYORISTA);
const {
    abrirEdicionTour, mostrarFormTourIncluido, mostrarToursId, onTourIncluidoActualizado,
    onTourIncluidoAgregado, quitarTourIncluido, tourEnEdicionId, verOpcionales, verTours,
} = inyectar(CLAVE_OPCIONALES_MAYORISTA);
const { tipoMayoristaId } = inyectar(CLAVE_PAGINA);
</script>

<template>
    <template v-if="alternativaActiva">
        <!-- Fila 13, fase 1b — línea de tour (modo nuevo): una carga =
             un tour, con una línea por grupo de IGV. Precio del tour
             completo editable; se mueve y se quita entera. -->
        <template v-if="bloque.carga">
            <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                <span class="small fw-semibold text-dark">
                    <i class="fas fa-route me-1 text-primary"></i>{{ bloque.tourNombre ?? 'Tour' }}
                    <span v-if="bloque.comboNombre" class="badge bg-primary-subtle text-primary ms-1" style="font-size:10px"><i class="fas fa-layer-group me-1"></i>Combo {{ bloque.comboNombre }}</span>
                    <span class="badge ms-1" :class="temporadaDeCarga(bloque) ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-dark border'" style="font-size:10px"
                        :title="bloque.items[0]?.tour_linea?.fecha_tour ? `Día del tour: ${formatFecha(bloque.items[0].tour_linea.fecha_tour)}` : 'La cotización no tiene fecha de viaje'">
                        <i class="fas fa-calendar-alt me-1"></i>{{ temporadaDeCarga(bloque) ?? 'Precio base' }}
                    </span>
                    <span v-if="bloque.items.some((i) => i.precio_manual)" class="badge bg-secondary-subtle text-secondary ms-1" style="font-size:10px"><i class="fas fa-pen me-1"></i>Precio a mano</span>
                </span>
                <span class="d-flex align-items-center gap-2">
                    <select v-if="mostrarTabsDia && !lineaTourBloqueada(bloque)" class="form-select form-select-sm" style="width:auto;font-size:11px" :value="diaActivo" @change="onMoverCarga(bloque, $event)">
                        <option v-for="d in diasCreados" :key="d" :value="d">{{ bloque.cargaCombo ? 'Mover combo a' : 'Mover a' }} Día {{ d }}</option>
                        <option :value="diaSiguiente">Mover a Día {{ diaSiguiente }} (nuevo)</option>
                    </select>
                    <span v-if="eliminandoCarga === bloque.carga" class="spinner-border spinner-border-sm text-danger"></span>
                    <i v-else-if="!lineaTourBloqueada(bloque)" class="fas fa-trash text-danger" style="cursor:pointer" title="Quitar el tour" @click="eliminarCargaTour(bloque)"></i>
                </span>
            </div>
            <div class="canvas-item border rounded p-2 small bg-white">
                <div v-if="desglosePrecioTour(bloque)" class="text-muted" style="font-size:11px">
                    <i class="fas fa-users me-1"></i>{{ desglosePrecioTour(bloque) }}
                </div>
                <div class="d-flex align-items-end flex-wrap gap-2 mt-1">
                    <div class="d-flex flex-column">
                        <label class="form-label mb-0 text-muted" style="font-size:10px">Precio del tour</label>
                        <div class="input-group input-group-sm" style="width:auto">
                            <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                            <input type="number" step="0.01" min="0" class="form-control" style="max-width:120px"
                                :value="borradorPrecioCarga[bloque.carga ?? ''] ?? subtotalGrupo(bloque.items).toFixed(2)" :disabled="lineaTourBloqueada(bloque) || guardandoPrecioCarga === bloque.carga"
                                @input="borradorPrecioCarga[bloque.carga ?? ''] = ($event.target as HTMLInputElement).value"
                                @change="onPrecioCarga(bloque, $event)">
                            <span v-if="guardandoPrecioCarga === bloque.carga" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                        </div>
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <span v-for="linea in bloque.items" :key="linea.id" class="d-flex align-items-center gap-1" style="font-size:11px">
                            <span v-if="badgeTratamientoTributario(linea)" class="badge" :class="badgeTratamientoTributario(linea)!.clase" style="font-size:10px">{{ badgeTratamientoTributario(linea)!.texto }}</span>
                            <span v-if="bloque.items.length > 1" class="text-muted">{{ alternativaActiva.moneda_cotizacion }} {{ totalConvertidoLocal(linea).toFixed(2) }}</span>
                        </span>
                    </div>
                </div>
                <a v-if="incluyeDeCarga(bloque).length" href="#" class="d-inline-block mt-1" style="font-size:11px" @click.prevent="toggleIncluye(bloque.carga)">
                    <i class="fas me-1" :class="incluyeAbierto.has(bloque.carga) ? 'fa-chevron-up' : 'fa-chevron-down'"></i>Incluye ({{ incluyeDeCarga(bloque).length }})
                </a>
                <ul v-if="incluyeAbierto.has(bloque.carga)" class="list-unstyled mb-0 mt-1 text-muted" style="font-size:11px">
                    <li v-for="servicio in incluyeDeCarga(bloque)" :key="servicio.paquete_plantilla_item_id">
                        <i class="fas fa-check me-1 text-success"></i>{{ servicio.servicio }}
                        <span v-if="servicio.costo_persona != null"> · costo {{ bloque.items[0].moneda_costo }} {{ Number(servicio.costo_persona).toFixed(2) }}/pax</span>
                    </li>
                </ul>
                <span v-if="lineaTourBloqueada(bloque)" class="d-block text-muted fst-italic mt-1" style="font-size:11px"><i class="fas fa-lock me-1"></i>Ya tiene una reserva generada</span>
            </div>
        </template>

        <div v-if="bloque.tourOrigenId && !bloque.carga" class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold text-dark"><i class="fas fa-route me-1 text-primary"></i>{{ bloque.tourNombre ?? 'Tour' }}</span>
            <span class="d-flex align-items-center gap-2">
                <select v-if="mostrarTabsDia && !alternativaSoloLectura" class="form-select form-select-sm" style="width:auto;font-size:11px" :value="diaActivo" @change="onMoverBloque(bloque, $event)">
                    <option v-for="d in diasCreados" :key="d" :value="d">Mover a Día {{ d }}</option>
                    <option :value="diaSiguiente">Mover a Día {{ diaSiguiente }} (nuevo)</option>
                </select>
                <span v-if="eliminandoBloqueId === bloque.tourOrigenId" class="spinner-border spinner-border-sm text-danger"></span>
                <i v-else-if="!alternativaSoloLectura" class="fas fa-trash text-danger" style="cursor:pointer" title="Eliminar todo el tour" @click="eliminarBloque(bloque)"></i>
            </span>
        </div>
    </template>
</template>

<script setup lang="ts">
/*
 * Un bloque de tour del lienzo: la línea de tour (precio del tour entero, mover, quitar, incluye) o la
 * cabecera de un tour armado con ítems sueltos. El template de raíz con v-if solo estrecha el tipo de
 * alternativaActiva (la página ya lo garantiza). Todo llega por inject desde cotizador/editar.vue; por
 * prop, solo el bloque del bucle (T-105).
 */
import { badgeTratamientoTributario } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import { formatFecha } from '@/helpers/fecha';
import {
    CLAVE_NUCLEO, CLAVE_PRECIOS, CLAVE_DIAS, CLAVE_LINEA_TOUR, CLAVE_BLOQUES, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';
import type { BloqueItem } from '@/helpers/agencia-viajes/cotizador/bloquesLienzo';

defineProps<{ bloque: BloqueItem }>();
const { alternativaActiva, alternativaSoloLectura } = inyectar(CLAVE_NUCLEO);
const { subtotalGrupo, totalConvertidoLocal } = inyectar(CLAVE_PRECIOS);
const { diaActivo, diaSiguiente, diasCreados, mostrarTabsDia } = inyectar(CLAVE_DIAS);
const {
    borradorPrecioCarga, desglosePrecioTour, eliminandoCarga, eliminarCargaTour, guardandoPrecioCarga,
    incluyeAbierto, incluyeDeCarga, lineaTourBloqueada, onMoverCarga, onPrecioCarga, temporadaDeCarga,
    toggleIncluye,
} = inyectar(CLAVE_LINEA_TOUR);
const { eliminandoBloqueId, eliminarBloque, onMoverBloque } = inyectar(CLAVE_BLOQUES);
</script>

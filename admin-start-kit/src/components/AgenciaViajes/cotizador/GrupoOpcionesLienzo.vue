<template>
    <!-- Sesión M4 — grupo "comparar varias opciones": una tarjeta
         compacta con las N opciones y el botón de resolución, en
         vez de N tarjetas de ítem sueltas (ver filasDelBloque()). -->
    <div v-if="alternativaActiva" class="canvas-item border rounded p-2 mb-2 small" :class="{ 'border-warning': !fila.items.some((i) => i.opcion_elegida) }">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-semibold">
                <i class="fas fa-layer-group me-2 text-primary"></i>{{ fila.items.length }} opciones de hotel
                <span v-if="nombreMayoristaDeGrupo(fila.items)" class="text-muted fw-normal"> — {{ nombreMayoristaDeGrupo(fila.items) }}</span>
            </span>
            <span class="d-flex align-items-center gap-2">
                <span v-if="!fila.items.some((i) => i.opcion_elegida)" class="badge bg-warning-subtle text-warning-emphasis" style="font-size:10px">
                    <i class="fas fa-triangle-exclamation me-1"></i>Sin resolver
                </span>
                <span v-if="eliminandoGrupoId === fila.grupoId" class="spinner-border spinner-border-sm text-danger"></span>
                <i v-else-if="!alternativaSoloLectura" class="fas fa-trash text-danger" style="cursor:pointer;font-size:11px" title="Eliminar todo este bloque" @click="eliminarGrupoLienzo(fila)"></i>
            </span>
        </div>
        <div v-for="it in fila.items" :key="it.id" class="d-flex justify-content-between align-items-center py-1 border-top" :data-item-id="it.id" :class="[{ 'opacity-50': !it.opcion_elegida }, claseResaltado([it.id])]">
            <span>
                <i class="fas fa-bed me-1 text-primary"></i>{{ etiquetaItem(it) }}<span v-if="textoAdultosMayorista(it)" class="text-muted"> {{ textoAdultosMayorista(it) }}</span>
                <span class="text-muted"> — {{ alternativaActiva.moneda_cotizacion }} {{ Number(it.precio_convertido).toFixed(2) }}</span>
            </span>
            <span class="d-flex align-items-center gap-2">
                <span v-if="it.opcion_elegida" class="badge bg-success-subtle text-success" style="font-size:10px"><i class="fas fa-check me-1"></i>Elegida</span>
                <button v-else-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-success py-0" style="font-size:11px" @click="elegirGrupoDesdeLienzo(it)" :disabled="eligiendoGrupoItemId === it.id">
                    <span v-if="eligiendoGrupoItemId === it.id" class="spinner-border spinner-border-sm"></span><span v-else>Marcar como elegida</span>
                </button>
                <span v-if="eliminandoItemId === it.id" class="spinner-border spinner-border-sm text-danger"></span>
                <i v-else-if="!alternativaSoloLectura" class="fas fa-times text-danger" style="cursor:pointer" title="Eliminar" @click="eliminarItem(it)"></i>
            </span>
        </div>
        <!-- Pedido del usuario (05-sep-2026): el itinerario incluido
             (OpcionMayoristaTour, con su "Día") solo se veía si el
             vendedor entraba a la pestaña "Tours" del drawer — acá
             queda visible siempre, sin precio (ya está prorrateado en
             el paquete, no es un ítem propio). -->
        <div v-if="toursDeGrupo(fila.items).length" class="border-top pt-1 mt-1">
            <div v-for="t in toursDeGrupo(fila.items)" :key="t.id" class="text-muted" style="font-size:11px">
                <i class="fas fa-route me-1 text-primary"></i>Día {{ t.orden }} · {{ t.paquete_plantilla?.nombre ?? t.nombre }}
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Grupo «comparar varias opciones» del lienzo: una tarjeta con las N opciones y su resolución. El v-if
 * de la raíz solo estrecha el tipo de alternativaActiva (la página ya lo garantiza; el
 * v-if="fila.esGrupo" quedó en el tag). Todo llega por inject desde cotizador/editar.vue; por prop,
 * solo la fila del bucle (T-105).
 */
import { etiquetaItem, nombreMayoristaDeGrupo, toursDeGrupo } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import {
    CLAVE_NUCLEO, CLAVE_PASAJEROS, CLAVE_BLOQUES, CLAVE_MODIFICACION, CLAVE_ITEMS_SUELTOS, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';
import type { FilaLienzo } from '@/helpers/agencia-viajes/cotizador/bloquesLienzo';

defineProps<{ fila: Extract<FilaLienzo, { esGrupo: true }> }>();
const { alternativaActiva, alternativaSoloLectura } = inyectar(CLAVE_NUCLEO);
const { textoAdultosMayorista } = inyectar(CLAVE_PASAJEROS);
const { elegirGrupoDesdeLienzo, eligiendoGrupoItemId } = inyectar(CLAVE_BLOQUES);
const { claseResaltado } = inyectar(CLAVE_MODIFICACION);
const { eliminandoGrupoId, eliminandoItemId, eliminarGrupoLienzo, eliminarItem } = inyectar(CLAVE_ITEMS_SUELTOS);
</script>

<style scoped>
.resaltado-item { box-shadow: 0 0 0 3px rgba(255, 193, 7, .6); transition: box-shadow .3s; }
</style>

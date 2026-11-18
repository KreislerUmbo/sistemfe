<template>
    <div v-if="alternativaActiva" class="canvas-item border rounded p-2 mb-2 small" :data-item-id="item.id" :class="claseResaltado([item.id])">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <span class="canvas-item-nombre">
                <i class="fas me-2 text-primary" :class="iconoItem(item)"></i>
                {{ etiquetaItem(item) }}
                <span v-if="textoAdultosMayorista(item)" class="text-muted"> {{ textoAdultosMayorista(item) }}</span>
                <span v-else-if="item.cantidad > 1 && item.modo_precio === 'tarifa_fija'" class="text-muted"> × {{ item.cantidad }}</span>
                <span v-if="idsParaRevisar.has(item.id)" class="badge bg-warning text-dark ms-2" style="font-size:10px">Para revisar</span>
                <button v-if="idsParaRevisarConfirmables.has(item.id)" type="button" class="btn btn-primary btn-sm py-0 ms-1" style="font-size:11px"
                    @click="confirmarRevisados([item.id])">Revisado</button>
            </span>
            <span v-if="eliminandoItemId === item.id" class="spinner-border spinner-border-sm text-danger flex-shrink-0"></span>
            <i v-else-if="!alternativaSoloLectura" class="fas fa-times text-danger flex-shrink-0" style="cursor:pointer" title="Eliminar" @click="eliminarItem(item)"></i>
        </div>

        <!-- Análisis de impuestos (28-ago-2026) — badge de tratamiento
             tributario resuelto, visible antes de facturar (no solo al
             enterarse en el momento de facturar la reserva). -->
        <span v-if="badgeTratamientoTributario(item)" class="badge mt-1"
            :class="badgeTratamientoTributario(item)!.clase" style="font-size:10px">
            {{ badgeTratamientoTributario(item)!.texto }}
        </span>

        <!-- Mismo itinerario incluido que el bloque de grupo de arriba,
             para un ítem de mayorista SUELTO (sin comparador de hoteles). -->
        <div v-if="item.origen_tipo === 'mayorista' && toursDeGrupo([item]).length" class="border-top pt-1 mt-1">
            <div v-for="t in toursDeGrupo([item])" :key="t.id" class="text-muted" style="font-size:11px">
                <i class="fas fa-route me-1 text-primary"></i>Día {{ t.orden }} · {{ t.paquete_plantilla?.nombre ?? t.nombre }}
            </div>
        </div>

        <div v-if="item.origen_tipo === 'manual'" class="mt-1 d-flex align-items-center gap-2 flex-wrap">
            <span v-if="item.proveedor_promovido_id" class="badge bg-success-subtle text-success" style="font-size:10px">
                <i class="fas fa-check me-1"></i>Proveedor creado
            </span>
            <template v-else>
                <span class="badge" :class="item.proveedor_sugerido_manual ? 'bg-warning-subtle text-warning-emphasis' : 'bg-danger-subtle text-danger'" style="font-size:10px">
                    <i class="fas" :class="item.proveedor_sugerido_manual ? 'fa-pen' : 'fa-triangle-exclamation'"></i>
                    Manual · {{ item.proveedor_sugerido_manual || 'sin proveedor asociado' }}
                </span>
                <a href="#" style="font-size:11px" @click.prevent="abrirPromoverProveedor(item)">
                    <i class="fas fa-arrow-up-right-from-square me-1"></i>Promover a proveedor
                </a>
            </template>
            <a v-if="!item.reserva_item && !alternativaSoloLectura" href="#" style="font-size:11px" class="text-secondary" @click.prevent="abrirEdicionManual(item)">
                <i class="fas fa-pencil me-1"></i>Editar
            </a>
            <a v-else href="#" style="font-size:11px" class="text-secondary" title="Ya tiene una reserva generada: solo se puede consultar" @click.prevent="abrirEdicionManual(item)">
                <i class="fas fa-eye me-1"></i>Ver detalle
            </a>
        </div>

        <div v-if="desglosePasajerosTexto(item)" class="text-muted mt-1" style="font-size:11px">
            <i class="fas fa-users me-1"></i>{{ desglosePasajerosTexto(item) }}
        </div>
        <div class="text-muted mt-1 d-flex align-items-center gap-2" style="font-size:11px" v-if="item.origen_tipo === 'pasaje_aereo' && item.cotizacion_pasaje_aereo">
            <span>{{ item.cotizacion_pasaje_aereo.aerolinea }}</span>
            <!-- Vigencia de la tarifa aérea (09-oct-2026): avisar antes de
                 reenviar al cliente un precio que la aerolínea ya no sostiene. -->
            <template v-if="item.cotizacion_pasaje_aereo.tarifa_valida_hasta && !item.reserva_item">
                <span v-if="tarifaAereaVencida(item.cotizacion_pasaje_aereo.tarifa_valida_hasta)" class="badge bg-danger-subtle text-danger">
                    <i class="fas fa-exclamation-triangle me-1"></i>Tarifa vencida ({{ formatFechaHora(item.cotizacion_pasaje_aereo.tarifa_valida_hasta) }})
                </span>
                <span v-else>
                    <i class="far fa-clock me-1"></i>Tarifa válida hasta {{ formatFechaHora(item.cotizacion_pasaje_aereo.tarifa_valida_hasta) }}
                </span>
            </template>
            <a v-if="!item.reserva_item && !alternativaSoloLectura" href="#" class="text-secondary" @click.prevent="abrirEdicionPasajeAereo(item)">
                <i class="fas fa-pencil me-1"></i>Editar
            </a>
            <a v-else href="#" class="text-secondary" title="Ya tiene una reserva generada: solo se puede consultar" @click.prevent="abrirEdicionPasajeAereo(item)">
                <i class="fas fa-eye me-1"></i>Ver detalle
            </a>
        </div>

        <div class="d-flex align-items-center flex-wrap gap-2 mt-2">
            <!-- Punto B (Sesión 11i) — un único input, según configuracion_agencia. -->
            <div v-if="configAgencia && !configAgencia.permitir_descuento_item" class="d-flex flex-column">
                <label class="form-label mb-0 text-muted" style="font-size:10px">Precio de venta</label>
                <div class="input-group input-group-sm" style="width:auto">
                    <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                    <input type="number" step="0.01" class="form-control"
                        style="max-width:110px"
                        :class="{ 'border-danger text-danger': alertasPiso[item.id] }"
                        v-model.number="edicionItems[item.id].precio_convertido" :disabled="alternativaSoloLectura" @input="onEditarPrecio(item)">
                    <span v-if="guardandoEdicion.includes(item.id)" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                </div>
            </div>
            <div v-else-if="configAgencia?.modo_descuento_item === 'monto'" class="d-flex flex-column">
                <label class="form-label mb-0 text-muted" style="font-size:10px">Descuento</label>
                <div class="input-group input-group-sm" style="width:auto">
                    <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                    <input type="number" step="0.01" min="0" class="form-control"
                        style="max-width:110px"
                        :class="{ 'border-danger text-danger': alertasPiso[item.id] }"
                        v-model.number="edicionItems[item.id].monto_descuento" :disabled="alternativaSoloLectura" @input="onEditarMontoDescuentoItem(item)">
                    <span v-if="guardandoEdicion.includes(item.id)" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                </div>
            </div>
            <div v-else class="d-flex flex-column">
                <label class="form-label mb-0 text-muted" style="font-size:10px">Descuento</label>
                <div class="input-group input-group-sm" style="width:auto">
                    <input type="number" step="0.01" min="0" max="100" class="form-control"
                        style="max-width:80px"
                        :class="{ 'border-danger text-danger': alertasPiso[item.id] }"
                        v-model.number="edicionItems[item.id].descuento_pct" :disabled="alternativaSoloLectura" @input="onEditarDescuentoPct(item)">
                    <span v-if="guardandoEdicion.includes(item.id)" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                    <span class="input-group-text">%</span>
                </div>
            </div>
            <!-- Badge de % efectivo — solo tiene sentido cuando el input primario NO
                 es el % mismo (sería redundante), y solo si permitir_descuento_item
                 (en modo "Precio de venta" el punto B pide explícitamente que no se
                 muestre lenguaje de descuento). -->
            <span v-if="configAgencia?.permitir_descuento_item && configAgencia?.modo_descuento_item === 'monto' && Number(edicionItems[item.id]?.descuento_pct) > 0"
                class="badge bg-light text-dark border" style="font-size:10px">
                -{{ Number(edicionItems[item.id].descuento_pct).toFixed(0) }}%
            </span>
            <select v-if="!bloque.tourOrigenId && mostrarTabsDia" :disabled="alternativaSoloLectura" class="form-select form-select-sm ms-auto" style="width:auto;font-size:11px" :value="item.dia_referencial ?? ''" @change="onReasignarDiaItem(item, $event)">
                <option value="" disabled>Sin día</option>
                <option v-for="d in diasCreados" :key="d" :value="d">Día {{ d }}</option>
            </select>
        </div>
        <small v-if="alertasPiso[item.id]" class="text-danger d-block mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Por debajo del piso permitido</small>
    </div>
</template>

<script setup lang="ts">
/*
 * Un ítem suelto del lienzo: nombre, badges, precio y descuentos editables, día, acciones y avisos. El
 * v-if de la raíz solo estrecha el tipo de alternativaActiva (la página ya lo garantiza; el v-else
 * quedó en el tag). Todo llega por inject desde cotizador/editar.vue; por prop, solo el ítem y su
 * bloque, los datos del bucle (T-105).
 */
import { badgeTratamientoTributario, etiquetaItem, iconoItem, tarifaAereaVencida, toursDeGrupo } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import { formatFechaHora } from '@/helpers/fecha';
import {
    CLAVE_NUCLEO, CLAVE_PRECIOS, CLAVE_PASAJEROS, CLAVE_DIAS, CLAVE_BLOQUES, CLAVE_MODIFICACION,
    CLAVE_ITEMS_SUELTOS, CLAVE_PAGINA, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';
import type { AlternativaItem } from '@/types/agencia-viajes';
import type { BloqueItem } from '@/helpers/agencia-viajes/cotizador/bloquesLienzo';

defineProps<{ item: AlternativaItem; bloque: BloqueItem }>();
const { alternativaActiva, alternativaSoloLectura, configAgencia } = inyectar(CLAVE_NUCLEO);
const {
    alertasPiso, edicionItems, guardandoEdicion, onEditarDescuentoPct, onEditarMontoDescuentoItem,
    onEditarPrecio,
} = inyectar(CLAVE_PRECIOS);
const { textoAdultosMayorista } = inyectar(CLAVE_PASAJEROS);
const { diasCreados, mostrarTabsDia } = inyectar(CLAVE_DIAS);
const { onReasignarDiaItem } = inyectar(CLAVE_BLOQUES);
const { claseResaltado, confirmarRevisados, idsParaRevisar, idsParaRevisarConfirmables } = inyectar(CLAVE_MODIFICACION);
const {
    abrirEdicionManual, abrirEdicionPasajeAereo, abrirPromoverProveedor, eliminandoItemId, eliminarItem,
} = inyectar(CLAVE_ITEMS_SUELTOS);
const { desglosePasajerosTexto } = inyectar(CLAVE_PAGINA);
</script>

<style scoped>
.canvas-item-nombre { white-space: normal; word-break: break-word; }
.resaltado-item { box-shadow: 0 0 0 3px rgba(255, 193, 7, .6); transition: box-shadow .3s; }
</style>

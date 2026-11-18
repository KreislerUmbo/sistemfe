<template>
    <div v-if="opcionHotelesActivaId === op.id" class="mt-2 border-top pt-2">
        <div v-if="op.estado !== 'elegida' && !alternativaSoloLectura" class="text-muted small fst-italic mb-1">Marca esta opción como elegida para poder confirmar una habitación (puedes seguir cargando hoteles mientras tanto).</div>
        <!-- 04-sep-2026 — una sola vez arriba de todo, en vez de metida
             solo en el form de "crear hotel nuevo" (ahí el usuario la veía
             al crear, pero no al editar/agregar un tipo de habitación desde
             la tabla — el mismo error de duplicación/inconsistencia que ya
             se corrigió una vez con la lista de hoteles repetida). Cubre los
             3 lugares donde aparecen Costo/Venta acá abajo: ver precios,
             editar una tarifa, agregar una tarifa nueva a un hotel ya
             cargado, y el form de crear hotel. -->
        <p class="text-muted fst-italic mb-2" style="font-size:11px">El costo/venta de cada tipo de habitación es el total del paquete por persona (aéreo + tours + hotel según este mayorista), no solo el hospedaje. Si el hotel es nuevo, no hace falta registrarle una tarifa en Proveedores para reusarlo — elígelo del selector de abajo (o déjalo "manual/referencial") y carga este precio de paquete directo acá.</p>
        <!-- 04-sep-2026 — vista unificada (hallazgo del usuario: la tabla de
             "elegir" y la lista de gestión de abajo mostraban los mismos
             hoteles dos veces, en dos formatos). permitir-gestion-hoteles
             agrupa las filas por hotel con edición/borrado inline, en el
             mismo lugar donde ya se elige la habitación. -->
        <p class="small text-muted mb-1"><strong>Adultos:</strong> precio por adulto. Los niños no están incluidos: si el mayorista los cobra, agrégalos como servicio aparte.</p>
        <HabitacionMatrixPicker
            :tarifas="tarifasHotelPlanas(op)" :moneda="op.moneda"
            cantidad-label="Adultos" :cantidad-default="cantidadAdultosCotizacion"
            :deshabilitar-confirmar="op.estado !== 'elegida' || alternativaSoloLectura"
            :motivo-deshabilitado="alternativaSoloLectura ? 'Esta alternativa ya está reservada: los cambios se hacen con Modificar reserva' : 'Marca esta opción como elegida para poder confirmar una habitación'"
            :permitir-gestion-hoteles="!alternativaSoloLectura"
            @seleccionar="({ id, cantidad }) => agregarItemMayorista(op, id, cantidad)"
            @agregar-grupo="({ ids }) => agregarGrupoMayorista(op, ids)"
            @guardar-hotel="onGuardarHotelMatrix"
            @eliminar-hotel="onEliminarHotelMatrix"
            @agregar-fotos-hotel="onAgregarFotosHotelMatrix"
            @eliminar-foto-hotel="onEliminarFotoHotelMatrix"
            @promover-hotel="onPromoverHotelMatrix"
            @guardar-tarifa="onGuardarTarifaMatrix"
            @eliminar-tarifa="onEliminarTarifaMatrix"
            @agregar-tarifa="onAgregarTarifaMatrix" />

        <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary w-100 mt-2" @click="mostrarFormHotel = mostrarFormHotel === op.id ? null : op.id">
            <i class="fas fa-plus me-1"></i>Agregar hotel a esta opción
        </button>
        <div v-if="mostrarFormHotel === op.id && !alternativaSoloLectura" class="border rounded p-2 mt-2">
            <label class="form-label mb-1 small text-secondary">Nombre del hotel</label>
            <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre del hotel" v-model="formHotel.nombre_hotel">
            <label class="form-label mb-1 small text-secondary">Proveedor</label>
            <ProveedorBuscador v-model="formHotel.proveedor_id" :tipo-id="tipoHotelId" placeholder="Hotel manual o buscar proveedor" @elegido="onCambiarProveedorHotel" />
            <!-- Nota de costo/venta ya no va acá — quedó una sola vez arriba
                 de toda la tabla (04-sep-2026), cubre también este form. -->
            <!-- Encabezados de columna — cumplen el rol de label para cada
                 fila de tarifas sin repetir el texto en cada una. -->
            <div class="row g-1 mb-1">
                <div class="col-3"><label class="form-label mb-0 small text-secondary">Tipo de habitación</label></div>
                <div class="col-3" v-if="formHotel.proveedor_id"><label class="form-label mb-0 small text-secondary">Tarifa registrada</label></div>
                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'"><label class="form-label mb-0 small text-secondary">Costo total paquete</label></div>
                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'"><label class="form-label mb-0 small text-secondary">Venta total paquete</label></div>
            </div>
            <div v-for="(tf, idx) in formHotel.tarifas" :key="idx" class="row g-1 mb-1">
                <div class="col-3">
                    <select class="form-select form-select-sm" v-model="tf.tipo_habitacion" @change="tf.proveedor_tarifa_id = null">
                        <option value="simple">Simple</option>
                        <option value="matrimonial">Matrimonial</option>
                        <option value="doble">Doble</option>
                        <option value="triple">Triple</option>
                        <option value="familiar">Familiar</option>
                    </select>
                </div>
                <div class="col-3" v-if="formHotel.proveedor_id">
                    <select class="form-select form-select-sm" :value="tf.proveedor_tarifa_id ?? ''" @change="onElegirTarifaRegistrada(tf, $event)">
                        <option value="">Manual</option>
                        <option v-for="t in tarifasHotelParaTipo(tf.tipo_habitacion)" :key="t.id" :value="t.id">Registrada ({{ t.precio_venta_adulto }})</option>
                    </select>
                </div>
                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'">
                    <input type="number" class="form-control form-control-sm" placeholder="Costo" v-model.number="tf.precio_costo" :readonly="!!tf.proveedor_tarifa_id">
                </div>
                <div :class="formHotel.proveedor_id ? 'col-3' : 'col-4'">
                    <input type="number" class="form-control form-control-sm" placeholder="Venta" v-model.number="tf.precio_venta" :readonly="!!tf.proveedor_tarifa_id">
                </div>
            </div>
            <button class="btn btn-sm btn-outline-secondary mb-1" @click="formHotel.tarifas.push({ tipo_habitacion: 'doble', precio_costo: 0, precio_venta: 0, proveedor_tarifa_id: null })">+ tipo de habitación</button>

            <!-- Pedido del usuario (05-sep-2026): hasta 3 fotos por hotel
                 (1 fachada + 2 habitación desde la mejora del PDF de
                 cotización — el PDF necesita distinguirlas). -->
            <label class="form-label mb-1 small text-secondary d-block">Fotos (opcional, máx. 1 fachada + 2 habitación)</label>
            <div v-if="fotosHotelNuevo.length" class="d-flex flex-wrap gap-1 mb-1">
                <div v-for="(foto, idx) in fotosHotelNuevo" :key="idx" class="position-relative">
                    <img :src="foto.previewUrl" style="width:50px;height:50px;object-fit:cover;border:1px solid #ccc;border-radius:3px;">
                    <select class="form-select form-select-sm position-absolute" style="bottom:-4px;left:0;right:0;font-size:8px;padding:0;height:14px;" v-model="foto.tipoFoto">
                        <option value="fachada">Fachada</option>
                        <option value="habitacion">Hab.</option>
                    </select>
                    <i class="fas fa-times-circle text-danger position-absolute" style="top:-6px;right:-6px;cursor:pointer;background:#fff;border-radius:50%" title="Quitar" @click="quitarFotoHotelNuevo(idx)"></i>
                </div>
            </div>
            <input type="file" accept="image/*" multiple class="form-control form-control-sm mb-1" :disabled="fotosHotelNuevo.length >= 3" @change="onFotosHotelNuevoSeleccionadas">

            <button class="btn btn-sm btn-primary w-100" @click="guardarHotel(op)" :disabled="guardandoHotelMayorista">
                <span v-if="guardandoHotelMayorista" class="spinner-border spinner-border-sm me-1"></span>Guardar hotel
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Hoteles de una opción de mayorista: matriz de habitaciones y formulario de hotel nuevo (con tarifa
 * registrada o fotos). Todo llega por inject desde cotizador/editar.vue; por prop, solo la opción del
 * bucle (T-105).
 */
import HabitacionMatrixPicker from '@/components/AgenciaViajes/HabitacionMatrixPicker.vue';
import ProveedorBuscador from '@/components/AgenciaViajes/ProveedorBuscador.vue';
import {
    CLAVE_NUCLEO, CLAVE_PASAJEROS, CLAVE_OPCIONES_MAYORISTA, CLAVE_HOTELES_MAYORISTA, CLAVE_PAGINA, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';
import type { OpcionMayorista } from '@/types/agencia-viajes';

defineProps<{ op: OpcionMayorista }>();
const { alternativaSoloLectura } = inyectar(CLAVE_NUCLEO);
const { cantidadAdultosCotizacion } = inyectar(CLAVE_PASAJEROS);
const { agregarGrupoMayorista, agregarItemMayorista, opcionHotelesActivaId } = inyectar(CLAVE_OPCIONES_MAYORISTA);
const {
    formHotel, fotosHotelNuevo, guardandoHotelMayorista, guardarHotel, mostrarFormHotel,
    onAgregarFotosHotelMatrix, onAgregarTarifaMatrix, onCambiarProveedorHotel, onElegirTarifaRegistrada,
    onEliminarFotoHotelMatrix, onEliminarHotelMatrix, onEliminarTarifaMatrix, onFotosHotelNuevoSeleccionadas,
    onGuardarHotelMatrix, onGuardarTarifaMatrix, onPromoverHotelMatrix, quitarFotoHotelNuevo,
    tarifasHotelParaTipo, tarifasHotelPlanas,
} = inyectar(CLAVE_HOTELES_MAYORISTA);
const { tipoHotelId } = inyectar(CLAVE_PAGINA);
</script>

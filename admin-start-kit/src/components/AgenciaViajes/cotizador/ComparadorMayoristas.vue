<template>
    <div class="d-flex flex-column gap-2">
        <OpcionMayoristaTarjeta v-for="op in opcionesMayorista" :key="op.id" :op="op" />
        <button v-if="!alternativaSoloLectura" class="btn btn-outline-primary btn-sm w-100" @click="mostrarFormMayorista = !mostrarFormMayorista; opcionMayoristaEnEdicionId = null">
            <i class="fas fa-plus me-1"></i>Agregar cotización de mayorista
        </button>
        <OpcionMayoristaForm v-if="mostrarFormMayorista && !alternativaSoloLectura"
            :alternativa-id="alternativaActiva!.id" :destino-activo-id="destinoActivoId"
            :opcion-existente="null" :tipo-mayorista-id="tipoMayoristaId"
            @agregado="onOpcionMayoristaGuardada" @cancelar="mostrarFormMayorista = false" />
    </div>
</template>

<script setup lang="ts">
/*
 * Comparador de mayoristas del drawer: una OpcionMayoristaTarjeta por opción y el formulario de opción
 * nueva. El v-else (cadena con BibliotecaLocal y BibliotecaGuia) quedó en el tag. Todo llega por
 * inject desde cotizador/editar.vue (T-105).
 */
import OpcionMayoristaForm from '@/components/AgenciaViajes/OpcionMayoristaForm.vue';
import {
    CLAVE_NUCLEO, CLAVE_DESTINOS, CLAVE_OPCIONES_MAYORISTA, CLAVE_PAGINA, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';
import OpcionMayoristaTarjeta from '@/components/AgenciaViajes/cotizador/OpcionMayoristaTarjeta.vue';

const { alternativaActiva, alternativaSoloLectura } = inyectar(CLAVE_NUCLEO);
const { destinoActivoId } = inyectar(CLAVE_DESTINOS);
const {
    mostrarFormMayorista, onOpcionMayoristaGuardada, opcionMayoristaEnEdicionId, opcionesMayorista,
} = inyectar(CLAVE_OPCIONES_MAYORISTA);
const { tipoMayoristaId } = inyectar(CLAVE_PAGINA);
</script>

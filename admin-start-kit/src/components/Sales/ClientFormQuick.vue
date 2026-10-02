<template>
  <form novalidate @submit.prevent="save">
    <ClienteDatosBase v-model="form" id="rapido" :errores="errores" />
    <small v-if="form.type_document === 'SND'" class="text-muted d-block mt-2">
      Sin documento por ahora: complétalo con "Editar cliente" cuando acepte la cotización.
    </small>

    <div class="mt-3 d-flex justify-content-end gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" @click="$emit('cancel')">Cancelar</button>
      <button type="submit" class="btn btn-sm btn-primary" :disabled="guardando">
        <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
        {{ esEdicion ? 'Actualizar cliente' : 'Guardar y seleccionar' }}
      </button>
    </div>
  </form>
</template>

<script setup lang="ts">
// Alta/edición rápida de un cliente sin salir de Ventas, Cotizador o Nuevo crédito. Los
// campos son los de la página del cliente (ClienteDatosBase, Fase 4c); los datos de crédito
// (ficha, fotos, ubicación) se completan en la página.
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import httpClient from '@/helpers/http-client';
import ClienteDatosBase from '@/components/Clientes/ClienteDatosBase.vue';
import { guardarCliente } from '@/helpers/clientes/guardarCliente';
import { aPayload, erroresDeValidacion, formDesdeCliente, formVacio, type FormCliente } from '@/helpers/clientes/formCliente';

// clienteId: si viene poblado, el form entra en modo edición (PUT sobre ese
// cliente en vez de POST uno nuevo) — usado cuando un cliente quedó mal
// registrado desde el modal rápido del cotizador y hay que corregirlo sin
// salir a /clientes.
const props = defineProps<{ initialData: any; clienteId?: number | null }>();
const emit = defineEmits(['saved', 'cancel']);
const esEdicion = computed(() => !!props.clienteId);

const form = ref<FormCliente>(formVacio());
const errores = ref<Record<string, string>>({});
const guardando = ref(false);

// Prefill de creación (initialData puede venir parcial, ej. solo el documento tipeado).
watch(() => props.initialData, (data) => {
  if (data && !esEdicion.value) form.value = formDesdeCliente(data);
}, { immediate: true });

// Modo edición: initialData puede venir parcial (ej. armado a mano desde la
// cabecera de una cotización) — se trae el registro completo del backend
// para no pisar con vacíos campos que el usuario nunca tocó al guardar.
watch(() => props.clienteId, async (id) => {
  if (!id) return;
  if (props.initialData) form.value = formDesdeCliente(props.initialData); // prefill instantáneo mientras llega el fetch
  try {
    const res = await httpClient.get(`clients/${id}`);
    form.value = formDesdeCliente(res.data.client);
  } catch (error) {
    console.error(error);
    Swal.fire('Error', 'No se pudo cargar el cliente a editar', 'error');
  }
}, { immediate: true });

const save = async () => {
  errores.value = {};
  guardando.value = true;
  try {
    // 04c: confirma nombre repetido (sin documento) y ofrece restaurar un cliente eliminado.
    const resultado = await guardarCliente(aPayload(form.value, { tributarios: false }), esEdicion.value ? props.clienteId : null);
    if (resultado.ok) emit('saved', resultado.client);
  } catch (error: any) {
    errores.value = erroresDeValidacion(error);
    if (Object.keys(errores.value).length === 0) {
      Swal.fire('Error', error.response?.data?.message || 'Error al guardar', 'error');
    }
  } finally {
    guardando.value = false;
  }
};
</script>

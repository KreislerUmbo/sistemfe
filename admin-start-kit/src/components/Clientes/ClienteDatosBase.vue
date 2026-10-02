<template>
  <div class="row g-3">
    <div v-if="completo" class="col-6 col-md-3">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-tipo-cliente`">Tipo de cliente</label>
      <select :id="`${id}-tipo-cliente`" v-model="form.type_client" class="form-select form-select-sm">
        <option value="1">Final</option>
        <option value="2">Empresa</option>
        <option value="3">Cualquiera</option>
      </select>
    </div>
    <div class="col-6" :class="completo ? 'col-md-3' : 'col-md-4'">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-tipo-doc`">Tipo de documento</label>
      <select :id="`${id}-tipo-doc`" v-model="form.type_document" class="form-select form-select-sm" :class="{ 'is-invalid': errores.type_document }">
        <option v-for="t in TIPOS_DOCUMENTO" :key="t.valor" :value="t.valor">{{ t.texto }}</option>
      </select>
    </div>
    <div v-if="form.type_document !== 'SND'" class="col-12" :class="completo ? 'col-md-6' : 'col-md-8'">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-n-doc`">N.º de documento</label>
      <div class="input-group input-group-sm has-validation">
        <input :id="`${id}-n-doc`" v-model="form.n_document" type="text" class="form-control" :class="{ 'is-invalid': errores.n_document }"
          :inputmode="['DNI', 'RUC'].includes(form.type_document) ? 'numeric' : 'text'" autocomplete="off" @keyup.enter="buscar" />
        <button v-if="buscable" type="button" class="btn btn-outline-primary" :disabled="buscando || !form.n_document.trim()" @click="buscar">
          <span v-if="buscando" class="spinner-border spinner-border-sm"></span>
          <template v-else><i class="fas fa-search me-1"></i>Buscar</template>
        </button>
        <div v-if="errores.n_document" class="invalid-feedback">{{ errores.n_document }}</div>
      </div>
      <small v-if="buscable" class="text-muted">Busca en {{ form.type_document === 'DNI' ? 'RENIEC' : 'SUNAT' }} y completa los datos.</small>
    </div>

    <!-- Nombre según el tipo de documento -->
    <div v-if="form.type_document === 'RUC'" class="col-12 col-md-7">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-razon`">Razón social</label>
      <input :id="`${id}-razon`" v-model="form.full_name" type="text" class="form-control form-control-sm" :class="{ 'is-invalid': errores.full_name }" />
      <div v-if="errores.full_name" class="invalid-feedback">{{ errores.full_name }}</div>
    </div>
    <div v-if="form.type_document === 'RUC'" class="col-12 col-md-5">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-comercial`">Nombre comercial</label>
      <input :id="`${id}-comercial`" v-model="form.name_comerc" type="text" class="form-control form-control-sm" />
    </div>
    <div v-else-if="form.type_document === 'SND'" class="col-12" :class="completo ? 'col-md-6' : 'col-md-8'">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-referencia`">Nombre o referencia</label>
      <input :id="`${id}-referencia`" v-model="form.full_name" type="text" class="form-control form-control-sm" :class="{ 'is-invalid': errores.full_name }"
        placeholder='Ej. "Manuel, gerente del BCP"' />
      <div v-if="errores.full_name" class="invalid-feedback">{{ errores.full_name }}</div>
    </div>
    <template v-else>
      <div class="col-12 col-md-6">
        <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-nombres`">Nombres</label>
        <input :id="`${id}-nombres`" v-model="form.name" type="text" class="form-control form-control-sm" :class="{ 'is-invalid': errores.full_name }" autocomplete="off" />
        <div v-if="errores.full_name" class="invalid-feedback">{{ errores.full_name }}</div>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-apellidos`">Apellidos</label>
        <input :id="`${id}-apellidos`" v-model="form.surname" type="text" class="form-control form-control-sm" autocomplete="off" />
      </div>
    </template>

    <!-- Contacto -->
    <div class="col-12 col-md-4">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-telefono`">Teléfono</label>
      <input :id="`${id}-telefono`" v-model="form.phone" type="tel" inputmode="tel" class="form-control form-control-sm" :class="{ 'is-invalid': errores.phone }" />
      <div v-if="errores.phone" class="invalid-feedback">{{ errores.phone }}</div>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-correo`">Correo</label>
      <input :id="`${id}-correo`" v-model="form.email" type="email" inputmode="email" class="form-control form-control-sm" :class="{ 'is-invalid': errores.email }" />
      <div v-if="errores.email" class="invalid-feedback">{{ errores.email }}</div>
    </div>
    <template v-if="completo && esPersona">
      <div class="col-6 col-md-2">
        <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-nacimiento`">Nacimiento</label>
        <CampoFecha :id="`${id}-nacimiento`" v-model="form.birth_date" :max="hoy" etiqueta="Fecha de nacimiento" :invalido="!!errores.birth_date" />
        <div v-if="errores.birth_date" class="invalid-feedback d-block">{{ errores.birth_date }}</div>
      </div>
      <div class="col-6 col-md-2">
        <span class="form-label mb-1 small fw-semibold text-secondary d-block">Género</span>
        <div class="btn-group btn-group-sm w-100" role="group" aria-label="Género">
          <input :id="`${id}-gen-m`" v-model="form.gender" type="radio" class="btn-check" value="M" />
          <label class="btn btn-outline-secondary" :for="`${id}-gen-m`">M</label>
          <input :id="`${id}-gen-f`" v-model="form.gender" type="radio" class="btn-check" value="F" />
          <label class="btn btn-outline-secondary" :for="`${id}-gen-f`">F</label>
        </div>
      </div>
    </template>

    <!-- Dirección -->
    <div class="col-12" :class="completo ? 'col-md-8' : ''">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-direccion`">Dirección</label>
      <input :id="`${id}-direccion`" v-model="form.address" type="text" class="form-control form-control-sm" />
    </div>
    <div v-if="completo" class="col-12 col-md-4">
      <span class="form-label mb-1 small fw-semibold text-secondary d-block">Estado</span>
      <div class="btn-group btn-group-sm w-100" role="group" aria-label="Estado">
        <input :id="`${id}-activo`" v-model="form.state" type="radio" class="btn-check" :value="1" />
        <label class="btn btn-outline-success" :for="`${id}-activo`">Activo</label>
        <input :id="`${id}-inactivo`" v-model="form.state" type="radio" class="btn-check" :value="2" />
        <label class="btn btn-outline-secondary" :for="`${id}-inactivo`">Inactivo</label>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-region`">Región</label>
      <select :id="`${id}-region`" v-model="form.ubigeo_region" class="form-select form-select-sm" @change="cambiarRegion">
        <option value="">Seleccionar</option>
        <option v-for="r in regiones" :key="r.id" :value="r.id">{{ r.name }}</option>
      </select>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-provincia`">Provincia</label>
      <select :id="`${id}-provincia`" v-model="form.ubigeo_provincia" class="form-select form-select-sm" :disabled="!form.ubigeo_region"
        @change="form.ubigeo_distrito = ''">
        <option value="">Seleccionar</option>
        <option v-for="p in provincias" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-distrito`">Distrito</label>
      <select :id="`${id}-distrito`" v-model="form.ubigeo_distrito" class="form-select form-select-sm" :disabled="!form.ubigeo_provincia">
        <option value="">Seleccionar</option>
        <option v-for="d in distritos" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
    </div>
    <div v-if="form.ubigeo_region && amazonia" class="col-12">
      <small class="text-success"><i class="fas fa-tree me-1"></i>Zona Amazonía (Ley 27037), según la región.</small>
    </div>

    <!-- Datos tributarios: solo empresas y solo donde se factura -->
    <template v-if="tributarios && form.type_document === 'RUC'">
      <div class="col-12 col-md-6">
        <label class="form-label mb-1 small fw-semibold text-secondary" :for="`${id}-regimen`">Régimen tributario</label>
        <select :id="`${id}-regimen`" v-model="form.regimen_tributario" class="form-select form-select-sm">
          <option v-for="r in REGIMENES" :key="r.valor" :value="r.valor">{{ r.texto }}</option>
        </select>
      </div>
      <div class="col-12 col-md-6 d-flex align-items-end">
        <div class="form-check mb-1">
          <input :id="`${id}-retencion`" v-model="form.es_agente_retencion" type="checkbox" class="form-check-input" />
          <label class="form-check-label small" :for="`${id}-retencion`">Agente de retención</label>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
// Datos base del cliente (Fase 4c), todos los giros: los usa la página del cliente
// (completo) y el modal rápido de Ventas/Cotizador/Nuevo crédito (compacto).
import { computed, ref, watch } from 'vue'
import Swal from 'sweetalert2'
import httpClient from '@/helpers/http-client'
import CampoFecha from '@/components/CampoFecha.vue'
import { hoyEnLima } from '@/helpers/creditos/formato'
import {
  REGIMENES, TIPOS_DOCUMENTO, distritosDe, esAmazonia, listaRegiones, provinciasDe, ubigeoDesdeTexto, type FormCliente,
} from '@/helpers/clientes/formCliente'

const form = defineModel<FormCliente>({ required: true })
const props = withDefaults(defineProps<{
  /** Prefijo de los id (dos formularios en la misma pantalla no deben chocar). */
  id?: string
  errores?: Record<string, string>
  /** Página del cliente: tipo de cliente, nacimiento, género y estado. */
  completo?: boolean
  tributarios?: boolean
}>(), { id: 'cliente', errores: () => ({}), completo: false, tributarios: false })

const errores = computed(() => props.errores)
const hoy = hoyEnLima()
const regiones = listaRegiones()
const provincias = computed(() => (form.value.ubigeo_region ? provinciasDe(form.value.ubigeo_region) : []))
const distritos = computed(() => (form.value.ubigeo_provincia ? distritosDe(form.value.ubigeo_provincia) : []))
const amazonia = computed(() => esAmazonia(form.value.ubigeo_region))
const esPersona = computed(() => !['RUC', 'SND'].includes(form.value.type_document))
const buscable = computed(() => TIPOS_DOCUMENTO.find((t) => t.valor === form.value.type_document)?.buscable ?? false)
const buscando = ref(false)

function cambiarRegion() {
  form.value.ubigeo_provincia = ''
  form.value.ubigeo_distrito = ''
}

// "Sin documento" no tiene número; al volver a un tipo con número se limpia el campo.
watch(() => form.value.type_document, (tipo, anterior) => {
  if (tipo === 'SND' || anterior === 'SND') form.value.n_document = ''
})

async function buscar() {
  const numero = form.value.n_document.trim()
  if (!buscable.value || !numero || buscando.value) return
  buscando.value = true
  try {
    const tipo = form.value.type_document.toLowerCase()
    const { data } = await httpClient.get(`/search-document/${tipo}/${numero}`)
    if (data?.success === false) {
      Swal.fire('Sin resultados', data.message || 'No se encontró información para ese documento.', 'info')
      return
    }
    if (form.value.type_document === 'DNI') {
      form.value.name = data.nombres ?? ''
      form.value.surname = `${data.apellidoPaterno ?? ''} ${data.apellidoMaterno ?? ''}`.trim()
    } else {
      form.value.full_name = data.razonSocial ?? ''
      form.value.name_comerc = data.nombreComercial ?? ''
      if (data.direccion) form.value.address = data.direccion
      if (data.telefonos?.length && !form.value.phone) form.value.phone = String(data.telefonos[0])
      if (data.departamento) Object.assign(form.value, ubigeoDesdeTexto(data.departamento, data.provincia, data.distrito))
    }
  } catch (e: any) {
    const estado = e?.response?.status
    Swal.fire('No se pudo buscar', estado === 404 ? 'Documento no encontrado.' : (e?.response?.data?.message ?? 'Error al consultar el documento.'), 'warning')
  } finally {
    buscando.value = false
  }
}
</script>

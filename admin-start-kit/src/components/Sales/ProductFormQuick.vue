<template>
  <b-form @submit.prevent="save">
    <div class="row g-3">
      <!-- Nombre -->
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Nombre <span class="text-danger">*</span></label>
        <b-form-input v-model="form.title" size="sm" @input="form.title = form.title.toUpperCase()" />
      </div>
      <!-- SKU -->
      <div class="col-md-3">
        <label class="form-label small fw-semibold">SKU <span class="text-danger">*</span></label>
        <b-form-input v-model="form.sku" size="sm" @input="form.sku = form.sku.toUpperCase()" />
      </div>
      <!-- Categoría -->
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Categoría <span class="text-danger">*</span></label>
        <b-form-select v-model="form.categorie_id" size="sm">
          <option value="">Seleccionar</option>
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.title }}</option>
        </b-form-select>
      </div>

      <!-- Precios -->
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Precio Final (S/.)</label>
        <b-form-input type="number" step="0.01" v-model="form.price_general" size="sm" />
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Precio Empresa (S/.)</label>
        <b-form-input type="number" step="0.01" v-model="form.price_company" size="sm" />
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Unidad</label>
        <b-form-select v-model="form.unidad_medida" size="sm">
          <option value="NIU">Unidad (NIU)</option>
          <option value="KGM">Kilogramo</option>
          <option value="LTR">Litro</option>
          <option value="MTR">Metro</option>
          <option value="ZZ">Servicio</option>
        </b-form-select>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Stock</label>
        <b-form-input type="number" v-model="form.stock" size="sm" />
      </div>

      <!-- Descripción -->
      <div class="col-md-12">
        <label class="form-label small fw-semibold">Descripción</label>
        <b-form-textarea v-model="form.description" rows="2" size="sm" />
      </div>

      <!-- Configuraciones rápidas -->
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Incluye IGV</label>
        <div class="btn-group w-100">
          <input type="radio" class="btn-check" id="prod-igv-no" value="1" v-model="form.include_igv">
          <label class="btn btn-outline-secondary btn-sm" for="prod-igv-no">No</label>
          <input type="radio" class="btn-check" id="prod-igv-si" value="2" v-model="form.include_igv">
          <label class="btn btn-outline-secondary btn-sm" for="prod-igv-si">Sí</label>
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Afectación IGV</label>
        <b-form-select v-model="form.tip_afe_igv_default" size="sm">
          <option value="10">Gravado</option>
          <option value="20">Exonerado</option>
          <option value="30">Inafecto</option>
        </b-form-select>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Tipo</label>
        <div class="btn-group w-100">
          <input type="radio" class="btn-check" id="prod-tipo-bien" value="BIEN" v-model="form.tipo_bien_servicio">
          <label class="btn btn-outline-primary btn-sm" for="prod-tipo-bien">Bien</label>
          <input type="radio" class="btn-check" id="prod-tipo-servicio" value="SERVICIO"
            v-model="form.tipo_bien_servicio">
          <label class="btn btn-outline-primary btn-sm" for="prod-tipo-servicio">Servicio</label>
        </div>
      </div>
    </div>

    <div class="mt-3 d-flex justify-content-end gap-2">
      <b-button variant="secondary" @click="$emit('cancel')">Cancelar</b-button>
      <b-button variant="primary" type="submit">Guardar y Seleccionar</b-button>
    </div>
  </b-form>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import httpClient from '@/helpers/http-client';
import Swal from 'sweetalert2';

const is_ivap = ref(1);
const is_icbper = ref(1);
const percentage_isc = ref<number>(0);
const emit = defineEmits(['saved', 'cancel']);

const form = ref({
  title: '',
  sku: '',
  categorie_id: '',
  price_general: 0,
  price_company: 0,
  description: '',
  unidad_medida: 'NIU',
  stock: 0,
  include_igv: 1,
  tip_afe_igv_default: '10',
  tipo_bien_servicio: 'BIEN',
  state: 1,
  is_discount: 1,
  disponiblidad: 1,
  is_icbper: 1,
  is_ivap: 1,
  percentage_isc: 0,
  is_especial_nota: 0,

  // otros campos por defecto
});

const categories = ref<any[]>([]);

onMounted(async () => {
  try {
    const res = await httpClient.get('products/config');
    categories.value = res.data.categories;
  } catch (error) {
    console.error(error);
  }
});

const save = async () => {
  try {
    if (!form.value.title.trim()) {
      Swal.fire('Error', 'El nombre es obligatorio', 'error');
      return;
    }
    if (!form.value.sku.trim()) {
      Swal.fire('Error', 'El SKU es obligatorio', 'error');
      return;
    }
    if (!form.value.categorie_id) {
      Swal.fire('Error', 'Selecciona una categoría', 'error');
      return;
    }

    if (form.value.tipo_bien_servicio === 'SERVICIO') {
      is_icbper.value = 1;
      is_ivap.value = 1;
      percentage_isc.value = 0;
    }

    const payload = { ...form.value };
    const res = await httpClient.post('products', payload);
    if (res.data.code === 200) {
      emit('saved', res.data.product);
    } else {
      Swal.fire('Error', res.data.message, 'error');
    }
  } catch (error: any) {
    Swal.fire('Error', error.response?.data?.message || 'Error al guardar', 'error');
  }
};
</script>
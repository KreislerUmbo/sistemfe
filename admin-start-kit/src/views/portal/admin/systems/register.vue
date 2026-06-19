<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <h4 class="mb-0">
                  <i class="fas fa-cogs me-2"></i>
                  {{ isEdit ? 'Editar' : 'Nuevo' }} Sistema
                </h4>
              </b-col>
              <b-col cols="auto">
                <b-badge :variant="form.is_active ? 'success' : 'secondary'" class="me-2">
                  {{ form.is_active ? 'Activo' : 'Inactivo' }}
                </b-badge>
                <b-button variant="outline-secondary" size="sm" @click="goBack">
                  <i class="fas fa-arrow-left me-1"></i> Volver
                </b-button>
              </b-col>
            </b-row>
          </b-card-header>

          <b-card-body>
            <b-row>
              <!-- Columna izquierda: navegación (LG+) -->
              <b-col lg="2" class="d-none d-lg-block">
                <div class="sticky-top" style="top: 20px;">
                  <div class="nav flex-column nav-pills">
                    <a v-for="item in navItems" :key="item.key" class="nav-link"
                      :class="{ active: activeSection === item.key }" href="#" @click.prevent="scrollTo(item.key)">
                      <i :class="item.icon" class="me-2"></i>{{ item.label }}
                    </a>
                  </div>
                  <div class="mt-3 p-2 bg-light rounded">
                    <div class="d-flex justify-content-between small">
                      <span>Completitud</span>
                      <strong>{{ completionPercent }}%</strong>
                    </div>
                    <b-progress :value="completionPercent" variant="success" height="6px" />
                  </div>
                </div>
              </b-col>

              <!-- Columna central: formulario -->
              <b-col lg="7">
                <!-- GENERAL -->
                <section id="sec-general" ref="sectionGeneral" class="mb-4">
                  <SystemGeneral v-model:form="form" :categories="categories" />
                </section>

                <!-- PRECIOS -->
                <!--     <section id="sec-precios" ref="sectionPrecios" class="mb-4">
                  <SystemPricing v-model:plans="form.plans" />
                </section> -->

                <!-- CARACTERÍSTICAS -->
                <!--            <section id="sec-caracteristicas" ref="sectionCaracteristicas" class="mb-4">
                  <SystemFeatures v-model:features="form.features" />
                </section> -->

                <!-- MÓDULOS -->
                <section id="sec-modulos" ref="sectionModulos" class="mb-4">
                  <SystemModules v-model:modules="form.modules" />
                </section>

                <!-- GALERÍA -->
                <!--        <section id="sec-galeria" ref="sectionGaleria" class="mb-4">
                  <SystemGallery v-model:gallery="galleryPreviews" ref="galleryRef" />
                </section> -->

                <!-- ESPECIFICACIONES -->
                <!--            <section id="sec-specs" ref="sectionSpecs" class="mb-4">
                  <SystemSpecs v-model:specs="form.specs" />
                </section> -->

                <!-- BADGE -->
                <!--                 <section id="sec-badges" ref="sectionBadges" class="mb-4">
                  <SystemBadge v-model:badge="form.badge" />
                </section> -->

                <!-- Botones de acción -->
                <div class="d-flex justify-content-end gap-2 mt-4">
                  <b-button variant="secondary" @click="goBack">Cancelar</b-button>
                  <b-button variant="success" @click="save" :disabled="loading">
                    <b-spinner v-if="loading" small />
                    <i v-else class="fas fa-save me-1"></i>
                    {{ isEdit ? 'Actualizar' : 'Guardar' }} Sistema
                  </b-button>
                </div>
              </b-col>

              <!-- Columna derecha: resumen (LG+) -->
              <b-col lg="3" class="d-none d-lg-block">
                <div class="sticky-top" style="top: 20px;">
                  <b-card>
                    <template #header>
                      <h5 class="mb-0"><i class="fas fa-eye me-2"></i>Vista Rápida</h5>
                    </template>
                    <div class="text-center">
                      <span class="display-1">{{ form.icon_emoji || '🛒' }}</span>
                      <h5>{{ form.name || 'Nombre del sistema' }}</h5>
                      <span class="text-muted">{{ categoryName }}</span>
                    </div>
                    <hr />
                    <div class="d-flex justify-content-between">
                      <span>Mensual</span>
                      <strong>S/ </strong>
                    </div>
                    <div class="d-flex justify-content-between">
                      <span>Anual</span>
                      <strong>S/ </strong>
                    </div>
                    <hr />
                    <div class="d-flex justify-content-between">
                      <span>Módulos</span>
                      <strong>{{ form.modules.length }}</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                      <span>Características</span>
                      <strong></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                      <span>Imágenes</span>
                      <strong></strong>
                    </div>
                    <hr />
                    <b-button variant="success" block @click="save" :disabled="loading">
                      <i class="fas fa-save me-1"></i> Guardar
                    </b-button>
                  </b-card>
                </div>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>

<script setup>
import { ref, reactive, onMounted, computed, nextTick } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import httpClient from '@/helpers/http-client';
import Swal from 'sweetalert2';
import DefaultLayout from '@/layouts/DefaultLayout.vue';

// Importar componentes hijos
import SystemGeneral from './components/SystemGeneral.vue';
//import SystemPricing from './components/SystemPricing.vue';
//import SystemFeatures from './components/SystemFeatures.vue';
import SystemModules from './components/SystemModules.vue';
//import SystemGallery from './components/SystemGallery.vue';
//import SystemSpecs from './components/SystemSpecs.vue';
//import SystemBadge from './components/SystemBadge.vue';

// Router
const router = useRouter();
const route = useRoute();
const loading = ref(false);
const isEdit = ref(false);
const activeSection = ref('general');

// Referencias a secciones (para scroll)
const sectionGeneral = ref(null);
//const sectionPrecios = ref(null);
//const sectionCaracteristicas = ref(null);
const sectionModulos = ref(null);
//const sectionGaleria = ref(null);
const sectionSpecs = ref(null);
//const sectionBadges = ref(null);
const galleryRef = ref(null);

// Datos del formulario
const form = reactive({
  id: null,
  system_category_id: null,
  name: '',
  slug: '',
  icon_emoji: '🛒',
  description_short: '',
  description_long: '',
  badge: null,
  is_active: true,
  is_featured: false,

  // Relaciones
  modules: [
    {
      name: 'Punto de Venta (POS)',
      icon: '🛒',
      items: [
        'Venta rápida con lector de código de barras',
        'Múltiples cajas y usuarios simultáneos'
      ]
    }
  ],

});

// Galería (archivos e imágenes existentes)
//const galleryPreviews = ref([]);

// Categorías (para el select)
const categories = ref([]);
const categoryName = computed(() => {
  const found = categories.value.find(c => c.id === form.system_category_id);
  return found ? found.name : '';
});

// Navegación
const navItems = [
  { key: 'general', label: 'General', icon: 'fas fa-info-circle' },
  //{ key: 'precios', label: 'Precios', icon: 'fas fa-tags' },
  { key: 'caracteristicas', label: 'Características', icon: 'fas fa-list-check' },
  { key: 'modulos', label: 'Módulos', icon: 'fas fa-cubes' },
  //{ key: 'galeria', label: 'Galería', icon: 'fas fa-images' },
  { key: 'specs', label: 'Especificaciones', icon: 'fas fa-sliders-h' },
  //{ key: 'badges', label: 'Badge', icon: 'fas fa-certificate' }
];

// Mapeo de referencias
const sectionRefs = {
  general: sectionGeneral,
  //precios: sectionPrecios,
  //caracteristicas: sectionCaracteristicas,
  modulos: sectionModulos,
  // galeria: sectionGaleria,
  // specs: sectionSpecs,
  // badges: sectionBadges
};

const completionPercent = computed(() => {
  let total = 0;
  let filled = 0;

  if (form.name) filled++;
  total++;
  if (form.description_short) filled++;
  total++;
  if (form.system_category_id) filled++;
  total++;

  if (form.modules.length > 0) filled++;
  total++;


  return Math.round((filled / total) * 100);
});

// Scroll y activación
function scrollTo(key) {
  const el = sectionRefs[key]?.value?.$el || sectionRefs[key]?.value;
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    activeSection.value = key;
  }
}

// Detectar sección visible al hacer scroll
function onScroll() {
  const scrollY = window.scrollY + 100;
  let found = 'general';
  for (const item of navItems) {
    const el = sectionRefs[item.key]?.value?.$el || sectionRefs[item.key]?.value;
    if (el) {
      const rect = el.getBoundingClientRect();
      if (rect.top <= 150) {
        found = item.key;
      }
    }
  }
  activeSection.value = found;
}

// Generar slug
function generateSlug() {
  if (!form.slug && form.name) {
    form.slug = form.name
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }
}

// Cargar datos para edición
async function loadSystem(id) {
  try {
    const res = await httpClient.get(`systems/${id}`);
    const data = res.data;

    // Asignar campos principales
    Object.assign(form, {
      id: data.id,
      system_category_id: data.system_category_id,
      name: data.name,
      slug: data.slug,
      icon_emoji: data.icon_emoji,
      description_short: data.description_short,
      description_long: data.description_long,
      badge: data.badge,
      is_active: data.is_active,
      is_featured: data.is_featured
    });

    // Relaciones
    form.plans = data.plans || [
      { plan_type: 'monthly', price: 99, discount_percent: 0 },
      { plan_type: 'annual', price: 79, discount_percent: 20 }
    ];
    form.features = data.features || [];
    form.modules = data.modules || [];
    form.specs = data.specs || [];

    // Galería
    if (data.media) {
      galleryPreviews.value = data.media.map(m => ({
        url: m.url,
        file: null,
        is_primary: m.is_primary
      }));
    }
  } catch (error) {
    console.error(error);
    Swal.fire('Error', 'No se pudo cargar el sistema', 'error');
    router.push({ name: 'systems.index' });
  }
}

// Guardar
async function save() {
  // Validaciones básicas
  if (!form.name || !form.description_short || !form.system_category_id) {
    Swal.fire('Campos incompletos', 'Por favor completa los campos obligatorios', 'warning');
    return;
  }

  loading.value = true;

  try {
    const payload = new FormData();

    // Campos generales
    payload.append('name', form.name);
    payload.append('slug', form.slug);
    payload.append('system_category_id', form.system_category_id);
    payload.append('icon_emoji', form.icon_emoji);
    payload.append('description_short', form.description_short);
    payload.append('description_long', form.description_long || '');
    payload.append('badge', form.badge || '');
    payload.append('is_active', form.is_active ? 1 : 0);
    payload.append('is_featured', form.is_featured ? 1 : 0);

    // Relaciones (como JSON)
    //payload.append('plans', JSON.stringify(form.plans));
    //payload.append('features', JSON.stringify(form.features));
    payload.append('modules', JSON.stringify(form.modules));
    //payload.append('specs', JSON.stringify(form.specs));

    // Imágenes nuevas (las que tienen archivo)
    const galleryComponent = galleryRef.value;
    if (galleryComponent) {
      const filesToUpload = galleryComponent.getFilesToUpload();
      const galleryData = galleryComponent.getGalleryData();

      // Imágenes nuevas (con archivo)
      filesToUpload.forEach((file, idx) => {
        payload.append(`media_images[${idx}]`, file);
        const isPrimary = galleryData[idx]?.is_primary ? 1 : 0;
        payload.append(`media_is_primary[${idx}]`, isPrimary);
      });

      // Imágenes existentes (solo URLs) para actualizar is_primary
      const existingImages = galleryData.filter(img => !img.file);
      payload.append('existing_media', JSON.stringify(existingImages));
    }

    // Si es edición
    if (isEdit.value) {
      payload.append('_method', 'PUT');
    }

    const url = isEdit.value ? `systems/${form.id}` : 'systems';
    const res = await httpClient.post(url, payload, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });

    Swal.fire('¡Éxito!', res.data.message || 'Sistema guardado correctamente', 'success');
    router.push({ name: 'systems.index' });
  } catch (error) {
    console.error(error);
    Swal.fire('Error', error.response?.data?.message || 'Ocurrió un error al guardar', 'error');
  } finally {
    loading.value = false;
  }
}

// Volver al listado
function goBack() {
  router.push({ name: 'systems.index' });
}

// Cargar categorías
async function loadCategories() {
  try {
    const res = await httpClient.get('systems/config');
    categories.value = res.data.categories;
  } catch (error) {
    console.error(error);
  }
}

// Inicializar
onMounted(async () => {
  await loadCategories();

  const id = route.params.id;
  if (id) {
    isEdit.value = true;
    await loadSystem(id);
  } else {
    // Valores por defecto
    form.features = [
      { feature_text: 'Punto de venta (POS) ilimitado', is_highlight: true },
      { feature_text: 'Control de inventario en tiempo real', is_highlight: true },
      { feature_text: 'Facturación electrónica SUNAT', is_highlight: true },
      { feature_text: 'Soporte técnico incluido', is_highlight: true }
    ];
    form.modules = [
      {
        name: 'Punto de Venta (POS)',
        icon: '🛒',
        items: [
          'Venta rápida con lector de código de barras',
          'Múltiples cajas y usuarios simultáneos',
          'Boletas y facturas electrónicas SUNAT',
          'Pagos con tarjeta, Yape, Plin y efectivo'
        ]
      },
      {
        name: 'Control de Inventario',
        icon: '📦',
        items: [
          'Stock en tiempo real por sucursal',
          'Alertas de stock mínimo',
          'Control de fechas de vencimiento',
          'Transferencias entre almacenes'
        ]
      }
    ];
    /*     form.specs = [
          { spec_key: 'Requisitos', spec_value: 'PC, tablet o celular con internet' },
          { spec_key: 'Usuarios incluidos', spec_value: 'Hasta 3 (plan básico)' },
          { spec_key: 'Soporte', spec_value: 'Chat y WhatsApp en horario de oficina' }
        ]; */
  }

  // Activar scroll listener
  window.addEventListener('scroll', onScroll);
  // Scroll a la primera sección después de renderizar
  await nextTick();
  setTimeout(() => scrollTo('general'), 300);
});
</script>

<style scoped>
.nav-pills .nav-link {
  color: #495057;
  border-radius: 8px;
  cursor: pointer;
}

.nav-pills .nav-link.active {
  background-color: #28a745;
  color: white;
}

.nav-pills .nav-link:hover:not(.active) {
  background-color: #e9ecef;
}

section {
  scroll-margin-top: 20px;
}
</style>
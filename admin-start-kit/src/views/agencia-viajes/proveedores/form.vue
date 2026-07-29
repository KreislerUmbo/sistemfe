<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-truck-loading me-2 text-primary"></i>
                    {{ esEdicion ? 'Editar Proveedor' : 'Nuevo Proveedor' }}
                </h5>
                <small class="text-muted">Completa los datos del proveedor</small>
            </div>
            <router-link to="/agencia-viajes/proveedores" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">1</span>
                <span class="fw-semibold text-dark">Datos Generales</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Razón Social *</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.razon_social">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre Comercial</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.nombre_comercial">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de Proveedor *</label>
                        <select class="form-select form-select-sm" v-model="form.tipo_id">
                            <option :value="null">— Selecciona —</option>
                            <option v-for="tipo in proveedorTiposHabilitados" :key="tipo.id" :value="tipo.id">{{ tipo.nombre }}</option>
                        </select>
                        <small class="text-muted" v-if="proveedorTiposHabilitados.length === 0">
                            No hay tipos habilitados — habilítalos en <router-link to="/agencia-viajes/proveedores">Configuración de tipos</router-link>.
                        </small>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de Persona</label>
                        <select class="form-select form-select-sm" v-model="form.tipo_persona">
                            <option :value="null">—</option>
                            <option value="natural">Natural</option>
                            <option value="juridica">Jurídica</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de Documento</label>
                        <select class="form-select form-select-sm" v-model="form.tipo_documento">
                            <option :value="null">—</option>
                            <option value="DNI">DNI</option>
                            <option value="RUC">RUC</option>
                            <option value="CE">Carné Extranjería</option>
                            <option value="PASAPORTE">Pasaporte</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">N° Documento</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.numero_documento">
                    </div>
                    <div class="col-6 col-md-3 d-flex align-items-end">
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="estado-c" id="estado-activo" :checked="form.estado === true" @click="form.estado = true" autocomplete="off">
                            <label class="btn btn-outline-success btn-sm" for="estado-activo">Activo</label>
                            <input type="radio" class="btn-check" name="estado-c" id="estado-inactivo" :checked="form.estado === false" @click="form.estado = false" autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm" for="estado-inactivo">Inactivo</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">2</span>
                <span class="fw-semibold text-dark">Contacto</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Teléfono</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.telefono">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Celular</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.celular">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">WhatsApp</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.whatsapp">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Email</label>
                        <input type="email" class="form-control form-control-sm" v-model="form.email">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Dirección</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.direccion">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3" v-if="esMayorista">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">3</span>
                <span class="fw-semibold text-dark">Margen automático (mayorista)</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de margen</label>
                        <select class="form-select form-select-sm" v-model="form.margen_default_tipo">
                            <option :value="null">—</option>
                            <option value="porcentaje">Porcentaje</option>
                            <option value="fijo">Fijo</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Valor</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="form.margen_default_valor">
                    </div>
                </div>
                <small class="text-muted">Se aplica automático al cargar precios de costo de este mayorista — editable línea por línea.</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">{{ esMayorista ? 4 : 3 }}</span>
                <span class="fw-semibold text-dark">Observaciones</span>
            </div>
            <div class="card-body py-3">
                <textarea class="form-control form-control-sm" rows="3" v-model="form.observaciones"></textarea>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <router-link to="/agencia-viajes/proveedores" class="btn btn-outline-secondary">Cancelar</router-link>
            <button class="btn btn-primary fw-semibold" @click="guardar" :disabled="guardando">
                <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="fas fa-save me-2"></i>
                {{ esEdicion ? 'Actualizar' : 'Registrar' }}
            </button>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import type { Proveedor, ProveedorTipo } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();

const esEdicion = computed(() => !!route.params.id);
const proveedorId = computed(() => Number(route.params.id));

const proveedorTipos = ref<ProveedorTipo[]>([]);
const proveedorTiposHabilitados = computed(() => proveedorTipos.value.filter((t) => t.habilitado));

const guardando = ref<boolean>(false);

const form = ref<Partial<Omit<Proveedor, 'tipo_id'>> & { tipo_id: number | null }>({
    razon_social: '',
    nombre_comercial: null,
    tipo_id: null,
    tipo_persona: null,
    tipo_documento: null,
    numero_documento: null,
    telefono: null,
    celular: null,
    whatsapp: null,
    email: null,
    direccion: null,
    observaciones: null,
    estado: true,
    margen_default_tipo: null,
    margen_default_valor: null,
});

const esMayorista = computed(() => {
    const tipo = proveedorTipos.value.find((t) => t.id === form.value.tipo_id);
    return tipo?.slug === 'mayorista';
});

const cargarProveedor = async () => {
    if (!esEdicion.value) return;
    try {
        const res = await proveedorService.obtener(proveedorId.value);
        form.value = { ...res.proveedor };
    } catch (error) {
        console.log(error);
    }
};

const guardar = async () => {
    if (!form.value.razon_social?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La razón social es obligatoria.', 'error');
        return;
    }
    if (!form.value.tipo_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Selecciona el tipo de proveedor.', 'error');
        return;
    }

    guardando.value = true;
    try {
        const res = esEdicion.value
            ? await proveedorService.actualizar(proveedorId.value, form.value)
            : await proveedorService.crear(form.value);

        await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        router.push(`/agencia-viajes/proveedores/${res.proveedor.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};

onMounted(async () => {
    const res = await proveedorTipoService.listar();
    proveedorTipos.value = res.proveedor_tipos;
    await cargarProveedor();
});
</script>

<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>
                    {{ esEdicion ? 'Editar Paquete/Tour' : 'Nuevo Paquete/Tour' }}
                </h5>
                <small class="text-muted">Datos generales de la plantilla — itinerario, ítems incluidos y hoteles se cargan después de guardar</small>
            </div>
            <router-link to="/agencia-viajes/paquetes" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <!-- ═══ Tipo: tour_simple | paquete_combo ═══ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <label class="form-label mb-2 small fw-semibold text-secondary">¿Qué estás creando? *</label>

                <div v-if="tipoBloqueado" class="d-flex align-items-center gap-2">
                    <span class="badge fs-6 py-2 px-3" :class="form.tipo === 'paquete_combo' ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-info-subtle text-info border border-info-subtle'">
                        <i class="fas me-2" :class="form.tipo === 'paquete_combo' ? 'fa-layer-group' : 'fa-route'"></i>
                        {{ form.tipo === 'paquete_combo' ? 'Paquete combo' : 'Tour simple' }}
                    </span>
                    <small class="text-muted fst-italic">Ya tiene ítems cargados en "Incluye" — para cambiar el tipo, quitalos primero.</small>
                </div>
                <div v-else class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="card h-100 tipo-card-select" :class="{ 'border-primary border-2 bg-primary bg-opacity-10': form.tipo === 'tour_simple' }"
                            style="cursor:pointer" @click="form.tipo = 'tour_simple'">
                            <div class="card-body text-center py-4">
                                <i class="fas fa-route fs-2 text-primary mb-2 d-block"></i>
                                <div class="fw-semibold text-dark">Tour simple</div>
                                <small class="text-muted">Un tour armado con sus propios atractivos, actividades y servicios</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="card h-100 tipo-card-select" :class="{ 'border-primary border-2 bg-primary bg-opacity-10': form.tipo === 'paquete_combo' }"
                            style="cursor:pointer" @click="form.tipo = 'paquete_combo'">
                            <div class="card-body text-center py-4">
                                <i class="fas fa-layers fs-2 text-primary mb-2 d-block"></i>
                                <div class="fw-semibold text-dark">Paquete combo</div>
                                <small class="text-muted">Agrupa 2 o más tours ya armados en un solo paquete vendible</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Código</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.codigo" placeholder="Ej. PDKM-CZ">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Categoría *</label>
                        <select class="form-select form-select-sm" v-model="form.categoria">
                            <option value="local">Local</option>
                            <option value="nacional">Nacional</option>
                            <option value="internacional">Internacional</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre *</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.nombre" placeholder="Ej. Full Day Alto Mayo">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Descripción</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="form.descripcion"></textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino / Atractivo principal *</label>
                        <DestinoTreeSelect v-model="form.destino_atractivo_id" nivel-max="lugar" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Duración (horas) *</label>
                        <input type="number" min="1" class="form-control form-control-sm" v-model.number="form.duracion_horas">
                    </div>
                    <div class="col-6 col-md-3" v-if="form.tipo !== 'paquete_combo'">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta (desde)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="form.precio_venta_final" placeholder="Se resuelve solo con los hoteles">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hora de salida</label>
                        <input type="time" class="form-control form-control-sm" v-model="form.hora_salida">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hora de retorno</label>
                        <input type="time" class="form-control form-control-sm" v-model="form.hora_retorno">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Lugar de recojo</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.lugar_recojo" placeholder="Ej. Hoteles ubicados dentro de la ciudad">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">No incluye</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="form.no_incluye"></textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Recomendaciones</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="form.recomendaciones"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark small">Vuelo</span>
            </div>
            <div class="card-body py-3">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="vueloIncluido" v-model="form.vuelo_incluido">
                    <label class="form-check-label small" for="vueloIncluido">Incluye vuelo (ej. Cusco/Panamá — Alto Mayo normalmente no)</label>
                </div>
                <div v-if="form.vuelo_incluido" class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Aerolínea</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.vuelo_aerolinea">
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Detalle (tramos, fechas, equipaje...)</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.vuelo_detalle">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3" v-if="form.tipo === 'paquete_combo'">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark small">Descuento y margen mínimo del combo</span>
            </div>
            <div class="card-body py-3">
                <small class="text-muted d-block mb-2">El precio final se calcula solo, sumando los tours incluidos (pestaña "Incluye") — acá solo configurás el descuento y el piso de margen protegido. Vista previa en vivo disponible en la pestaña "Datos" una vez guardado.</small>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de descuento</label>
                        <div class="btn-group btn-group-sm d-flex">
                            <button type="button" class="btn" :class="form.descuento_tipo === 'porcentaje' ? 'btn-primary' : 'btn-outline-secondary'" @click="form.descuento_tipo = 'porcentaje'">%</button>
                            <button type="button" class="btn" :class="form.descuento_tipo === 'monto' ? 'btn-primary' : 'btn-outline-secondary'" @click="form.descuento_tipo = 'monto'">S/ fijo</button>
                            <button type="button" class="btn" :class="!form.descuento_tipo ? 'btn-primary' : 'btn-outline-secondary'" @click="form.descuento_tipo = null; form.descuento_valor = null">Sin descuento</button>
                        </div>
                    </div>
                    <div class="col-6 col-md-4" v-if="form.descuento_tipo">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Valor del descuento</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="form.descuento_valor">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Margen mínimo protegido (%)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="form.margen_minimo_pct" placeholder="Opcional">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vigente desde</label>
                        <input type="date" class="form-control form-control-sm" v-model="form.vigencia_desde">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vigente hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="form.vigencia_hasta">
                    </div>
                    <div class="col-12 col-md-6 d-flex align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="publicadoWeb" v-model="form.publicado_web">
                            <label class="form-check-label small text-muted" for="publicadoWeb">Publicado en portal web (sin efecto todavía — interruptor para el futuro portal)</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <button class="btn btn-primary fw-semibold" @click="guardar" :disabled="guardando">
                <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="fas fa-check me-2"></i>
                {{ esEdicion ? 'Guardar cambios' : 'Crear paquete/tour' }}
            </button>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import type { PaquetePlantilla } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();

const esEdicion = computed(() => !!route.params.id);
const paqueteId = computed(() => Number(route.params.id));

const guardando = ref<boolean>(false);

const form = ref<Partial<PaquetePlantilla> & { destino_atractivo_id: number | null }>({
    codigo: null,
    categoria: 'local',
    tipo: 'tour_simple',
    nombre: '',
    descripcion: null,
    destino_atractivo_id: null,
    duracion_horas: 1,
    hora_salida: null,
    hora_retorno: null,
    lugar_recojo: null,
    no_incluye: null,
    recomendaciones: null,
    vuelo_incluido: false,
    vuelo_aerolinea: null,
    vuelo_detalle: null,
    precio_venta_final: null,
    vigencia_desde: null,
    vigencia_hasta: null,
    publicado_web: false,
    activo: true,
    descuento_tipo: null,
    descuento_valor: null,
    margen_minimo_pct: null,
});

// Una vez guardado el header CON ítems cargados en "Incluye", el backend
// bloquea cambiar `tipo` (ver PaquetePlantillaController::update()) — el
// selector pasa a solo-lectura para que la UI no prometa algo que el
// server va a rechazar.
const tipoBloqueado = computed(() => esEdicion.value && (form.value.items?.length ?? 0) > 0);

const cargarPaquete = async () => {
    if (!esEdicion.value) return;
    try {
        const res = await paquetePlantillaService.obtener(paqueteId.value);
        form.value = { ...res.paquete_plantilla };
        // El backend devuelve hora_salida/hora_retorno tal cual las guarda
        // Postgres (columna `time`, sin $cast) — "06:00:00", no "06:00".
        // <input type="time"> espera/valida H:i sin segundos, y si el
        // usuario no llega a tocar el campo, ese "06:00:00" se reenvía tal
        // cual y el backend lo rechaza (date_format:H:i). Se normaliza acá,
        // al cargar, para que coincida siempre con lo que el input y el
        // backend esperan.
        if (form.value.hora_salida) form.value.hora_salida = form.value.hora_salida.slice(0, 5);
        if (form.value.hora_retorno) form.value.hora_retorno = form.value.hora_retorno.slice(0, 5);
    } catch (error) {
        console.log(error);
    }
};

const guardar = async () => {
    if (!form.value.nombre?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'El nombre es obligatorio.', 'error');
        return;
    }
    if (!form.value.destino_atractivo_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Seleccioná el destino/atractivo principal.', 'error');
        return;
    }
    if (!form.value.duracion_horas || form.value.duracion_horas < 1) {
        (Swal as TVueSwalInstance).fire('Error', 'La duración en horas es obligatoria.', 'error');
        return;
    }
    if (form.value.tipo === 'paquete_combo') {
        form.value.precio_venta_final = null;
    }

    guardando.value = true;
    try {
        const res = esEdicion.value
            ? await paquetePlantillaService.actualizar(paqueteId.value, form.value)
            : await paquetePlantillaService.crear(form.value);

        await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        router.push(`/agencia-viajes/paquetes/${res.paquete_plantilla.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};

onMounted(async () => {
    await cargarPaquete();
});
</script>

<style scoped>
.tipo-card-select {
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.tipo-card-select:hover {
    border-color: var(--bs-primary);
}
</style>

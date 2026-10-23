<template>
    <div>
        <!-- Servicios a reservar (D10, D11). Solo con tours del modo nuevo: en
             una salida clásica cada reserva trae su propio proveedor. -->
        <div v-if="mostrarServicios" class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span class="fw-semibold small">Servicios a reservar</span>
                <div v-if="!soloLectura" class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="ocupado" @click="actualizarDesdeTour"
                        title="Agrega los servicios nuevos del Incluye del tour, sin tocar lo ya asignado">
                        <i class="fas fa-rotate me-1"></i>Actualizar desde el tour
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Servicio</th>
                                <th style="min-width:220px">Proveedor</th>
                                <th style="width:110px">Cantidad</th>
                                <th style="width:140px">Estado</th>
                                <th v-if="!soloLectura" style="width:40px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="servicios.length === 0">
                                <td colspan="5" class="text-center text-muted fst-italic py-3">
                                    Sin servicios. Si el tour tiene Incluye, usa "Actualizar desde el tour".
                                </td>
                            </tr>
                            <tr v-for="sv in servicios" :key="sv.id">
                                <td class="ps-3">
                                    {{ sv.nombre }}
                                    <span v-if="!sv.del_tour" class="badge bg-light text-dark border ms-1">agregado a mano</span>
                                </td>
                                <td>
                                    <span v-if="soloLectura">{{ sv.proveedor ?? '—' }}</span>
                                    <template v-else>
                                        <ProveedorBuscador :model-value="sv.proveedor_id" :nombre-actual="sv.proveedor"
                                            @elegido="(p) => guardarServicio(sv, { proveedor_id: p?.id ?? null })" />
                                        <div v-if="!sv.proveedor_id" class="text-warning-emphasis" style="font-size:.72rem">
                                            <i class="fas fa-triangle-exclamation me-1"></i>Sin proveedor
                                        </div>
                                    </template>
                                </td>
                                <td>
                                    <input type="number" min="0" class="form-control form-control-sm" :disabled="soloLectura"
                                        :value="sv.cantidad_manual ?? ''" :placeholder="String(sv.cantidad)"
                                        @change="guardarCantidad(sv, ($event.target as HTMLInputElement).value)">
                                    <div class="text-muted" style="font-size:.72rem">
                                        {{ sv.cantidad_manual === null ? 'automática' : 'a mano' }}
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm w-100" :disabled="soloLectura || ocupado"
                                        :class="sv.estado === 'reservado' ? 'btn-success' : 'btn-outline-warning'"
                                        :title="sv.estado === 'reservado' ? 'Volver a Por reservar' : 'Marcar como reservado con el proveedor'"
                                        @click="guardarServicio(sv, { estado: sv.estado === 'reservado' ? 'por_reservar' : 'reservado' })">
                                        <i :class="sv.estado === 'reservado' ? 'fas fa-check' : 'far fa-clock'" class="me-1"></i>
                                        {{ sv.estado === 'reservado' ? 'Reservado' : 'Por reservar' }}
                                    </button>
                                </td>
                                <td v-if="!soloLectura">
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" title="Quitar servicio" @click="quitarServicio(sv)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!soloLectura" class="d-flex gap-2 p-2 border-top">
                    <input type="text" class="form-control form-control-sm" placeholder="Agregar un servicio a mano (ej. Botiquín, Snack extra)"
                        v-model="nuevoServicio" @keyup.enter="agregarServicio">
                    <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" :disabled="!nuevoServicio.trim() || ocupado" @click="agregarServicio">
                        <i class="fas fa-plus me-1"></i>Agregar
                    </button>
                </div>
            </div>
        </div>

        <!-- Vehículos (D12, D13) -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between">
                <span class="fw-semibold small">Vehículos</span>
                <button v-if="!soloLectura" type="button" class="btn btn-outline-primary btn-sm" :disabled="ocupado" @click="agregarVehiculo">
                    <i class="fas fa-plus me-1"></i>Agregar vehículo
                </button>
            </div>
            <div class="card-body">
                <div v-if="vehiculos.length === 0" class="text-center text-muted fst-italic small py-2">
                    Sin vehículos. Agrega uno si el tour lleva transporte.
                </div>
                <div v-for="(v, k) in vehiculos" :key="v.id" class="border rounded p-2 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold small">Vehículo {{ k + 1 }}</span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="small" :class="v.excede_capacidad ? 'text-danger fw-semibold' : 'text-muted'">
                                {{ v.pasajeros }}{{ v.capacidad ? ` / ${v.capacidad}` : '' }} asientos ocupados
                            </span>
                            <button v-if="!soloLectura" type="button" class="btn btn-sm btn-link text-danger p-0" title="Quitar vehículo" @click="quitarVehiculo(v)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-12 col-md-4">
                            <label class="form-label mb-0 small text-secondary">Descripción / placa</label>
                            <input type="text" class="form-control form-control-sm" :disabled="soloLectura" placeholder="Van 14 pax · ABC-123"
                                :value="v.descripcion ?? ''" @change="guardarVehiculo(v, { descripcion: valor($event) })">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label mb-0 small text-secondary">Transportista</label>
                            <span v-if="soloLectura" class="d-block small">{{ v.proveedor ?? 'Propio' }}</span>
                            <ProveedorBuscador v-else :model-value="v.proveedor_id" :nombre-actual="v.proveedor" placeholder="Vacío = vehículo propio"
                                @elegido="(p) => guardarVehiculo(v, { proveedor_id: p?.id ?? null })" />
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-0 small text-secondary">Capacidad</label>
                            <input type="number" min="1" class="form-control form-control-sm" :disabled="soloLectura"
                                :value="v.capacidad ?? ''" @change="guardarVehiculo(v, { capacidad: numero($event) })">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-0 small text-secondary">Guía del vehículo</label>
                            <select class="form-select form-select-sm" :disabled="soloLectura" :value="v.guia_id ?? ''"
                                @change="guardarVehiculo(v, { guia_id: numero($event) })">
                                <option value="">El de la salida</option>
                                <option v-for="g in guias" :key="g.id" :value="g.id">{{ g.nombre }}</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-0 small text-secondary">Chofer</label>
                            <input type="text" class="form-control form-control-sm" :disabled="soloLectura"
                                :value="v.chofer_nombre ?? ''" @change="guardarVehiculo(v, { chofer_nombre: valor($event) })">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-0 small text-secondary">Teléfono del chofer</label>
                            <input type="text" class="form-control form-control-sm" :disabled="soloLectura"
                                :value="v.chofer_telefono ?? ''" @change="guardarVehiculo(v, { chofer_telefono: valor($event) })">
                        </div>
                    </div>
                    <div class="mt-2">
                        <span class="small text-secondary">Reservas que lleva:</span>
                        <div class="d-flex flex-wrap gap-3 mt-1">
                            <label v-for="r in reservas" :key="r.id" class="small d-flex align-items-center gap-1">
                                <input type="checkbox" class="form-check-input mt-0" :disabled="soloLectura || ocupado"
                                    :checked="v.reserva_ids.includes(r.id)" @change="alternarReserva(v, r.id)">
                                {{ r.codigo ?? ('#' + r.id) }} <span class="text-muted">({{ r.total_pax }} pax)</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div v-if="(salida.reservas_sin_vehiculo ?? 0) > 0" class="small text-warning-emphasis">
                    <i class="fas fa-triangle-exclamation me-1"></i>{{ salida.reservas_sin_vehiculo }} reserva(s) sin vehículo.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Fila 13, fase 2b (plan-tours-venta-simple-costo-real.md §3.6, D10-D13):
// servicios a reservar y vehículos de una salida, dentro del detalle del
// tablero de Salidas Operativas. Cada cambio se guarda al momento y avisa al
// padre ('cambio') para que recargue la salida (cantidades, avisos).
import { computed, ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import ProveedorBuscador from '@/components/AgenciaViajes/ProveedorBuscador.vue';
import { salidaOperativaService } from '@/services/admin/salidaOperativaService';
import { useToast } from '@/composables/useToast';
import type { Guia, SalidaOperativa, SalidaServicio, SalidaVehiculo } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{
    salida: SalidaOperativa;
    guias: Guia[];
}>();

const emit = defineEmits<{ (e: 'cambio'): void }>();

const toast = useToast();
const ocupado = ref(false);
const nuevoServicio = ref('');

const soloLectura = computed(() => props.salida.estado === 'cancelada');
const servicios = computed(() => props.salida.servicios ?? []);
const mostrarServicios = computed(() => props.salida.tiene_lineas_tour || servicios.value.length > 0);
const vehiculos = computed(() => props.salida.vehiculos ?? []);
const reservas = computed(() => props.salida.reservas ?? []);

const valor = (e: Event) => ((e.target as HTMLInputElement).value.trim() || null);
const numero = (e: Event) => {
    const v = (e.target as HTMLInputElement | HTMLSelectElement).value;
    return v === '' ? null : Number(v);
};

// Ejecuta una llamada, avisa el resultado y pide al padre recargar la salida.
const ejecutar = async (llamada: () => Promise<{ message?: string }>, silencioso = false) => {
    ocupado.value = true;
    try {
        const res = await llamada();
        if (!silencioso && res?.message) toast.success(res.message);
        emit('cambio');
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo guardar el cambio');
    } finally {
        ocupado.value = false;
    }
};

const guardarServicio = (sv: SalidaServicio, data: Partial<SalidaServicio>) =>
    ejecutar(() => salidaOperativaService.actualizarServicio(sv.id, data), true);

// Vacío = vuelve a la cantidad automática (adultos + niños).
const guardarCantidad = (sv: SalidaServicio, texto: string) =>
    guardarServicio(sv, { cantidad_manual: texto === '' ? null : Number(texto) });

const agregarServicio = () => {
    const nombre = nuevoServicio.value.trim();
    if (!nombre) return;
    nuevoServicio.value = '';
    ejecutar(() => salidaOperativaService.agregarServicio(props.salida.id, { nombre }));
};

const actualizarDesdeTour = () => ejecutar(() => salidaOperativaService.actualizarServiciosDesdeTour(props.salida.id));

const quitarServicio = async (sv: SalidaServicio) => {
    const r = await (Swal as TVueSwalInstance).fire({
        title: `¿Quitar «${sv.nombre}»?`, icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Sí, quitar', cancelButtonText: 'Volver',
    });
    if (r.isConfirmed) ejecutar(() => salidaOperativaService.quitarServicio(sv.id));
};

const agregarVehiculo = () => ejecutar(() => salidaOperativaService.agregarVehiculo(props.salida.id, {}));

const guardarVehiculo = (v: SalidaVehiculo, data: Partial<SalidaVehiculo>) =>
    ejecutar(() => salidaOperativaService.actualizarVehiculo(v.id, data), true);

const quitarVehiculo = async (v: SalidaVehiculo) => {
    const r = await (Swal as TVueSwalInstance).fire({
        title: '¿Quitar este vehículo?', text: 'Sus reservas quedan sin vehículo.', icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Sí, quitar', cancelButtonText: 'Volver',
    });
    if (r.isConfirmed) ejecutar(() => salidaOperativaService.quitarVehiculo(v.id));
};

// Marcar una reserva en este vehículo la saca del otro (lo hace el backend).
const alternarReserva = (v: SalidaVehiculo, reservaId: number) => {
    const ids = v.reserva_ids.includes(reservaId)
        ? v.reserva_ids.filter((id) => id !== reservaId)
        : [...v.reserva_ids, reservaId];
    ejecutar(() => salidaOperativaService.asignarReservasVehiculo(v.id, ids));
};
</script>

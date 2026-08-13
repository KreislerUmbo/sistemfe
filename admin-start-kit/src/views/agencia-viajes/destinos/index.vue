<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-map-marked-alt me-2 text-primary"></i>
                    Destinos y Atractivos
                </h5>
                <small class="text-muted">Árbol de zonas, lugares y atractivos</small>
            </div>
            <router-link to="/agencia-viajes/destinos/nuevo" class="btn btn-primary fw-semibold shadow-sm">
                <i class="fas fa-plus me-2"></i>Nueva Zona
            </router-link>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Nombre</th>
                                <th>Tipo</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="3" class="text-center py-5 text-muted">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                                </td>
                            </tr>
                            <tr v-else-if="filasVisibles.length === 0">
                                <td colspan="3" class="text-center py-5 text-muted fst-italic">Sin destinos cargados todavía.</td>
                            </tr>
                            <tr v-for="fila in filasVisibles" :key="fila.id">
                                <td class="ps-3">
                                    <span :style="{ paddingLeft: (fila.profundidad * 22) + 'px' }">
                                        <i v-if="fila.tieneHijos" class="fas me-1" style="cursor:pointer;width:12px;display:inline-block;"
                                            :class="expandidos.has(fila.id) ? 'fa-caret-down' : 'fa-caret-right'"
                                            @click="toggleExpandido(fila.id)"></i>
                                        <i v-else class="me-1" style="width:12px;display:inline-block;"></i>
                                        {{ fila.nombre }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">{{ etiquetaTipo(fila.tipo) }}</span>
                                </td>
                                <td class="text-center pe-3">
                                    <router-link v-if="fila.tipo !== 'atractivo'" class="btn btn-sm btn-outline-success me-1"
                                        :title="`Agregar ${fila.tipo === 'zona' ? 'lugar' : 'atractivo'}`"
                                        :to="`/agencia-viajes/destinos/nuevo?parent_id=${fila.id}`">
                                        <i class="fas fa-plus"></i>
                                    </router-link>
                                    <button class="btn btn-sm btn-outline-primary me-1" title="Servicios asociados" @click="abrirServicios(fila)">
                                        <i class="fas fa-concierge-bell"></i>
                                    </button>
                                    <router-link class="btn btn-sm btn-outline-secondary me-1" title="Editar"
                                        :to="`/agencia-viajes/destinos/${fila.id}/editar`">
                                        <i class="fas fa-pen"></i>
                                    </router-link>
                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar" @click="eliminar(fila)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal servicios asociados -->
        <div v-if="modalServiciosAbierto" class="modal d-block" style="background:rgba(0,0,0,.5)" @click.self="modalServiciosAbierto = false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Servicios de "{{ destinoServiciosActivo?.nombre }}"</h6>
                        <button class="btn-close" @click="modalServiciosAbierto = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="input-group input-group-sm mb-2">
                            <select class="form-select" v-model="servicioNuevoId">
                                <option :value="null">— Selecciona un servicio —</option>
                                <option v-for="s in servicios" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                            </select>
                            <button class="btn btn-primary" @click="asociarServicio" :disabled="!servicioNuevoId">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <!-- Alta rápida: el catálogo de servicios (Traslado, Hospedaje,
                             Entrada/Boleto...) no tiene pantalla propia en esta sesión —
                             se crea acá mismo, donde se necesita, mismo espíritu que
                             ClientFormQuick/ProductFormQuick del core. -->
                        <div class="input-group input-group-sm mb-3">
                            <input type="text" class="form-control" placeholder="¿No está en la lista? Escribe el nombre y créalo..." v-model="servicioNuevoNombre">
                            <button class="btn btn-outline-success" @click="crearServicioRapido" :disabled="!servicioNuevoNombre.trim()">
                                <i class="fas fa-plus-circle me-1"></i>Crear
                            </button>
                        </div>
                        <ul class="list-group">
                            <li v-for="ds in destinoServiciosLista" :key="ds.id" class="list-group-item d-flex justify-content-between align-items-center">
                                <div v-if="editandoServicioId === ds.servicio?.id" class="w-100">
                                    <small class="text-muted d-block mb-1">
                                        <i class="fas fa-info-circle me-1"></i>Este cambio de nombre afecta a todos los destinos que usan este servicio.
                                    </small>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" v-model="editandoServicioNombre" @keyup.enter="guardarNombreServicio">
                                        <button class="btn btn-outline-success" @click="guardarNombreServicio" :disabled="!editandoServicioNombre.trim()">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-outline-secondary" @click="cancelarEdicionServicio">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <div v-else-if="moviendoServicioId === ds.id" class="d-flex gap-1 align-items-start w-100">
                                    <div class="flex-grow-1">
                                        <DestinoTreeSelect v-model="moviendoDestinoNuevoId" placeholder="Buscar destino..." />
                                    </div>
                                    <button class="btn btn-sm btn-outline-success" @click="confirmarMoverServicio(ds.id)" :disabled="!moviendoDestinoNuevoId">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" @click="cancelarMoverServicio">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <template v-else>
                                    {{ ds.servicio?.nombre }}
                                    <span>
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Mover a otro destino" @click="iniciarMoverServicio(ds.id)">
                                            <i class="fas fa-arrows-alt"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary me-1" @click="iniciarEdicionServicio(ds)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" @click="desasociarServicio(ds.id)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </span>
                                </template>
                            </li>
                            <li v-if="destinoServiciosLista.length === 0" class="list-group-item text-muted fst-italic">
                                Sin servicios asociados.
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { destinoAtractivoService } from '@/services/admin/destinoAtractivoService';
import { servicioService } from '@/services/admin/servicioService';
import type { DestinoAtractivo, DestinoServicio, Servicio } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

type Fila = { id: number; nombre: string; tipo: 'zona' | 'lugar' | 'atractivo'; parentId: number | null; profundidad: number; tieneHijos: boolean };

const arbol = ref<DestinoAtractivo[]>([]);
const servicios = ref<Servicio[]>([]);
const loading = ref<boolean>(false);
const expandidos = ref<Set<number>>(new Set());

const filas = computed<Fila[]>(() => {
    const resultado: Fila[] = [];
    const recorrer = (nodos: DestinoAtractivo[], parentId: number | null, profundidad: number) => {
        for (const nodo of nodos) {
            resultado.push({
                id: nodo.id, nombre: nodo.nombre, tipo: nodo.tipo, parentId,
                profundidad, tieneHijos: !!(nodo.hijos && nodo.hijos.length > 0),
            });
            if (nodo.hijos) recorrer(nodo.hijos, nodo.id, profundidad + 1);
        }
    };
    recorrer(arbol.value, null, 0);
    return resultado;
});

const filasVisibles = computed(() => {
    return filas.value.filter((fila) => {
        if (fila.parentId === null) return true;
        // Todos los ancestros deben estar expandidos.
        let actual: Fila | undefined = fila;
        while (actual && actual.parentId !== null) {
            if (!expandidos.value.has(actual.parentId)) return false;
            actual = filas.value.find((f) => f.id === actual!.parentId);
        }
        return true;
    });
});

const toggleExpandido = (id: number) => {
    if (expandidos.value.has(id)) expandidos.value.delete(id);
    else expandidos.value.add(id);
};

const etiquetaTipo = (tipo: string) => ({ zona: 'Zona', lugar: 'Lugar', atractivo: 'Atractivo' }[tipo] ?? tipo);

const cargarArbol = async () => {
    loading.value = true;
    try {
        const res = await destinoAtractivoService.arbol();
        arbol.value = res.destinos_atractivos;
    } finally {
        loading.value = false;
    }
};

const eliminar = (fila: Fila) => {
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar eliminación', text: `¿Eliminar "${fila.nombre}"?`, icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sí, eliminar',
    }).then(async (result: any) => {
        if (!result.isConfirmed) return;
        try {
            const res = await destinoAtractivoService.eliminar(fila.id);
            (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
            await cargarArbol();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar', 'error');
        }
    });
};

// ── Servicios asociados ──────────────────────────────────────────────
const modalServiciosAbierto = ref<boolean>(false);
const destinoServiciosActivo = ref<Fila | null>(null);
const destinoServiciosLista = ref<DestinoServicio[]>([]);
const servicioNuevoId = ref<number | null>(null);
const servicioNuevoNombre = ref<string>('');
const editandoServicioId = ref<number | null>(null);
const editandoServicioNombre = ref<string>('');
const moviendoServicioId = ref<number | null>(null);
const moviendoDestinoNuevoId = ref<number | null>(null);

const abrirServicios = async (fila: Fila) => {
    destinoServiciosActivo.value = fila;
    servicioNuevoId.value = null;
    servicioNuevoNombre.value = '';
    modalServiciosAbierto.value = true;
    const res = await destinoAtractivoService.listarServicios(fila.id);
    destinoServiciosLista.value = res.destino_servicios;
};

const crearServicioRapido = async () => {
    try {
        const res = await servicioService.crear({ nombre: servicioNuevoNombre.value.trim() });
        servicios.value.push(res.servicio);
        servicioNuevoId.value = res.servicio.id;
        servicioNuevoNombre.value = '';
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo crear el servicio', 'error');
    }
};

const asociarServicio = async () => {
    if (!destinoServiciosActivo.value || !servicioNuevoId.value) return;
    try {
        await destinoAtractivoService.asociarServicio(destinoServiciosActivo.value.id, servicioNuevoId.value);
        const res = await destinoAtractivoService.listarServicios(destinoServiciosActivo.value.id);
        destinoServiciosLista.value = res.destino_servicios;
        servicioNuevoId.value = null;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo asociar', 'error');
    }
};

const iniciarEdicionServicio = (ds: DestinoServicio) => {
    if (!ds.servicio) return;
    editandoServicioId.value = ds.servicio.id;
    editandoServicioNombre.value = ds.servicio.nombre;
};

const cancelarEdicionServicio = () => {
    editandoServicioId.value = null;
    editandoServicioNombre.value = '';
};

// Renombra la fila real del catálogo compartido de servicios (no una
// copia local) — afecta a todos los destinos/proveedores que ya lo usan.
const guardarNombreServicio = async () => {
    if (!editandoServicioId.value || !editandoServicioNombre.value.trim()) return;
    try {
        await servicioService.actualizar(editandoServicioId.value, { nombre: editandoServicioNombre.value.trim() });
        cancelarEdicionServicio();
        if (destinoServiciosActivo.value) {
            const res = await destinoAtractivoService.listarServicios(destinoServiciosActivo.value.id);
            destinoServiciosLista.value = res.destino_servicios;
        }
        const serviciosRes = await servicioService.listar({ per_page: 200 });
        servicios.value = serviciosRes.servicios;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar el servicio', 'error');
    }
};

const desasociarServicio = async (destinoServicioId: number) => {
    if (!destinoServiciosActivo.value) return;
    try {
        await destinoAtractivoService.desasociarServicio(destinoServicioId);
        const res = await destinoAtractivoService.listarServicios(destinoServiciosActivo.value.id);
        destinoServiciosLista.value = res.destino_servicios;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo quitar', 'error');
    }
};

const iniciarMoverServicio = (dsId: number) => {
    cancelarEdicionServicio();
    moviendoServicioId.value = dsId;
    moviendoDestinoNuevoId.value = null;
};

const cancelarMoverServicio = () => {
    moviendoServicioId.value = null;
    moviendoDestinoNuevoId.value = null;
};

const confirmarMoverServicio = async (dsId: number) => {
    if (!moviendoDestinoNuevoId.value || !destinoServiciosActivo.value) return;
    try {
        const res = await destinoAtractivoService.moverServicio(dsId, moviendoDestinoNuevoId.value);
        cancelarMoverServicio();
        (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        const listado = await destinoAtractivoService.listarServicios(destinoServiciosActivo.value.id);
        destinoServiciosLista.value = listado.destino_servicios;
    } catch (error: any) {
        const destinoServicioExistenteId = error.response?.data?.destino_servicio_existente_id;

        if (destinoServicioExistenteId) {
            const confirmacion = await (Swal as TVueSwalInstance).fire({
                title: 'Ya existe en ese destino',
                text: 'El destino elegido ya tiene este servicio con sus propios proveedores. ¿Fusionar los proveedores de este con los de ese, y eliminar esta asociación duplicada?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, fusionar',
                cancelButtonText: 'Cancelar',
            });

            if (confirmacion.isConfirmed) {
                try {
                    const res = await destinoAtractivoService.fusionarServicio(dsId, destinoServicioExistenteId);
                    cancelarMoverServicio();
                    (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
                    const listado = await destinoAtractivoService.listarServicios(destinoServiciosActivo.value.id);
                    destinoServiciosLista.value = listado.destino_servicios;
                } catch (fusionError: any) {
                    (Swal as TVueSwalInstance).fire('Error', fusionError.response?.data?.message ?? 'No se pudo fusionar', 'error');
                }
            }
            return;
        }

        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo mover', 'error');
    }
};

onMounted(async () => {
    await cargarArbol();
    const res = await servicioService.listar({ per_page: 200 });
    servicios.value = res.servicios;
});
</script>

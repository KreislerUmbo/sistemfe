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
                        <div class="mb-2">
                            <input type="text" class="form-control form-control-sm" v-model="servicioBusqueda"
                                placeholder="Buscar un servicio del catálogo para asociar...">
                            <div v-if="servicioBusqueda.trim().length >= 2" class="list-group mt-1" style="max-height:160px; overflow-y:auto;">
                                <div v-if="buscandoServicio" class="text-center py-2"><span class="spinner-border spinner-border-sm"></span></div>
                                <div v-else-if="servicioResultados.length === 0" class="list-group-item text-muted small fst-italic">Sin resultados.</div>
                                <template v-else>
                                    <button v-for="s in servicioResultados" :key="s.id" type="button"
                                        class="list-group-item list-group-item-action small py-1"
                                        :disabled="asociandoServicio" @click="asociarServicioExistente(s)">
                                        <i class="fas fa-plus me-1 text-success"></i>{{ s.nombre }}
                                    </button>
                                </template>
                            </div>
                        </div>
                        <!-- Alta rápida: el catálogo de servicios (Traslado, Hospedaje,
                             Entrada/Boleto...) no tiene pantalla propia en esta sesión —
                             se crea acá mismo, donde se necesita, mismo espíritu que
                             ClientFormQuick/ProductFormQuick del core. El selector de
                             "Tipo de proveedor" es opcional — de acá sale el
                             tipo_proveedor_id que alimenta el desglose por categoría
                             de paquetes/detalle.vue (antes no había forma de asignarlo
                             desde ningún lado, todo caía en "Sin categoría"). -->
                        <div class="input-group input-group-sm mb-3">
                            <input type="text" class="form-control" placeholder="¿No está en la lista? Escribe el nombre y créalo..." v-model="servicioNuevoNombre">
                            <select class="form-select" style="max-width:150px" v-model="tipoProveedorNuevoId" title="Tipo de proveedor (opcional)">
                                <option :value="null">Sin categoría</option>
                                <option v-for="t in proveedorTipos" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                            </select>
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
                                        <select class="form-select" style="max-width:150px" v-model="editandoTipoProveedorId" title="Tipo de proveedor (opcional)">
                                            <option :value="null">Sin categoría</option>
                                            <option v-for="t in proveedorTipos" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                                        </select>
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
                                    <span v-if="nombreTipoServicio(ds)" class="badge bg-light text-dark border ms-1" style="font-size:10px">{{ nombreTipoServicio(ds) }}</span>
                                    <span v-else class="badge bg-warning-subtle text-warning border ms-1" style="font-size:10px">Sin categoría</span>
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
import { ref, computed, onMounted, watch } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { destinoAtractivoService } from '@/services/admin/destinoAtractivoService';
import { servicioService } from '@/services/admin/servicioService';
import { proveedorTipoService } from '@/services/admin/proveedorService';
import type { DestinoAtractivo, DestinoServicio, Servicio, ProveedorTipo } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

type Fila = { id: number; nombre: string; tipo: 'zona' | 'lugar' | 'atractivo'; parentId: number | null; profundidad: number; tieneHijos: boolean };

const arbol = ref<DestinoAtractivo[]>([]);
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
const servicioNuevoNombre = ref<string>('');

// Búsqueda de servicios existentes para asociar (Sesión pagina/detalle.vue) —
// antes precargaba TODO el catálogo (per_page:200) en un <select>; con el
// catálogo creciendo eso se vuelve lento e inmanejable. Ahora busca en el
// backend con debounce, mismo patrón que DestinoServicioPicker.vue.
const servicioBusqueda = ref<string>('');
const servicioResultados = ref<Servicio[]>([]);
const buscandoServicio = ref<boolean>(false);
const asociandoServicio = ref<boolean>(false);
let servicioBusquedaTimeout: ReturnType<typeof setTimeout> | undefined;
const editandoServicioId = ref<number | null>(null);
const editandoServicioNombre = ref<string>('');
const moviendoServicioId = ref<number | null>(null);
const moviendoDestinoNuevoId = ref<number | null>(null);

// Tipo de proveedor (Hotel/Transporte/Mayorista/...) — catálogo central,
// opcional. Antes no había forma de asignarlo a un servicio desde ningún
// lado del frontend, así que el desglose por categoría de
// paquetes/detalle.vue caía siempre en "Sin categoría". Se agrega acá,
// donde ya se crean/editan los servicios.
const proveedorTipos = ref<ProveedorTipo[]>([]);
const tipoProveedorNuevoId = ref<number | null>(null);
const editandoTipoProveedorId = ref<number | null>(null);

const nombreTipoServicio = (ds: DestinoServicio): string | null => {
    const tipoId = ds.servicio?.tipo_proveedor_id;
    if (!tipoId) return null;
    return proveedorTipos.value.find((t) => t.id === tipoId)?.nombre ?? null;
};

const abrirServicios = async (fila: Fila) => {
    destinoServiciosActivo.value = fila;
    servicioBusqueda.value = '';
    servicioResultados.value = [];
    servicioNuevoNombre.value = '';
    modalServiciosAbierto.value = true;
    const res = await destinoAtractivoService.listarServicios(fila.id);
    destinoServiciosLista.value = res.destino_servicios;
};

// Crea el servicio y lo asocia al destino en un solo paso — ya no hace
// falta el select+botón "asociar" que existía antes (búsqueda reemplaza
// la mitad de ese flujo, esto reemplaza la otra mitad).
const crearServicioRapido = async () => {
    if (!destinoServiciosActivo.value) return;
    try {
        const res = await servicioService.crear({
            nombre: servicioNuevoNombre.value.trim(),
            tipo_proveedor_id: tipoProveedorNuevoId.value,
        });
        await destinoAtractivoService.asociarServicio(destinoServiciosActivo.value.id, res.servicio.id);
        const listado = await destinoAtractivoService.listarServicios(destinoServiciosActivo.value.id);
        destinoServiciosLista.value = listado.destino_servicios;
        servicioNuevoNombre.value = '';
        tipoProveedorNuevoId.value = null;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo crear el servicio', 'error');
    }
};

// Ya asociados quedan fuera de los resultados — no tiene sentido ofrecer
// asociar de nuevo algo que ya está en la lista de abajo.
watch(servicioBusqueda, (q) => {
    clearTimeout(servicioBusquedaTimeout);
    servicioResultados.value = [];
    if (q.trim().length < 2) return;
    servicioBusquedaTimeout = setTimeout(async () => {
        buscandoServicio.value = true;
        try {
            const res = await servicioService.listar({ search: q.trim() });
            const idsAsociados = new Set(destinoServiciosLista.value.map((ds) => ds.servicio?.id));
            servicioResultados.value = (res.servicios ?? []).filter((s: Servicio) => !idsAsociados.has(s.id));
        } finally {
            buscandoServicio.value = false;
        }
    }, 300);
});

const asociarServicioExistente = async (servicio: Servicio) => {
    if (!destinoServiciosActivo.value || asociandoServicio.value) return;
    asociandoServicio.value = true;
    try {
        await destinoAtractivoService.asociarServicio(destinoServiciosActivo.value.id, servicio.id);
        const res = await destinoAtractivoService.listarServicios(destinoServiciosActivo.value.id);
        destinoServiciosLista.value = res.destino_servicios;
        servicioBusqueda.value = '';
        servicioResultados.value = [];
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo asociar', 'error');
    } finally {
        asociandoServicio.value = false;
    }
};

const iniciarEdicionServicio = (ds: DestinoServicio) => {
    if (!ds.servicio) return;
    editandoServicioId.value = ds.servicio.id;
    editandoServicioNombre.value = ds.servicio.nombre;
    editandoTipoProveedorId.value = ds.servicio.tipo_proveedor_id ?? null;
};

const cancelarEdicionServicio = () => {
    editandoServicioId.value = null;
    editandoServicioNombre.value = '';
    editandoTipoProveedorId.value = null;
};

// Renombra la fila real del catálogo compartido de servicios (no una
// copia local) — afecta a todos los destinos/proveedores que ya lo usan.
// Lo mismo para el tipo de proveedor: se guarda en el servicio, no en
// esta asociación puntual con el destino.
const guardarNombreServicio = async () => {
    if (!editandoServicioId.value || !editandoServicioNombre.value.trim()) return;
    try {
        await servicioService.actualizar(editandoServicioId.value, {
            nombre: editandoServicioNombre.value.trim(),
            tipo_proveedor_id: editandoTipoProveedorId.value,
        });
        cancelarEdicionServicio();
        if (destinoServiciosActivo.value) {
            const res = await destinoAtractivoService.listarServicios(destinoServiciosActivo.value.id);
            destinoServiciosLista.value = res.destino_servicios;
        }
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
    // 2 cargas independientes entre sí (árbol de destinos, catálogo de tipos
    // de proveedor) — en paralelo, mismo criterio que paquetes/detalle.vue.
    // El catálogo de servicios ya NO se precarga entero acá — con el
    // catálogo creciendo eso era cada vez más lento; ahora se busca bajo
    // demanda dentro del modal (ver watch(servicioBusqueda, ...)).
    await Promise.all([
        cargarArbol(),
        proveedorTipoService.listar().then((res) => { proveedorTipos.value = res.proveedor_tipos; }),
    ]);
});
</script>

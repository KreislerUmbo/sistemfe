<template>
    <!-- T-059 (CRM C4): orígenes de contacto (por dónde llegó la consulta). Recurso propio
         (crm_origenes_contacto, catálogo de core), cada cambio se guarda al momento. Nunca se borra
         un origen: se desactiva. Copia de MotivosResultadoSeccion.vue con una sola lista. -->
    <div class="accordion mb-4" id="accOrigenes">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accOrigenesBody">
                    <span class="badge bg-primary rounded-pill me-2">15</span>
                    <span class="fw-semibold text-dark">Orígenes de contacto</span>
                </button>
            </h2>
            <div id="accOrigenesBody" class="accordion-collapse collapse" data-bs-parent="#accOrigenes">
                <div class="accordion-body">
                    <p class="small text-muted">
                        Por dónde llegó el cliente: se elige (opcional) en «Nueva consulta» y «Nueva cotización».
                        Un origen que ya no uses se desactiva: las cotizaciones y clientes que lo tienen lo siguen mostrando.
                    </p>

                    <div v-if="cargando" class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                    </div>
                    <div v-else class="row">
                        <div class="col-md-6">
                            <div class="card mb-0">
                                <div class="card-header py-2">
                                    <h6 class="mb-0 small fw-semibold"><i class="fas fa-bullhorn text-primary me-1"></i>Orígenes</h6>
                                </div>
                                <ul class="list-group list-group-flush">
                                    <li v-for="(o, i) in origenes" :key="o.id" class="list-group-item py-1 px-2 d-flex align-items-center gap-2">
                                        <div class="d-flex flex-column">
                                            <button class="btn btn-link btn-sm p-0 lh-1" title="Subir" :disabled="ocupado || i === 0" @click="mover(i, -1)"><i class="fas fa-chevron-up" style="font-size:10px"></i></button>
                                            <button class="btn btn-link btn-sm p-0 lh-1" title="Bajar" :disabled="ocupado || i === origenes.length - 1" @click="mover(i, 1)"><i class="fas fa-chevron-down" style="font-size:10px"></i></button>
                                        </div>
                                        <input v-if="editandoId === o.id" v-model="nombreEditado" type="text" maxlength="80" class="form-control form-control-sm"
                                            @keyup.enter="guardarNombre(o)" @keyup.esc="editandoId = null">
                                        <span v-else class="small flex-grow-1" :class="{ 'text-muted text-decoration-line-through': !o.activo }">{{ o.nombre }}</span>
                                        <template v-if="editandoId === o.id">
                                            <button class="btn btn-sm btn-primary py-0" :disabled="ocupado" @click="guardarNombre(o)"><i class="fas fa-check"></i></button>
                                            <button class="btn btn-sm btn-outline-secondary py-0" :disabled="ocupado" @click="editandoId = null"><i class="fas fa-times"></i></button>
                                        </template>
                                        <template v-else>
                                            <button class="btn btn-sm btn-link text-primary py-0 px-1" title="Renombrar" :disabled="ocupado" @click="editar(o)"><i class="fas fa-pen" style="font-size:11px"></i></button>
                                            <div class="form-check form-switch mb-0" :title="o.activo ? 'Desactivar' : 'Activar'">
                                                <input class="form-check-input" type="checkbox" :checked="o.activo" :disabled="ocupado" @change="cambiarActivo(o)">
                                            </div>
                                        </template>
                                    </li>
                                </ul>
                                <div class="card-body py-2">
                                    <div class="input-group input-group-sm">
                                        <input v-model="nuevo" type="text" maxlength="80" class="form-control" placeholder="Nuevo origen"
                                            @keyup.enter="agregar">
                                        <button class="btn btn-outline-primary" :disabled="ocupado || !nuevo.trim()" @click="agregar">
                                            <i class="fas fa-plus me-1"></i>Agregar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { crmOrigenContactoService, type OrigenContacto } from '@/services/admin/crmOrigenContactoService';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const origenes = ref<OrigenContacto[]>([]);
const cargando = ref(true);
const ocupado = ref(false);
const editandoId = ref<number | null>(null);
const nombreEditado = ref('');
const nuevo = ref('');

const cargar = async () => {
    origenes.value = (await crmOrigenContactoService.listar()).origenes;
};

onMounted(async () => {
    try {
        await cargar();
    } finally {
        cargando.value = false;
    }
});

// Cada cambio se guarda y se vuelve a leer la lista (el backend ubica el nuevo al final).
const ejecutar = async (accion: () => Promise<unknown>, error: string) => {
    ocupado.value = true;
    try {
        await accion();
        await cargar();
        return true;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? error, 'error');
        return false;
    } finally {
        ocupado.value = false;
    }
};

const agregar = async () => {
    const nombre = nuevo.value.trim();
    if (!nombre) return;
    if (await ejecutar(() => crmOrigenContactoService.crear({ nombre }), 'No se pudo agregar el origen')) {
        nuevo.value = '';
    }
};

const editar = (o: OrigenContacto) => {
    editandoId.value = o.id;
    nombreEditado.value = o.nombre;
};

const guardarNombre = async (o: OrigenContacto) => {
    const nombre = nombreEditado.value.trim();
    if (!nombre || nombre === o.nombre) {
        editandoId.value = null;
        return;
    }
    if (await ejecutar(() => crmOrigenContactoService.actualizar(o.id, { nombre }), 'No se pudo renombrar el origen')) {
        editandoId.value = null;
    }
};

const cambiarActivo = (o: OrigenContacto) =>
    ejecutar(() => crmOrigenContactoService.actualizar(o.id, { activo: !o.activo }), 'No se pudo cambiar el origen');

// Subir/bajar: se renumera la lista con el par intercambiado (1, 2, 3…), así dos orígenes con el
// mismo orden no quedan trabados.
const mover = (indice: number, paso: -1 | 1) => {
    const lista = [...origenes.value];
    [lista[indice], lista[indice + paso]] = [lista[indice + paso], lista[indice]];
    const cambios = lista
        .map((o, i) => ({ o, orden: i + 1 }))
        .filter(({ o, orden }) => o.orden !== orden);

    return ejecutar(
        () => Promise.all(cambios.map(({ o, orden }) => crmOrigenContactoService.actualizar(o.id, { orden }))),
        'No se pudo cambiar el orden'
    );
};
</script>

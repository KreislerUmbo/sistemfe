<template>
    <!-- T-052 (CRM C3): motivos de venta ganada / perdida. Recurso propio (crm_motivos_resultado),
         cada cambio se guarda al momento. Nunca se borra un motivo: se desactiva. «Otro» solo se ordena. -->
    <div class="accordion mb-4" id="accMotivos">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accMotivosBody">
                    <span class="badge bg-primary rounded-pill me-2">14</span>
                    <span class="fw-semibold text-dark">Motivos de venta ganada / perdida</span>
                </button>
            </h2>
            <div id="accMotivosBody" class="accordion-collapse collapse" data-bs-parent="#accMotivos">
                <div class="accordion-body">
                    <p class="small text-muted">
                        Se piden al cerrar una cotización como perdida (obligatorio) y al aceptarla (opcional).
                        Un motivo que ya no uses se desactiva: las cotizaciones que lo tienen lo siguen mostrando.
                    </p>

                    <div v-if="cargando" class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                    </div>
                    <div v-else class="row g-3">
                        <div v-for="tipo in TIPOS" :key="tipo.valor" class="col-md-6">
                            <div class="card mb-0">
                                <div class="card-header py-2">
                                    <h6 class="mb-0 small fw-semibold"><i class="fas me-1" :class="tipo.icono"></i>{{ tipo.titulo }}</h6>
                                </div>
                                <ul class="list-group list-group-flush">
                                    <li v-for="(m, i) in porTipo(tipo.valor)" :key="m.id" class="list-group-item py-1 px-2 d-flex align-items-center gap-2">
                                        <div class="d-flex flex-column">
                                            <button class="btn btn-link btn-sm p-0 lh-1" title="Subir" :disabled="ocupado || i === 0" @click="mover(tipo.valor, i, -1)"><i class="fas fa-chevron-up" style="font-size:10px"></i></button>
                                            <button class="btn btn-link btn-sm p-0 lh-1" title="Bajar" :disabled="ocupado || i === porTipo(tipo.valor).length - 1" @click="mover(tipo.valor, i, 1)"><i class="fas fa-chevron-down" style="font-size:10px"></i></button>
                                        </div>
                                        <input v-if="editandoId === m.id" v-model="nombreEditado" type="text" maxlength="80" class="form-control form-control-sm"
                                            @keyup.enter="guardarNombre(m)" @keyup.esc="editandoId = null">
                                        <span v-else class="small flex-grow-1" :class="{ 'text-muted text-decoration-line-through': !m.activo }">{{ m.nombre }}</span>
                                        <template v-if="editandoId === m.id">
                                            <button class="btn btn-sm btn-primary py-0" :disabled="ocupado" @click="guardarNombre(m)"><i class="fas fa-check"></i></button>
                                            <button class="btn btn-sm btn-outline-secondary py-0" :disabled="ocupado" @click="editandoId = null"><i class="fas fa-times"></i></button>
                                        </template>
                                        <template v-else-if="!m.es_otro">
                                            <button class="btn btn-sm btn-link text-primary py-0 px-1" title="Renombrar" :disabled="ocupado" @click="editar(m)"><i class="fas fa-pen" style="font-size:11px"></i></button>
                                            <div class="form-check form-switch mb-0" :title="m.activo ? 'Desactivar' : 'Activar'">
                                                <input class="form-check-input" type="checkbox" :checked="m.activo" :disabled="ocupado" @change="cambiarActivo(m)">
                                            </div>
                                        </template>
                                        <small v-else class="text-muted" title="«Otro» no se renombra ni se desactiva">fijo</small>
                                    </li>
                                </ul>
                                <div class="card-body py-2">
                                    <div class="input-group input-group-sm">
                                        <input v-model="nuevos[tipo.valor]" type="text" maxlength="80" class="form-control" placeholder="Nuevo motivo"
                                            @keyup.enter="agregar(tipo.valor)">
                                        <button class="btn btn-outline-primary" :disabled="ocupado || !nuevos[tipo.valor].trim()" @click="agregar(tipo.valor)">
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
import { onMounted, reactive, ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { crmMotivoResultadoService, type MotivoResultado, type TipoMotivoResultado } from '@/services/admin/crmMotivoResultadoService';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const TIPOS: { valor: TipoMotivoResultado; titulo: string; icono: string }[] = [
    { valor: 'perdida', titulo: 'Por qué se pierde', icono: 'fa-circle-xmark text-danger' },
    { valor: 'ganada', titulo: 'Por qué se gana', icono: 'fa-handshake text-success' },
];

const motivos = ref<MotivoResultado[]>([]);
const cargando = ref(true);
const ocupado = ref(false);
const editandoId = ref<number | null>(null);
const nombreEditado = ref('');
const nuevos = reactive<Record<TipoMotivoResultado, string>>({ perdida: '', ganada: '' });

const porTipo = (tipo: TipoMotivoResultado) => motivos.value.filter((m) => m.tipo === tipo);

const cargar = async () => {
    motivos.value = await crmMotivoResultadoService.listar();
};

onMounted(async () => {
    try {
        await cargar();
    } finally {
        cargando.value = false;
    }
});

// Cada cambio se guarda y se vuelve a leer la lista (el backend ubica el nuevo antes de «Otro»).
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

const agregar = async (tipo: TipoMotivoResultado) => {
    const nombre = nuevos[tipo].trim();
    if (!nombre) return;
    if (await ejecutar(() => crmMotivoResultadoService.crear({ tipo, nombre }), 'No se pudo agregar el motivo')) {
        nuevos[tipo] = '';
    }
};

const editar = (m: MotivoResultado) => {
    editandoId.value = m.id;
    nombreEditado.value = m.nombre;
};

const guardarNombre = async (m: MotivoResultado) => {
    const nombre = nombreEditado.value.trim();
    if (!nombre || nombre === m.nombre) {
        editandoId.value = null;
        return;
    }
    if (await ejecutar(() => crmMotivoResultadoService.actualizar(m.id, { nombre }), 'No se pudo renombrar el motivo')) {
        editandoId.value = null;
    }
};

const cambiarActivo = (m: MotivoResultado) =>
    ejecutar(() => crmMotivoResultadoService.actualizar(m.id, { activo: !m.activo }), 'No se pudo cambiar el motivo');

// Subir/bajar: se renumera la lista del tipo con el par intercambiado (1, 2, 3…), así dos motivos
// con el mismo orden no quedan trabados.
const mover = (tipo: TipoMotivoResultado, indice: number, paso: -1 | 1) => {
    const lista = [...porTipo(tipo)];
    [lista[indice], lista[indice + paso]] = [lista[indice + paso], lista[indice]];
    const cambios = lista
        .map((m, i) => ({ m, orden: i + 1 }))
        .filter(({ m, orden }) => m.orden !== orden);

    return ejecutar(
        () => Promise.all(cambios.map(({ m, orden }) => crmMotivoResultadoService.actualizar(m.id, { orden }))),
        'No se pudo cambiar el orden'
    );
};
</script>

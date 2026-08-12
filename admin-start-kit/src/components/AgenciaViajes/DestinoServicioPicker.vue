<template>
    <div class="destino-servicio-picker">
        <template v-if="!destinoSeleccionado">
            <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
            <input type="text" class="form-control form-control-sm" v-model="queryDestino"
                placeholder="Buscá un destino (zona, lugar o atractivo)...">
            <div v-if="queryDestino.trim().length >= 2" class="border rounded mt-1 picker-lista">
                <div v-if="destinosFiltrados.length === 0" class="text-muted small text-center py-2">Sin resultados.</div>
                <div v-for="d in destinosFiltrados" :key="d.id" class="picker-item small px-2 py-1"
                    style="cursor:pointer" @click="elegirDestino(d)">
                    {{ d.rutaTexto }}
                </div>
            </div>
        </template>

        <template v-else>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small fw-semibold">
                    <i class="fas fa-map-marker-alt me-1 text-primary"></i>{{ destinoSeleccionado.rutaTexto }}
                </span>
                <a href="#" class="small" @click.prevent="limpiarDestino">Cambiar</a>
            </div>

            <div v-if="cargandoServicios" class="text-center py-2">
                <span class="spinner-border spinner-border-sm"></span>
            </div>
            <template v-else>
                <div v-if="destinoServicios.length" class="mb-2">
                    <span class="small text-secondary d-block mb-1">Servicios ya asociados a este destino</span>
                    <div v-for="ds in destinoServicios" :key="ds.id" class="picker-item small border rounded px-2 py-1 mb-1"
                        style="cursor:pointer" @click="elegirDestinoServicio(ds)">
                        <i class="fas fa-concierge-bell me-1 text-primary"></i>{{ ds.servicio?.nombre }}
                    </div>
                </div>

                <label class="form-label mb-1 small fw-semibold text-secondary">Buscar otro servicio en el catálogo</label>
                <input type="text" class="form-control form-control-sm" v-model="queryServicio"
                    placeholder="Nombre del servicio...">

                <div v-if="queryServicio.trim().length >= 2" class="mt-1">
                    <div v-if="buscandoServicio" class="text-center py-2">
                        <span class="spinner-border spinner-border-sm"></span>
                    </div>
                    <template v-else>
                        <div v-if="resultadosServicio.length" class="border rounded picker-lista">
                            <div v-for="s in resultadosServicio" :key="s.id" class="picker-item small px-2 py-1"
                                style="cursor:pointer" @click="asociarYElegir(s)">
                                <i class="fas fa-plus me-1 text-success"></i>Asociar "{{ s.nombre }}" a este destino
                            </div>
                        </div>
                        <div v-else class="text-muted small mt-1">
                            Ese servicio no existe en tu catálogo —
                            <router-link to="/agencia-viajes/destinos" target="_blank">creálo primero en Servicios</router-link>.
                        </div>
                    </template>
                </div>
            </template>
        </template>
    </div>
</template>

<script setup lang="ts">
// Sesión 11q — elegir un destino_servicio_id existente, o crearlo
// combinando un destino_atractivo + un servicio ya existentes en el
// catálogo. Usado por PromoverProveedorModal.vue. Deliberadamente NO crea
// servicios nuevos (eso es su propio CRUD, fuera de alcance acá).
import { ref, computed, watch } from 'vue';
import { destinoAtractivoService } from '@/services/admin/destinoAtractivoService';
import { servicioService } from '@/services/admin/servicioService';
import type { DestinoAtractivo, DestinoServicio, Servicio } from '@/types/agencia-viajes';

const emit = defineEmits<{ (e: 'seleccionado', destinoServicioId: number): void }>();

type DestinoPlano = { id: number; nombre: string; rutaTexto: string };

const arbol = ref<DestinoAtractivo[]>([]);
const queryDestino = ref('');
const destinoSeleccionado = ref<DestinoPlano | null>(null);
const destinoServicios = ref<DestinoServicio[]>([]);
const cargandoServicios = ref(false);
const queryServicio = ref('');
const resultadosServicio = ref<Servicio[]>([]);
const buscandoServicio = ref(false);

// Aplana el árbol (zona → lugar → atractivo) UNA sola vez al montar — los
// árboles de destino de una agencia son chicos, no hace falta paginar ni
// buscar por texto en el backend (destinos-atractivos no tiene ese filtro).
const destinosPlanos = computed<DestinoPlano[]>(() => {
    const resultado: DestinoPlano[] = [];
    const recorrer = (nodos: DestinoAtractivo[], ruta: string[]) => {
        nodos.forEach((n) => {
            const rutaActual = [...ruta, n.nombre];
            resultado.push({ id: n.id, nombre: n.nombre, rutaTexto: rutaActual.join(' › ') });
            if (n.hijos?.length) recorrer(n.hijos, rutaActual);
        });
    };
    recorrer(arbol.value, []);
    return resultado;
});

const destinosFiltrados = computed(() => {
    const q = queryDestino.value.trim().toLowerCase();
    if (q.length < 2) return [];
    return destinosPlanos.value.filter((d) => d.nombre.toLowerCase().includes(q)).slice(0, 30);
});

(async () => {
    const res = await destinoAtractivoService.arbol();
    arbol.value = res.destinos_atractivos;
})();

const elegirDestino = async (d: DestinoPlano) => {
    destinoSeleccionado.value = d;
    queryDestino.value = '';
    cargandoServicios.value = true;
    try {
        const res = await destinoAtractivoService.listarServicios(d.id);
        destinoServicios.value = res.destino_servicios;
    } finally {
        cargandoServicios.value = false;
    }
};

const limpiarDestino = () => {
    destinoSeleccionado.value = null;
    destinoServicios.value = [];
    queryServicio.value = '';
    resultadosServicio.value = [];
};

const elegirDestinoServicio = (ds: DestinoServicio) => emit('seleccionado', ds.id);

let timeoutBusqueda: ReturnType<typeof setTimeout> | undefined;
watch(queryServicio, (q) => {
    clearTimeout(timeoutBusqueda);
    resultadosServicio.value = [];
    if (q.trim().length < 2) return;
    timeoutBusqueda = setTimeout(async () => {
        buscandoServicio.value = true;
        try {
            const res = await servicioService.listar({ search: q.trim() });
            resultadosServicio.value = res.servicios ?? [];
        } finally {
            buscandoServicio.value = false;
        }
    }, 350);
});

const asociarYElegir = async (servicio: Servicio) => {
    if (!destinoSeleccionado.value) return;
    const res = await destinoAtractivoService.asociarServicio(destinoSeleccionado.value.id, servicio.id);
    emit('seleccionado', res.destino_servicio.id);
};
</script>

<style scoped>
.picker-lista { max-height: 220px; overflow-y: auto; }
.picker-item:hover { background: var(--bs-light); }
</style>

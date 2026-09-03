<template>
    <div class="card border p-2 small">
        <select class="form-select form-select-sm mb-1" v-model="form.proveedor_id">
            <option :value="null">— Proveedor mayorista —</option>
            <option v-for="p in proveedoresMayoristas" :key="p.id" :value="p.id">{{ p.nombre_comercial ?? p.razon_social }}</option>
        </select>
        <select class="form-select form-select-sm mb-1" v-model="form.moneda">
            <option value="USD">USD</option>
            <option value="PEN">PEN</option>
        </select>
        <input type="text" class="form-control form-control-sm mb-1" placeholder="Vuelo (aerolínea)" v-model="form.vuelo_aerolinea">
        <!-- Fix C1 (02-sep-2026) — lo único que resolverNombreItemPdf() puede
             imprimir en el PDF comercial para esta opción; el nombre del
             mayorista/proveedor nunca llega a ese documento. -->
        <input type="text" class="form-control form-control-sm mb-1" placeholder="Descripción para el cliente (ej. Paquete Panamá 6D/5N)"
            v-model="form.descripcion_publica">

        <!-- Sesión 12e — buscador de contenido reutilizable, "buscar antes de
             crear" para no ensuciar la biblioteca con duplicados (§23.1.9). -->
        <div class="position-relative mb-1">
            <input type="text" class="form-control form-control-sm" placeholder="Buscar contenido reutilizable (ej. 'City Tour Panamá')..."
                v-model="contenidoTourSearch" @input="onContenidoTourSearchInput">
            <div v-if="contenidoTourResultados.length" class="list-group position-absolute w-100" style="z-index: 10;">
                <button v-for="c in contenidoTourResultados" :key="c.id" type="button"
                    class="list-group-item list-group-item-action py-1 small" @click="seleccionarContenidoTour(c)">
                    {{ c.nombre }}
                </button>
            </div>
            <div v-if="contenidoTourSeleccionado" class="small text-success mt-1">
                <i class="fas fa-check-circle me-1"></i>Vinculado a "{{ contenidoTourSeleccionado.nombre }}"
            </div>
            <button v-else-if="contenidoTourSearch.trim() && !contenidoTourResultados.length"
                type="button" class="btn btn-link btn-sm p-0 mt-1" @click="crearContenidoTourDesdeTexto" :disabled="creandoContenidoTour">
                <span v-if="creandoContenidoTour" class="spinner-border spinner-border-sm me-1"></span>
                + Guardar "{{ contenidoTourSearch }}" como contenido reutilizable
            </button>
        </div>

        <textarea class="form-control form-control-sm mb-1" rows="2" placeholder="Incluye..." v-model="form.incluye"></textarea>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm w-100" @click="guardar" :disabled="guardando">
                <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>{{ opcionExistente ? 'Guardar' : 'Agregar' }}
            </button>
            <button class="btn btn-outline-secondary btn-sm" @click="$emit('cancelar')"><i class="fas fa-times"></i></button>
        </div>
    </div>
</template>

<script setup lang="ts">
// Formulario de alta/edición de una OpcionMayorista — extraído de
// editar.vue (ronda "editar inline en el card") para poder usarse dos
// veces (inline dentro de la card al editar, al final de la lista al
// crear) sin duplicar el markup del buscador de contenido reutilizable.
// Mismo molde que PasajeAereoForm.vue/ItemManualForm.vue: opcionExistente
// nullable + watch inmediato para poblar/limpiar, emit agregado/actualizado.
import { ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { opcionMayoristaService } from '@/services/admin/opcionMayoristaService';
import { contenidoTourService } from '@/services/admin/contenidoTourService';
import type { OpcionMayorista, Proveedor, ContenidoTour } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{
    alternativaId: number;
    destinoActivoId: number | null;
    opcionExistente?: OpcionMayorista | null;
    proveedoresMayoristas: Proveedor[];
}>();
const emit = defineEmits<{
    (e: 'agregado', payload: OpcionMayorista): void;
    (e: 'actualizado', payload: OpcionMayorista): void;
    (e: 'cancelar'): void;
}>();

const form = ref({
    proveedor_id: null as number | null, moneda: 'USD' as 'PEN' | 'USD', vuelo_aerolinea: '', incluye: '',
    contenido_tour_id: null as number | null, descripcion_publica: '',
});

// Sesión 12e — buscador de contenido_tour ("buscar antes de crear",
// mitiga duplicados en la biblioteca — auditoria-arquitectonica-agencia-
// viajes.md §23.1.9). Mismo patrón de debounce que bibliotecaSearch de
// editar.vue. Sin filtro de destino — 12f (multi-destino) no llegó a
// extender este buscador puntual, sigue pendiente si hace falta.
const contenidoTourSearch = ref('');
const contenidoTourResultados = ref<ContenidoTour[]>([]);
const contenidoTourSeleccionado = ref<ContenidoTour | null>(null);
let contenidoTourTimeout: any = null;

const resetContenidoTourBuscador = () => {
    contenidoTourSearch.value = '';
    contenidoTourResultados.value = [];
    contenidoTourSeleccionado.value = null;
};

const buscarContenidoTour = async () => {
    if (!contenidoTourSearch.value.trim()) {
        contenidoTourResultados.value = [];
        return;
    }
    contenidoTourResultados.value = await contenidoTourService.buscar({ categoria: 'incluido', q: contenidoTourSearch.value });
};

const onContenidoTourSearchInput = () => {
    contenidoTourSeleccionado.value = null;
    form.value.contenido_tour_id = null;
    clearTimeout(contenidoTourTimeout);
    contenidoTourTimeout = setTimeout(buscarContenidoTour, 300);
};

const seleccionarContenidoTour = (contenido: ContenidoTour) => {
    contenidoTourSeleccionado.value = contenido;
    form.value.contenido_tour_id = contenido.id;
    contenidoTourResultados.value = [];
    contenidoTourSearch.value = contenido.nombre;
    // No pisar texto que el vendedor ya escribió a mano.
    if (!form.value.incluye.trim()) {
        form.value.incluye = contenido.incluye ?? contenido.descripcion ?? '';
    }
};

const creandoContenidoTour = ref(false);
const crearContenidoTourDesdeTexto = async () => {
    if (!contenidoTourSearch.value.trim()) return;
    creandoContenidoTour.value = true;
    try {
        const contenido = await contenidoTourService.crear({
            nombre: contenidoTourSearch.value.trim(),
            categoria: 'incluido',
            incluye: form.value.incluye || undefined,
        });
        seleccionarContenidoTour(contenido);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar el contenido', 'error');
    } finally {
        creandoContenidoTour.value = false;
    }
};

const resetearCampos = () => {
    const op = props.opcionExistente;
    if (op) {
        form.value = {
            proveedor_id: op.proveedor_id, moneda: op.moneda, vuelo_aerolinea: op.vuelo_aerolinea ?? '',
            incluye: op.incluye ?? '', contenido_tour_id: null, descripcion_publica: op.descripcion_publica ?? '',
        };
    } else {
        form.value = { proveedor_id: null, moneda: 'USD', vuelo_aerolinea: '', incluye: '', contenido_tour_id: null, descripcion_publica: '' };
    }
    resetContenidoTourBuscador();
};
watch(() => props.opcionExistente, resetearCampos, { immediate: true });

const guardando = ref(false);
const guardar = async () => {
    if (!form.value.proveedor_id) return;
    guardando.value = true;
    try {
        if (props.opcionExistente) {
            const res = await opcionMayoristaService.actualizar(props.opcionExistente.id, { ...form.value, proveedor_id: form.value.proveedor_id } as any);
            emit('actualizado', res.opcion_mayorista);
        } else {
            const res = await opcionMayoristaService.crear(props.alternativaId, { ...form.value, alternativa_destino_id: props.destinoActivoId } as any);
            emit('agregado', res.opcion_mayorista);
        }
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};
</script>

<template>
    <!-- T-022: "¿lista para el cliente?". Todo bien = solo un ✓ (silencio); con pendientes, una
         insignia "N pendientes" que despliega una línea por tipo. Sin modal. Los mensajes van
         como texto (nada de v-html). -->
    <div v-if="cargado" class="small">
        <div v-if="revision.length === 0" class="text-success">
            <i class="fas fa-check-circle me-1"></i>Lista para el cliente
        </div>
        <template v-else>
            <button type="button" class="btn btn-sm w-100 text-start" :class="bloquea ? 'btn-outline-danger' : 'btn-outline-warning'"
                @click="abierta = !abierta">
                <i class="fas fa-exclamation-triangle me-1"></i>{{ revision.length }} pendiente{{ revision.length === 1 ? '' : 's' }}
                <i class="fas float-end mt-1" :class="abierta ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
            </button>
            <ul v-if="abierta" class="list-group list-group-flush border rounded mt-1">
                <li v-for="entrada in revision" :key="entrada.codigo" class="list-group-item py-1 px-2">
                    <a href="#" class="text-decoration-none" :class="entrada.nivel === 'bloquea' ? 'text-danger' : 'text-body'"
                        @click.prevent="ir(entrada)">
                        <i class="fas me-1" :class="entrada.nivel === 'bloquea' ? 'fa-times-circle' : 'fa-exclamation-circle text-warning'"></i>{{ entrada.mensaje }}
                        <span v-if="entrada.destino.item_ids.length > 1" class="text-muted"> — siguiente</span>
                    </a>
                    <div v-for="d in preciosNuevos(entrada)" :key="d.item_id"
                        class="d-flex justify-content-between align-items-center gap-2 ps-3 pt-1 text-muted">
                        <span>{{ nombreDeItem(d.item_id) }}: {{ dinero(d.precio_actual) }} → {{ dinero(d.precio_nuevo) }}</span>
                        <button v-if="puedeEditar" type="button" class="btn btn-outline-primary btn-sm py-0 text-nowrap"
                            :disabled="actualizando !== null" @click="actualizar(d)">
                            <span v-if="actualizando === d.item_id" class="spinner-border spinner-border-sm me-1"></span>Actualizar
                        </button>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import {
    hayBloqueo, revisionAlternativaService,
    type DetallePrecioNuevo, type RevisionEntrada,
} from '@/services/admin/revisionAlternativaService';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{
    alternativaId: number;
    moneda: 'PEN' | 'USD';
    /** Cambia cada vez que la alternativa cambia (el padre lo conecta a lo que refresca el total). */
    refrescar: unknown;
    nombreDeItem: (itemId: number) => string;
}>();

const emit = defineEmits<{
    (e: 'cambio', revision: RevisionEntrada[]): void;
    /** Clic en una línea: el padre navega. `itemId` = el servicio al que ir (null si no hay uno). */
    (e: 'ir', entrada: RevisionEntrada, itemId: number | null): void;
    /** Un servicio ya se re-enganchó al precio nuevo: el padre recarga la cotización. */
    (e: 'actualizada'): void;
}>();

const auth = useAuthStore();
const toast = useToast();
const puedeEditar = computed(() => auth.isPermitedRoute('cotizaciones.editar'));

const revision = ref<RevisionEntrada[]>([]);
const cargado = ref(false);
const abierta = ref(false);
const actualizando = ref<number | null>(null);
const bloquea = computed(() => hayBloqueo(revision.value));

// Qué ítem sigue al hacer clic de nuevo en la misma línea ("siguiente").
const posicion: Record<string, number> = {};

const dinero = (monto?: number): string =>
    `${props.moneda === 'USD' ? 'US$' : 'S/'} ${(monto ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const preciosNuevos = (entrada: RevisionEntrada): DetallePrecioNuevo[] =>
    entrada.codigo === 'tarifa_precio_nuevo' ? (entrada.detalle as DetallePrecioNuevo[]) : [];

// Una sola respuesta vale: si cambian dos cosas seguidas, la más vieja se descarta.
let ultimaPeticion = 0;
let temporizador: ReturnType<typeof setTimeout> | null = null;

const cargar = async () => {
    const peticion = ++ultimaPeticion;
    try {
        const resultado = await revisionAlternativaService.obtener(props.alternativaId);
        if (peticion !== ultimaPeticion) return;
        revision.value = resultado.revision;
        cargado.value = true;
        emit('cambio', resultado.revision);
    } catch {
        // Sin revisión no se frena nada: el backend vuelve a revisar al enviar.
        if (peticion === ultimaPeticion) {
            revision.value = [];
            cargado.value = false;
            emit('cambio', []);
        }
    }
};

// Se recalcula tras cualquier cambio, pero no en cada tecla: espera a que se calme.
const programar = () => {
    if (temporizador) clearTimeout(temporizador);
    temporizador = setTimeout(cargar, 500);
};

watch(() => props.alternativaId, () => { cargado.value = false; abierta.value = false; cargar(); });
watch(() => props.refrescar, programar);
onMounted(cargar);
onBeforeUnmount(() => { if (temporizador) clearTimeout(temporizador); });

const ir = (entrada: RevisionEntrada) => {
    const ids = entrada.destino.item_ids;
    if (ids.length === 0) {
        emit('ir', entrada, null);
        return;
    }
    const siguiente = (posicion[entrada.codigo] ?? -1) + 1;
    posicion[entrada.codigo] = siguiente % ids.length;
    emit('ir', entrada, ids[siguiente % ids.length]);
};

const actualizar = async (d: DetallePrecioNuevo) => {
    const respuesta = await (Swal as TVueSwalInstance).fire({
        title: `Precio actual ${dinero(d.precio_actual)} → nuevo ${dinero(d.precio_nuevo)}`,
        text: '¿Actualizar?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Actualizar',
        cancelButtonText: 'Cancelar',
    });
    if (!respuesta.isConfirmed) return;

    actualizando.value = d.item_id;
    try {
        const resultado = await revisionAlternativaService.actualizarTarifa(d.item_id);
        toast.success(resultado.message);
        emit('actualizada');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('No se pudo actualizar', error?.response?.data?.message ?? 'Intenta de nuevo.', 'warning');
    } finally {
        actualizando.value = null;
    }
};
</script>

<template>
    <div class="card border-warning shadow-sm">
        <div class="card-header bg-warning-subtle border-bottom py-2">
            <span class="fw-semibold small"><i class="fas fa-cloud-rain me-1"></i>Reprogramar la salida completa</span>
        </div>
        <div class="card-body">
            <div class="row g-2 mb-2">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Nueva fecha</label>
                    <CampoFecha v-model="fechaNueva" />
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Motivo</label>
                    <input type="text" class="form-control form-control-sm" placeholder="Lluvia, derrumbe, cierre del atractivo…" v-model="motivo">
                </div>
            </div>

            <div class="small text-secondary mb-1">Por defecto todas las reservas pasan a la nueva fecha. Marca las que se cancelan:</div>
            <div class="d-flex flex-wrap gap-3 mb-2">
                <label v-for="r in reservas" :key="r.id" class="small d-flex align-items-center gap-1">
                    <input type="checkbox" class="form-check-input mt-0" :value="r.id" v-model="aCancelar">
                    Cancelar {{ r.codigo ?? ('#' + r.id) }} <span class="text-muted">({{ r.cliente ?? 'sin cliente' }})</span>
                </label>
            </div>
            <div v-if="aCancelar.length > 0" class="row g-2 mb-2">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Motivo de cancelación</label>
                    <select class="form-select form-select-sm" v-model="motivoCancelacion">
                        <option value="clima">Clima</option>
                        <option value="fuerza_mayor">Fuerza mayor</option>
                        <option value="voluntaria">Voluntaria</option>
                    </select>
                </div>
            </div>
            <div class="small text-muted mb-2">
                Una reserva que es solo de ese día se mueve completa. En un viaje de varios días solo se mueve este tour.
                Los proveedores, cantidades y vehículos pasan a la nueva fecha; los servicios quedan "Por reservar".
            </div>

            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="emit('cerrar')">Volver</button>
                <button type="button" class="btn btn-warning btn-sm" :disabled="guardando || !fechaNueva || !motivo.trim()" @click="confirmar">
                    <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>Reprogramar
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Fila 13, fase 2c (plan-tours-venta-simple-costo-real.md §3.13, D16/D17):
// mover la salida completa a otra fecha. El backend lo hace todo o nada.
import { computed, ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import CampoFecha from '@/components/CampoFecha.vue';
import { salidaOperativaService } from '@/services/admin/salidaOperativaService';
import { useToast } from '@/composables/useToast';
import type { SalidaOperativa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{ salida: SalidaOperativa }>();
const emit = defineEmits<{ (e: 'cerrar'): void; (e: 'reprogramada', salidaNuevaId: number | null): void }>();

const toast = useToast();
const fechaNueva = ref('');
const motivo = ref('');
const aCancelar = ref<number[]>([]);
const motivoCancelacion = ref('clima');
const guardando = ref(false);

const reservas = computed(() => props.salida.reservas ?? []);

// Escapa el texto de los avisos antes de ponerlo en el HTML del aviso final.
const escapar = (t: string) => t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));

const confirmar = async () => {
    const r = await (Swal as TVueSwalInstance).fire({
        title: '¿Reprogramar la salida?',
        text: aCancelar.value.length
            ? `Se cancelarán ${aCancelar.value.length} reserva(s) y el resto pasa a la nueva fecha.`
            : 'Todas las reservas pasan a la nueva fecha.',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, reprogramar', cancelButtonText: 'Volver',
    });
    if (!r.isConfirmed) return;

    guardando.value = true;
    try {
        const res = await salidaOperativaService.reprogramar(props.salida.id, {
            fecha_nueva: fechaNueva.value,
            motivo: motivo.value.trim(),
            cancelar_reserva_ids: aCancelar.value.length ? aCancelar.value : undefined,
            motivo_cancelacion: aCancelar.value.length ? motivoCancelacion.value : undefined,
        });
        await (Swal as TVueSwalInstance).fire({
            title: res.message,
            html: res.avisos.length
                ? '<div class="text-start small"><strong>Avisa a los proveedores:</strong><ul class="mt-1">'
                    + res.avisos.map((a) => `<li>${escapar(a)}</li>`).join('') + '</ul></div>'
                : '',
            icon: 'success',
        });
        emit('reprogramada', res.salida_nueva_id);
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudo reprogramar la salida');
    } finally {
        guardando.value = false;
    }
};
</script>
